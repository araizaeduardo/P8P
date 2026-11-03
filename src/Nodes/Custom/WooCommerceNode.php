<?php

namespace P8P\Nodes\Custom;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;
use Throwable;

class WooCommerceNode implements NodeInterface
{
    private const ORDER_STATUSES = ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'failed'];
    private const PRODUCT_STATUSES = ['draft', 'pending', 'private', 'publish'];
    private const MAX_BODY = 4194304;
    private const MAX_INPUT = 262144;
    private ?ClientInterface $client;
    private ?CredentialManager $credentials;

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null)
    {
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'woocommerce', 'name' => 'WooCommerce', 'category' => 'Community',
            'icon' => 'ShoppingCart', 'is_start' => false,
            'description' => 'Lista, obtiene, crea y actualiza productos simples, pedidos y clientes con REST API wc/v3.',
            'help_text' => 'Credencial generic_bearer: API Key contiene JSON con site_url HTTPS (subdirectorio permitido, sin /wp-json), consumer_key ck_… y consumer_secret cs_…. Claves REST de WooCommerce, no contraseña WordPress ni Application Password. Lecturas requieren clave Read; escrituras Read/Write y capacidades del usuario. Una página por llamada: page=1, per_page=10, máximo 100; data.items y data.pagination. data admite objeto/JSON/expresión, solo campos permitidos. Productos: crear simple/draft por defecto; publish debe estar explícito. Actualizar comprueba primero que el producto existente sea simple; no aplica defaults. Precios y dimensiones son cadenas decimales, no números JSON. Pedidos: crear exige line_items [{product_id,quantity}], estado pending por defecto, set_paid siempre false al crear y no configurable. No procesa pagos; estados processing/completed pueden afectar inventario y correos por reglas de la tienda. Actualizar pedidos solo status, customer_note, customer_id, billing, shipping; sin líneas, reembolsos, trash ni estados personalizados. Clientes: email obligatorio al crear, username opcional solo crear; requiere generación automática de contraseñas habilitada en WooCommerce y, si falta username, de nombres de usuario. Este nodo nunca recibe/genera/devuelve contraseñas. El sitio puede enviar correos al crear cuentas o pedidos. Actualizar exige al menos un campo; omisión conserva, texto vacío y listas [] se envían cuando se admiten. Sin meta_data, medios, variaciones, batch, borrado ni retries. Un fallo de transporte de escritura deja resultado incierto: comprueba la tienda antes de repetir. El motor resuelve expresiones una vez. Salidas filtradas sin order_key, claves ni datos de autenticación. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'label' => 'Credencial WooCommerce (JSON en API Key)', 'required' => true],
                'resource' => ['type' => 'select', 'label' => 'Recurso', 'required' => true, 'default' => 'products', 'options' => [
                    ['value' => 'products', 'label' => 'Productos'], ['value' => 'orders', 'label' => 'Pedidos'], ['value' => 'customers', 'label' => 'Clientes']
                ]],
                'operation' => ['type' => 'select', 'label' => 'Operación', 'required' => true, 'default' => 'list', 'options' => [
                    ['value' => 'list', 'label' => 'Listar (una página)'], ['value' => 'get', 'label' => 'Obtener por ID'],
                    ['value' => 'create', 'label' => 'Crear'], ['value' => 'update', 'label' => 'Actualizar parcialmente']
                ]],
                'resource_id' => ['type' => 'string', 'label' => 'ID (obtener/actualizar)', 'required' => false],
                'data' => ['type' => 'textarea', 'label' => 'Datos (objeto JSON; crear/actualizar)', 'required' => false,
                    'placeholder' => '{"name":"Camiseta","regular_price":"19.90"}'],
                'filters' => ['type' => 'textarea', 'label' => 'Filtros (objeto JSON; listar)', 'required' => false,
                    'placeholder' => '{"search":"camiseta","status":"draft"}'],
                'page' => ['type' => 'number', 'label' => 'Página', 'required' => false, 'default' => 1, 'min' => 1],
                'per_page' => ['type' => 'number', 'label' => 'Resultados por página (1–100)', 'required' => false, 'default' => 10, 'min' => 1, 'max' => 100]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepare($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine ya utiliza ExpressionEvaluator; no reinterpretar el contenido resuelto.
        $p = $this->prepare($parameters);
        $config = $this->credential($p['credential_id']);
        $base = $config['site_url'] . '/wp-json/wc/v3/' . $p['resource'];
        $url = $base . (isset($p['resource_id']) ? '/' . $p['resource_id'] : '');
        if ($p['resource'] === 'products' && $p['operation'] === 'update') {
            // No convertir ni modificar accidentalmente productos variables/externos/agrupados.
            [$preflight, $existing] = $this->request('GET', $url, $config, [], null);
            $this->resourceResult($existing, 'products', $p['resource_id']);
            if ($preflight->getStatusCode() !== 200 || ($existing->type ?? null) !== 'simple') {
                throw new InvalidArgumentException('WooCommerce: solo se pueden actualizar productos simples; no se realizó la escritura.');
            }
            if (array_key_exists('stock_quantity', $p['payload'])
                && ($p['payload']['manage_stock'] ?? $existing->manage_stock ?? false) !== true) {
                throw new InvalidArgumentException('WooCommerce: stock_quantity requiere manage_stock activo; no se realizó la escritura.');
            }
            if (isset($p['payload']['sale_price']) && $p['payload']['sale_price'] !== '') {
                $regular = $this->decimal($p['payload']['regular_price'] ?? $existing->regular_price ?? null, false);
                if ($this->decimalCompare($p['payload']['sale_price'], $regular) >= 0) {
                    throw new InvalidArgumentException('WooCommerce: sale_price debe ser menor que el precio regular efectivo; no se realizó la escritura.');
                }
            }
        }
        [$response, $body] = $this->request($p['method'], $url, $config, $p['query'], $p['payload']);
        $status = $response->getStatusCode();
        try {
            $expected = $p['operation'] === 'create' ? 201 : 200;
            if ($status !== $expected) {
                throw new InvalidArgumentException();
            }
            if ($p['operation'] === 'list') {
                if (!is_array($body) || count($body) > $p['query']['per_page']) {
                    throw new InvalidArgumentException();
                }
                $items = array_map(fn($item) => $this->resourceResult($item, $p['resource']), $body);
                $total = $this->headerInteger($response->getHeaderLine('X-WP-Total'));
                $pages = $this->headerInteger($response->getHeaderLine('X-WP-TotalPages'));
                if ($total !== null && $total < count($items)) {
                    throw new InvalidArgumentException();
                }
                $data = ['items' => $items, 'pagination' => [
                    'page' => $p['query']['page'], 'per_page' => $p['query']['per_page'], 'returned_count' => count($items),
                    'total' => $total, 'total_pages' => $pages,
                    'next_page' => $pages !== null && $p['query']['page'] < $pages ? $p['query']['page'] + 1 : null
                ]];
            } else {
                $data = $this->resourceResult($body, $p['resource'], $p['resource_id'] ?? null);
                if ($p['resource'] === 'products' && in_array($p['operation'], ['create', 'update'], true) && $data['type'] !== 'simple') {
                    throw new InvalidArgumentException();
                }
            }
            $this->checkSecrets($data, $config);
        } catch (Throwable $e) {
            throw new RuntimeException('WooCommerce: respuesta incompleta, incompatible o con datos sensibles.' . $this->uncertain($p['method']), $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        if (array_diff(array_keys($p), ['credential_id', 'resource', 'operation', 'resource_id', 'data', 'filters', 'page', 'per_page', '_sub_connections']) !== []) {
            throw new InvalidArgumentException('WooCommerce: parámetro no admitido; configura solo los campos del formulario, sin URLs, métodos, cabeceras ni secretos de credenciales.');
        }
        $credentialId = $this->text($p['credential_id'] ?? null, 255, false);
        $resource = $p['resource'] ?? 'products';
        $operation = $p['operation'] ?? 'list';
        if (!in_array($resource, ['products', 'orders', 'customers'], true)
            || !in_array($operation, ['list', 'get', 'create', 'update'], true)) {
            throw new InvalidArgumentException('WooCommerce: recurso u operación no admitidos.');
        }
        $result = ['credential_id' => $credentialId, 'resource' => $resource, 'operation' => $operation,
            'method' => match ($operation) { 'create' => 'POST', 'update' => 'PUT', default => 'GET' },
            'query' => [], 'payload' => null];
        if (in_array($operation, ['get', 'update'], true)) {
            $result['resource_id'] = $this->integer($p['resource_id'] ?? null, 1);
        }
        if ($operation === 'list') {
            $filters = array_key_exists('filters', $p) && $p['filters'] !== '' ? $this->object($p['filters']) : [];
            $result['query'] = $this->filters($filters, $resource) + [
                'page' => $this->integer($p['page'] ?? 1, 1, 1000000),
                'per_page' => $this->integer($p['per_page'] ?? 10, 1, 100)
            ];
        } elseif (in_array($operation, ['create', 'update'], true)) {
            $input = $this->object($p['data'] ?? null);
            if ($input === []) {
                throw new InvalidArgumentException('WooCommerce: crear o actualizar requiere al menos un campo en data.');
            }
            $result['payload'] = match ($resource) {
                'products' => $this->productPayload($input, $operation === 'create'),
                'orders' => $this->orderPayload($input, $operation === 'create'),
                'customers' => $this->customerPayload($input, $operation === 'create')
            };
        }
        return $result;
    }

    private function productPayload(array $input, bool $create): array
    {
        $rules = [
            'name' => 'nonempty', 'slug' => 'text', 'type' => ['simple'], 'status' => self::PRODUCT_STATUSES,
            'description' => 'longtext', 'short_description' => 'longtext', 'sku' => 'text',
            'regular_price' => 'decimal', 'sale_price' => 'decimal', 'manage_stock' => 'bool', 'stock_quantity' => 'quantity',
            'stock_status' => ['instock', 'outofstock', 'onbackorder'], 'backorders' => ['no', 'notify', 'yes'],
            'featured' => 'bool', 'virtual' => 'bool', 'sold_individually' => 'bool', 'reviews_allowed' => 'bool',
            'catalog_visibility' => ['visible', 'catalog', 'search', 'hidden'], 'categories' => 'relations', 'tags' => 'relations',
            'weight' => 'decimal', 'dimensions' => 'dimensions', 'shipping_class_id' => 'nonnegative',
            'tax_status' => ['taxable', 'shipping', 'none'], 'tax_class' => 'text', 'purchase_note' => 'longtext', 'menu_order' => 'nonnegative'
        ];
        $data = $this->fields($input, $rules);
        if ($create) {
            if (!array_key_exists('name', $data)) {
                throw new InvalidArgumentException('WooCommerce: crear un producto simple requiere name.');
            }
            $data += ['type' => 'simple', 'status' => 'draft'];
            if (isset($data['sale_price']) && $data['sale_price'] !== '' && (!isset($data['regular_price']) || $data['regular_price'] === '')) {
                throw new InvalidArgumentException('WooCommerce: crear con sale_price requiere regular_price no vacío.');
            }
            if (array_key_exists('stock_quantity', $data) && ($data['manage_stock'] ?? false) !== true) {
                throw new InvalidArgumentException('WooCommerce: crear con stock_quantity requiere manage_stock=true explícito.');
            }
        }
        if (($data['manage_stock'] ?? null) === false && array_key_exists('stock_quantity', $data)) {
            throw new InvalidArgumentException('WooCommerce: no combines stock_quantity con manage_stock=false.');
        }
        // Comparar cadenas sin convertir importes a float.
        if (isset($data['regular_price'], $data['sale_price']) && $data['regular_price'] !== '' && $data['sale_price'] !== ''
            && $this->decimalCompare($data['sale_price'], $data['regular_price']) >= 0) {
            throw new InvalidArgumentException('WooCommerce: sale_price debe ser menor que regular_price cuando ambos se proporcionan.');
        }
        return $data;
    }

    private function orderPayload(array $input, bool $create): array
    {
        $rules = ['status' => self::ORDER_STATUSES, 'customer_id' => 'id', 'customer_note' => 'longtext',
            'billing' => 'billing', 'shipping' => 'shipping'];
        if ($create) {
            $rules += ['currency' => 'currency', 'line_items' => 'line_items', 'payment_method' => 'nonempty', 'payment_method_title' => 'text'];
        }
        $data = $this->fields($input, $rules);
        if ($create) {
            if (!isset($data['line_items']) || $data['line_items'] === []) {
                throw new InvalidArgumentException('WooCommerce: crear pedido requiere line_items con product_id y quantity positivos.');
            }
            if (isset($data['payment_method_title']) && !isset($data['payment_method'])) {
                throw new InvalidArgumentException('WooCommerce: payment_method_title requiere payment_method.');
            }
            $data += ['status' => 'pending'];
            $data['set_paid'] = false;
        }
        return $data;
    }

    private function customerPayload(array $input, bool $create): array
    {
        $rules = ['email' => 'email', 'first_name' => 'text', 'last_name' => 'text', 'billing' => 'billing', 'shipping' => 'shipping'];
        if ($create) {
            $rules['username'] = 'username';
        }
        $data = $this->fields($input, $rules);
        if ($create && !isset($data['email'])) {
            throw new InvalidArgumentException('WooCommerce: crear cliente requiere email; habilita generación automática de contraseñas en el sitio.');
        }
        return $data;
    }

    private function fields(array $input, array $rules): array
    {
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw new InvalidArgumentException('WooCommerce: data contiene campos desconocidos, de solo lectura o no admitidos para esta operación.');
        }
        $data = [];
        foreach ($input as $key => $value) {
            $rule = $rules[$key];
            if (is_array($rule)) {
                if (!is_string($value) || !in_array($value, $rule, true)) {
                    throw new InvalidArgumentException('WooCommerce: valor de opción no admitido en data.');
                }
                $data[$key] = $value;
                continue;
            }
            $data[$key] = match ($rule) {
                'bool' => $this->boolean($value),
                'id' => $this->integer($value, 1),
                'quantity' => $this->integer($value, 0, 1000000000),
                'nonnegative' => $this->integer($value, 0),
                'decimal' => $this->decimal($value),
                'email' => $this->email($value),
                'currency' => $this->currency($value),
                'username' => $this->username($value),
                'text' => $this->text($value, 1000),
                'nonempty' => $this->text($value, 1000, false),
                'longtext' => $this->text($value, 100000, true, true),
                'billing', 'shipping' => (object) $this->address($value, $rule === 'billing'),
                'relations' => $this->relations($value),
                'dimensions' => (object) $this->dimensions($value),
                'line_items' => $this->lineItems($value),
                default => throw new InvalidArgumentException('WooCommerce: campo no admitido.')
            };
        }
        return $data;
    }

    private function address(mixed $value, bool $billing): array
    {
        $input = $this->object($value);
        $allowed = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
        if ($billing) {
            $allowed = array_merge($allowed, ['email', 'phone']);
        }
        if ($input === [] || array_diff(array_keys($input), $allowed) !== []) {
            throw new InvalidArgumentException('WooCommerce: dirección vacía o con campos no admitidos.');
        }
        foreach ($input as $key => $item) {
            $input[$key] = $this->text($item, $key === 'email' ? 254 : 255);
            if ($key === 'email' && $item !== '') {
                $input[$key] = $this->email($item);
            }
            if ($key === 'country' && $item !== '' && preg_match('/^[A-Z]{2}$/D', $item) !== 1) {
                throw new InvalidArgumentException('WooCommerce: country debe ser código ISO de dos letras mayúsculas o vacío explícito.');
            }
        }
        return $input;
    }

    private function relations(mixed $value): array
    {
        $items = $this->list($value, 100);
        $ids = [];
        foreach ($items as $item) {
            $relation = $this->object($item);
            if (array_keys($relation) !== ['id']) {
                throw new InvalidArgumentException('WooCommerce: categorías y etiquetas requieren objetos con solo id positivo.');
            }
            $id = $this->integer($relation['id'], 1);
            if (isset($ids[$id])) {
                throw new InvalidArgumentException('WooCommerce: IDs relacionados duplicados.');
            }
            $ids[$id] = true;
        }
        return array_map(fn($id) => ['id' => $id], array_keys($ids));
    }

    private function dimensions(mixed $value): array
    {
        $input = $this->object($value);
        if ($input === [] || array_diff(array_keys($input), ['length', 'width', 'height']) !== []) {
            throw new InvalidArgumentException('WooCommerce: dimensions requiere length, width o height, sin otros campos.');
        }
        return array_map(fn($item) => $this->decimal($item), $input);
    }

    private function lineItems(mixed $value): array
    {
        $items = $this->list($value, 100);
        if ($items === []) {
            throw new InvalidArgumentException('WooCommerce: line_items no puede estar vacío al crear.');
        }
        $result = [];
        foreach ($items as $item) {
            $line = $this->object($item);
            if (count($line) !== 2 || array_diff(array_keys($line), ['product_id', 'quantity']) !== []) {
                throw new InvalidArgumentException('WooCommerce: cada línea admite únicamente product_id y quantity; sin variaciones, precios forzados ni borrado.');
            }
            $result[] = ['product_id' => $this->integer($line['product_id'] ?? null, 1),
                'quantity' => $this->integer($line['quantity'] ?? null, 1, 1000000000)];
        }
        return $result;
    }

    private function filters(array $input, string $resource): array
    {
        $allowed = ['search', 'include', 'exclude', 'order', 'orderby'];
        $allowed = array_merge($allowed, match ($resource) {
            'products' => ['status', 'type', 'sku', 'category', 'tag', 'featured', 'on_sale', 'stock_status', 'min_price', 'max_price', 'after', 'before', 'modified_after', 'modified_before', 'dates_are_gmt'],
            'orders' => ['status', 'customer', 'product', 'after', 'before', 'modified_after', 'modified_before', 'dates_are_gmt'],
            'customers' => ['email']
        });
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InvalidArgumentException('WooCommerce: filtro no admitido para el recurso; no se aceptan rutas ni parámetros de autenticación.');
        }
        $result = [];
        foreach ($input as $key => $value) {
            if (in_array($key, ['include', 'exclude'], true)) {
                $result[$key] = array_map(fn($id) => $this->integer($id, 1), $this->list($value, 100));
            } elseif (in_array($key, ['after', 'before', 'modified_after', 'modified_before'], true)) {
                $result[$key] = $this->date($value);
            } elseif (in_array($key, ['dates_are_gmt', 'featured', 'on_sale'], true)) {
                $result[$key] = $this->boolean($value) ? 'true' : 'false';
            } elseif (in_array($key, ['category', 'tag', 'customer', 'product'], true)) {
                $result[$key] = $this->integer($value, 1);
            } elseif (in_array($key, ['min_price', 'max_price'], true)) {
                $result[$key] = $this->decimal($value, false);
            } elseif ($key === 'email') {
                $result[$key] = $this->email($value);
            } elseif ($key === 'status') {
                $options = $resource === 'products' ? array_merge(['any'], self::PRODUCT_STATUSES)
                    : array_merge(['any', 'refunded'], self::ORDER_STATUSES);
                if ($resource === 'orders') {
                    $statuses = is_string($value) ? [$value] : $this->list($value, 8);
                    if ($statuses === [] || count(array_filter($statuses, 'is_string')) !== count($statuses) || array_diff($statuses, $options) !== []) {
                        throw new InvalidArgumentException('WooCommerce: estados de filtro de pedidos inválidos.');
                    }
                    $result[$key] = $statuses;
                } else {
                    $result[$key] = $this->option($value, $options);
                }
            } elseif ($key === 'order') {
                $result[$key] = $this->option($value, ['asc', 'desc']);
            } elseif ($key === 'orderby') {
                $options = match ($resource) {
                    'products' => ['date', 'modified', 'id', 'include', 'title', 'slug', 'price', 'popularity', 'rating', 'menu_order'],
                    'orders' => ['date', 'modified', 'id', 'include', 'title', 'slug'],
                    'customers' => ['id', 'include', 'name', 'registered_date']
                };
                $result[$key] = $this->option($value, $options);
            } elseif ($key === 'type') {
                $result[$key] = $this->option($value, ['simple', 'grouped', 'external', 'variable']);
            } elseif ($key === 'stock_status') {
                $result[$key] = $this->option($value, ['instock', 'outofstock', 'onbackorder']);
            } else {
                $result[$key] = $this->text($value, 1000, false);
            }
        }
        foreach ([['after', 'before'], ['modified_after', 'modified_before']] as [$start, $end]) {
            if (isset($result[$start], $result[$end]) && new DateTimeImmutable($result[$start]) >= new DateTimeImmutable($result[$end])) {
                throw new InvalidArgumentException('WooCommerce: el filtro inicial debe ser anterior al final.');
            }
        }
        if (isset($result['min_price'], $result['max_price']) && $this->decimalCompare($result['min_price'], $result['max_price']) > 0) {
            throw new InvalidArgumentException('WooCommerce: min_price no puede superar max_price.');
        }
        return $result;
    }

    private function credential(string $id): array
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (Throwable $e) {
            throw new RuntimeException('WooCommerce: fallo del gestor al recuperar o descifrar la credencial; revisa almacenamiento y configuración.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('WooCommerce: la credencial seleccionada no existe.');
        }
        $raw = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($raw) || strlen($raw) > 16384) {
            throw new InvalidArgumentException('WooCommerce: se requiere generic_bearer con JSON en API Key.');
        }
        try {
            $config = $this->object($raw);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('WooCommerce: JSON de credencial inválido; configura site_url, consumer_key y consumer_secret en API Key.');
        }
        if (count($config) !== 3 || array_diff(array_keys($config), ['site_url', 'consumer_key', 'consumer_secret']) !== []) {
            throw new InvalidArgumentException('WooCommerce: la credencial requiere site_url, consumer_key y consumer_secret exclusivamente.');
        }
        foreach (['consumer_key' => 'ck_', 'consumer_secret' => 'cs_'] as $key => $prefix) {
            if (!is_string($config[$key]) || preg_match('/^' . $prefix . '[a-fA-F0-9]{40}$/D', $config[$key]) !== 1) {
                throw new InvalidArgumentException('WooCommerce: consumer_key o consumer_secret mal formados; usa las claves REST de WooCommerce.');
            }
        }
        $url = $config['site_url'];
        if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new InvalidArgumentException('WooCommerce: site_url debe ser una URL HTTPS canónica del sitio.');
        }
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        $decoded = rawurldecode($path);
        if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('~(?:^|/)(?:\.{1,2}|wp-json)(?:/|$)~i', $decoded) || str_contains($decoded, '\\')
            || preg_match('/[\x00-\x20\x7f%]/', $decoded) || str_contains($decoded, '//')) {
            throw new InvalidArgumentException('WooCommerce: usa site_url HTTPS con subdirectorio opcional, sin credenciales, consulta, /wp-json ni segmentos ambiguos.');
        }
        $config['site_url'] = rtrim($url, '/');
        return $config;
    }

    private function request(string $method, string $url, array $config, array $query, ?array $payload): array
    {
        $basic = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);
        $options = ['headers' => null, 'auth' => null, 'cookies' => false, 'query' => $query === [] ? null : $query,
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true];
        if ($payload !== null) {
            $options['json'] = (object) $payload;
        }
        try {
            $response = ($this->client ?? new Client())->send(new Request($method, $url,
                ['Authorization' => 'Basic ' . $basic, 'Accept' => 'application/json']), $options);
            $status = $response->getStatusCode();
            $stream = $response->getBody();
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= self::MAX_BODY) {
                $chunk = $stream->read(min(8192, self::MAX_BODY + 1 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $raw .= $chunk;
            }
            if (strlen($raw) > self::MAX_BODY) {
                throw new RuntimeException();
            }
        } catch (Throwable $e) {
            throw new RuntimeException('WooCommerce: fallo de transporte o lectura (máximo 4 MiB); sin reintento.' . $this->uncertain($method));
        } finally {
            if (isset($response)) {
                try {
                    $response->getBody()->close();
                } catch (Throwable $e) {
                    throw new RuntimeException('WooCommerce: no se pudo cerrar la lectura de forma segura.' . $this->uncertain($method));
                }
            }
        }
        $body = json_decode($raw, false, 64);
        $functionalError = $body instanceof stdClass && (property_exists($body, 'code') || property_exists($body, 'error')
            || property_exists($body, 'errors') || ($body->success ?? true) === false);
        if ($status < 200 || $status >= 300 || $functionalError) {
            $code = $body instanceof stdClass && is_string($body->code ?? null) ? $body->code : '';
            $remoteStatus = $body instanceof stdClass ? ($body->data->status ?? null) : null;
            $effective = $functionalError && is_int($remoteStatus) && $remoteStatus >= 400 && $remoteStatus <= 599 ? $remoteStatus : $status;
            $reason = match (true) {
                $status >= 300 && $status < 400 => 'redirección rechazada; usa la URL HTTPS canónica del sitio',
                $code === 'rest_missing_callback_param' => 'faltan campos exigidos por la tienda; al crear clientes sin contraseña habilita generación automática y revisa username',
                in_array($code, ['registration-error-email-exists', 'registration-error-username-exists', 'woocommerce_rest_customer_invalid_email', 'woocommerce_rest_product_invalid_sku'], true)
                    => 'email, nombre de usuario o SKU duplicado/inválido; no se seleccionó ni actualizó otro recurso',
                $effective === 401 => 'autenticación rechazada; revisa claves REST y que el servidor preserve Authorization',
                $effective === 403 => 'permisos insuficientes; revisa lectura/escritura de la clave y capacidades del usuario',
                $effective === 404 => 'recurso inexistente o ruta REST no disponible; revisa sitio, subdirectorio y permalinks',
                $effective === 409 => 'conflicto o duplicado; consulta la tienda antes de repetir',
                $effective === 429 => 'cuota o límite de solicitudes alcanzado; no se reintentó',
                $effective >= 500 => 'fallo del servidor de la tienda',
                default => 'campos, estado, identificadores o configuración rechazados por WooCommerce'
            };
            throw new RuntimeException('WooCommerce: ' . $reason . ' (HTTP ' . $status . ').' . $this->uncertain($method), $effective);
        }
        if (json_last_error() !== JSON_ERROR_NONE || (!is_array($body) && !$body instanceof stdClass)) {
            throw new RuntimeException('WooCommerce: respuesta JSON inválida o vacía.' . $this->uncertain($method), $status);
        }
        return [$response, $body];
    }

    private function resourceResult(mixed $value, string $resource, ?int $expectedId = null): array
    {
        if (!$value instanceof stdClass || property_exists($value, 'code') || property_exists($value, 'error')
            || property_exists($value, 'errors') || ($value->success ?? true) === false) {
            throw new InvalidArgumentException('WooCommerce: representación de recurso inválida.');
        }
        $id = $this->integer($value->id ?? null, 1);
        if ($expectedId !== null && $id !== $expectedId) {
            throw new InvalidArgumentException('WooCommerce: ID de respuesta incompatible.');
        }
        $data = ['id' => $id];
        $required = match ($resource) {
            'products' => ['name', 'type', 'status', 'sku', 'price', 'permalink'],
            'orders' => ['number', 'status', 'currency', 'total'],
            'customers' => ['email', 'first_name', 'last_name']
        };
        foreach ($required as $field) {
            $data[$field] = $this->text($value->{$field} ?? null, 4096);
        }
        if ($resource === 'products') {
            $this->option($data['type'], ['simple', 'grouped', 'external', 'variable']);
            $this->option($data['status'], self::PRODUCT_STATUSES);
            $this->decimal($data['price']);
            $this->safeLink($data['permalink']);
            foreach (['regular_price', 'sale_price'] as $field) {
                if (property_exists($value, $field)) {
                    $data[$field] = $this->decimal($value->{$field});
                }
            }
            if (property_exists($value, 'manage_stock')) {
                $data['manage_stock'] = $this->boolean($value->manage_stock);
            }
            if (property_exists($value, 'stock_quantity')) {
                $data['stock_quantity'] = $value->stock_quantity === null ? null : $this->integer($value->stock_quantity, -1000000000, 1000000000);
            }
            if (property_exists($value, 'stock_status')) {
                $data['stock_status'] = $this->option($value->stock_status, ['instock', 'outofstock', 'onbackorder']);
            }
        } elseif ($resource === 'orders') {
            $this->option($data['status'], array_merge(self::ORDER_STATUSES, ['refunded', 'trash']));
            $this->currency($data['currency']);
            $this->decimal($data['total'], false);
            if ($data['number'] === '') {
                throw new InvalidArgumentException('WooCommerce: pedido sin número.');
            }
            if (property_exists($value, 'customer_id')) {
                $data['customer_id'] = $this->integer($value->customer_id, 0);
            }
        } else {
            $this->email($data['email']);
        }
        foreach (['date_created', 'date_created_gmt', 'date_modified', 'date_modified_gmt'] as $field) {
            if (property_exists($value, $field)) {
                $data[$field] = $value->{$field} === null ? null : $this->date($value->{$field});
            }
        }
        return $data;
    }

    private function safeLink(string $url): void
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || preg_match('/(?:[?&])[^=&]*(?:key|token|secret|password|signature|nonce|auth)[^=&]*=/i', $url)
            || str_contains(rawurldecode($url), 'wc_order_')) {
            throw new InvalidArgumentException('WooCommerce: enlace público inválido o sensible.');
        }
    }

    private function checkSecrets(array $data, array $config): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $combined = $config['consumer_key'] . ':' . $config['consumer_secret'];
        foreach ([$config['consumer_key'], $config['consumer_secret'], base64_encode($combined)] as $secret) {
            if (str_contains($encoded, $secret) || str_contains($encoded, rawurlencode($secret))) {
                throw new InvalidArgumentException('WooCommerce: respuesta contiene datos sensibles.');
            }
        }
    }

    private function object(mixed $value): array
    {
        if (is_string($value)) {
            if (strlen($value) > self::MAX_INPUT) {
                throw new InvalidArgumentException('WooCommerce: objeto JSON superior al límite local de 256 KiB.');
            }
            try {
                $value = json_decode($value, false, 16, JSON_THROW_ON_ERROR);
                if (!$value instanceof stdClass) {
                    throw new InvalidArgumentException();
                }
            } catch (Throwable $e) {
                throw new InvalidArgumentException('WooCommerce: se requiere un objeto JSON válido.');
            }
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('WooCommerce: se requiere objeto o array asociativo, no lista ni valor escalar.');
        }
        try {
            if (strlen(json_encode($value, JSON_THROW_ON_ERROR, 20)) > self::MAX_INPUT) {
                throw new InvalidArgumentException('WooCommerce: objeto superior a 256 KiB.');
            }
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('WooCommerce: objeto no representable como JSON o demasiado anidado.');
        }
        return $value;
    }

    private function list(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException('WooCommerce: se requiere una lista JSON dentro del límite admitido.');
        }
        return $value;
    }

    private function text(mixed $value, int $maximum, bool $empty = true, bool $multiline = false): string
    {
        if (!is_string($value) || strlen($value) > $maximum || !mb_check_encoding($value, 'UTF-8')
            || (!$empty && trim($value) === '') || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('WooCommerce: texto vacío, tipo incorrecto, codificación inválida o límite excedido.');
        }
        return $value;
    }

    private function integer(mixed $value, int $minimum, int $maximum = 9007199254740991): int
    {
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,15})$/D', $value)) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('WooCommerce: ID, cantidad o paginación fuera del rango de enteros admitido.');
        }
        return $value;
    }

    private function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('WooCommerce: se requiere booleano JSON, no texto ni número.');
        }
        return $value;
    }

    private function decimal(mixed $value, bool $empty = true): string
    {
        if (!is_string($value) || !(($empty && $value === '') || preg_match('/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,12})?$/D', $value))) {
            throw new InvalidArgumentException('WooCommerce: importe o medida requiere cadena decimal no negativa (máximo 18 enteros y 12 decimales), sin coma ni notación científica.');
        }
        return $value;
    }

    private function decimalCompare(string $left, string $right): int
    {
        [$li, $lf] = array_pad(explode('.', $left, 2), 2, '');
        [$ri, $rf] = array_pad(explode('.', $right, 2), 2, '');
        return strlen($li) <=> strlen($ri) ?: strcmp($li, $ri) ?: strcmp(str_pad($lf, 12, '0'), str_pad($rf, 12, '0'));
    }

    private function email(mixed $value): string
    {
        $value = $this->text($value, 254, false);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('WooCommerce: email simple inválido.');
        }
        return $value;
    }

    private function username(mixed $value): string
    {
        $value = $this->text($value, 60, false);
        if (preg_match('/^[A-Za-z0-9._@-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('WooCommerce: username admite letras ASCII, números y . _ @ -; solo al crear.');
        }
        return $value;
    }

    private function currency(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new InvalidArgumentException('WooCommerce: currency requiere código ISO de tres letras mayúsculas; la tienda valida su disponibilidad.');
        }
        return $value;
    }

    private function option(mixed $value, array $options): string
    {
        if (!is_string($value) || !in_array($value, $options, true)) {
            throw new InvalidArgumentException('WooCommerce: opción fuera de los valores admitidos.');
        }
        return $value;
    }

    private function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(Z|[+-](?:0\d|1[0-4]):[0-5]\d)?$/D', $value, $match) !== 1
            || substr($value, 0, 4) === '0000') {
            throw new InvalidArgumentException('WooCommerce: fecha requiere ISO8601 YYYY-MM-DDTHH:MM:SS con zona opcional.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $match[1], new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s') !== $match[1]
            || (isset($match[2]) && preg_match('/^[+-]14:(?!00)/', $match[2]))) {
            throw new InvalidArgumentException('WooCommerce: fecha u hora imposible.');
        }
        return $value;
    }

    private function headerInteger(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        return $this->integer($value, 0);
    }

    private function uncertain(string $method): string
    {
        return $method === 'GET' ? '' : ' Resultado incierto: la escritura puede haberse realizado; comprueba la tienda antes de repetir.';
    }
}
