<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use Throwable;

class ShopifyNode implements NodeInterface
{
    private const FIELDS = ['products' => 'id title handle status vendor productType tags createdAt updatedAt', 'customers' => 'id createdAt updatedAt', 'orders' => 'id name createdAt updatedAt displayFinancialStatus displayFulfillmentStatus totalPriceSet { shopMoney { amount currencyCode } }'];

    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return ['type' => 'shopify', 'name' => 'Shopify e-Commerce', 'category' => 'Community', 'icon' => 'ShoppingBag', 'is_start' => false,
            'description' => 'Admin GraphQL: productos, clientes y metadatos de pedidos.',
            'help_text' => 'generic_bearer API Key JSON: {"shop_domain":"ejemplo.myshopify.com","access_token":"token_ficticio","api_version":"2026-10"}. Versión estable configurable; sin OAuth/renovación automática. Productos/clientes list/get/create/update; pedidos list/get/update solo note/tags (sin cambios financieros, pago, fulfillment, líneas, creación de pedidos ni draft orders). Crear producto siempre DRAFT, sin publicación. Actualizar producto admite title, descriptionHtml, vendor, productType, tags y handle; sin cambio de status/publicación. Clientes: email/firstName/lastName/phone/note/tags; no consentimiento implícito. Devuelve clientes solo ID/fechas, pedidos sin PII/notas y productos sin HTML; no vuelca metadata o errores remotos en historiales. En update solo campos presentes, vacío explícito se preserva donde API lo admita. Una página first 1–100, after cursor opaco; sin bucles/retries. Scopes read/write_products, read/write_customers y read/write_orders; clientes requieren acceso a datos protegidos aprobado. Pedidos limitados por defecto a últimos 60 días; read_all_orders adicional aprobado para anteriores. Transporte incierto de escritura: verificar Shopify antes de repetir. Eventos disponibles se configuran en Triggers; carts solo Online Store, no carritos Storefront personalizados. Cumplimiento App Store exige customers/data_request, customers/redact, shop/redact y atender realmente solicitudes de privacidad externamente.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'label' => 'Credencial Shopify JSON', 'required' => true],
                'resource' => ['type' => 'select', 'label' => 'Recurso', 'default' => 'products', 'required' => true, 'options' => array_map(fn($v) => ['value' => $v, 'label' => $v], ['products','customers','orders'])],
                'operation' => ['type' => 'select', 'label' => 'Operación', 'default' => 'list', 'required' => true, 'options' => array_map(fn($v) => ['value' => $v, 'label' => $v], ['list','get','create','update'])],
                'resource_id' => ['type' => 'string', 'label' => 'ID GraphQL gid://shopify/…', 'required' => false],
                'data' => ['type' => 'textarea', 'label' => 'Campos JSON crear/actualizar', 'required' => false],
                'first' => ['type' => 'number', 'label' => 'Tamaño de página', 'default' => 10, 'min' => 1, 'max' => 100],
                'after' => ['type' => 'string', 'label' => 'Cursor de página anterior', 'required' => false],
                'query' => ['type' => 'string', 'label' => 'Filtro de búsqueda Shopify (list)', 'required' => false]
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $p = $this->prepare($parameters); $c = $this->credential($p['credential_id']);
        $options = ['headers' => ['X-Shopify-Access-Token' => $c['access_token'], 'Accept' => 'application/json'], 'json' => ['query' => $p['graphql'], 'variables' => $p['variables']], 'timeout' => 25, 'connect_timeout' => 10, 'verify' => true, 'http_errors' => false, 'allow_redirects' => false, 'stream' => true];
        try { $response = ($this->client ?? new Client())->request('POST', 'https://' . $c['shop_domain'] . '/admin/api/' . $c['api_version'] . '/graphql.json', $options); }
        catch (Throwable $e) { throw new RuntimeException('Shopify: fallo de transporte; escritura de resultado incierto. No se reintentó.'); }
        try { $raw = $response->getBody()->read(2097153); }
        catch (Throwable $e) { throw new RuntimeException('Shopify: respuesta interrumpida; resultado de escritura incierto. No se reintentó.'); }
        if (strlen($raw) > 2097152) { throw new RuntimeException('Shopify: respuesta demasiado grande.'); }
        $body = json_decode($raw, true);
        $servedVersion = $response->getHeaderLine('X-Shopify-API-Version');
        if ($servedVersion !== '' && $servedVersion !== $c['api_version']) { throw new RuntimeException('Shopify: versión solicitada no disponible; servidor cambió de versión. Verifica el resultado antes de repetir una escritura.'); }
        if ($response->getStatusCode() !== 200 || !is_array($body) || !empty($body['errors']) || !is_array($body['data'] ?? null)) { throw new RuntimeException('Shopify: operación rechazada o GraphQL errors (HTTP ' . $response->getStatusCode() . '). Revisa scopes, versión, throttling y permisos; sin reintentos.'); }
        $value = $body['data'][$p['root']] ?? null;
        if ($p['operation'] === 'list') {
            if (!is_array($value) || !is_array($value['nodes'] ?? null) || !array_is_list($value['nodes']) || count($value['nodes']) > $p['variables']['first'] || !is_bool($value['pageInfo']['hasNextPage'] ?? null)) { throw new RuntimeException('Shopify: página GraphQL inválida.'); }
            $items = array_map(fn($item) => $this->result($item, $p['resource']), $value['nodes']);
            $cursor = $value['pageInfo']['endCursor'] ?? null;
            if ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 4096)) { throw new RuntimeException('Shopify: cursor inválido.'); }
            return ['success' => true, 'data' => ['items' => $items, 'has_more' => $value['pageInfo']['hasNextPage'], 'next_cursor' => $value['pageInfo']['hasNextPage'] ? $cursor : null]];
        }
        if (in_array($p['operation'], ['create','update'], true)) {
            if (!is_array($value) || !array_key_exists('userErrors', $value) || !is_array($value['userErrors']) || $value['userErrors'] !== []) { throw new RuntimeException('Shopify: mutation userErrors; revisa los campos y permisos. No se reintentó.'); }
            $value = $value[$p['singular']] ?? null;
        }
        return ['success' => true, 'data' => $this->result($value, $p['resource'])];
    }

    private function prepare(array $p): array
    {
        if (array_diff(array_keys($p), ['credential_id','resource','operation','resource_id','data','first','after','query','_sub_connections'])) { throw new InvalidArgumentException('Shopify: parámetro no admitido.'); }
        $this->text($p['credential_id'] ?? null, 255, false);
        $resource = $p['resource'] ?? 'products'; $operation = $p['operation'] ?? 'list';
        if (!is_string($resource) || !isset(self::FIELDS[$resource]) || !in_array($operation, ['list','get','create','update'], true) || ($resource === 'orders' && $operation === 'create')) { throw new InvalidArgumentException('Shopify: recurso/operación no admitido. No crea pedidos ni borradores.'); }
        $singular = ['products' => 'product', 'customers' => 'customer', 'orders' => 'order'][$resource]; $fields = self::FIELDS[$resource]; $variables = [];
        if ($operation === 'list') {
            $first = $p['first'] ?? 10; if (is_string($first) && preg_match('/^[0-9]+$/D', $first)) { $first = (int)$first; }
            if (!is_int($first) || $first < 1 || $first > 100) { throw new InvalidArgumentException('Shopify: first entero 1–100.'); }
            $variables['first'] = $first;
            foreach (['after','query'] as $field) { if (($p[$field] ?? '') !== '') { $this->text($p[$field], $field === 'after' ? 4096 : 1024, false); $variables[$field] = $p[$field]; } }
            $root = $resource;
            $graphql = 'query P8PList($first:Int!,$after:String,$query:String){' . $root . '(first:$first,after:$after,query:$query){nodes{' . $fields . '} pageInfo{hasNextPage endCursor}}}';
        } elseif ($operation === 'get') {
            $this->id($p['resource_id'] ?? null, $singular); $variables['id'] = $p['resource_id']; $root = $singular;
            $graphql = 'query P8PGet($id:ID!){' . $root . '(id:$id){' . $fields . '}}';
        } else {
            $input = $this->object($p['data'] ?? null);
            $allowed = match ($resource) { 'products' => ['title','descriptionHtml','vendor','productType','tags','handle'], 'customers' => ['email','firstName','lastName','phone','note','tags'], default => ['note','tags'] };
            if (!$input || array_diff(array_keys($input), $allowed)) { throw new InvalidArgumentException('Shopify: campos no admitidos o actualización vacía.'); }
            foreach ($input as $key => $value) {
                if ($key === 'tags') {
                    if (!is_array($value) || !array_is_list($value) || count($value) > 250) { throw new InvalidArgumentException('Shopify: tags debe ser lista de hasta 250 cadenas.'); }
                    foreach ($value as $tag) { $this->text($tag, 255, false); }
                } else { $this->text($value, $key === 'descriptionHtml' ? 65536 : ($key === 'note' ? 5000 : 500), $operation === 'update' && !in_array($key, ['title','handle'], true)); }
            }
            if (isset($input['email']) && $input['email'] !== '' && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Shopify: email inválido.'); }
            if ($operation === 'create' && $resource === 'products') { $this->text($input['title'] ?? null, 500, false); $input['status'] = 'DRAFT'; }
            if ($operation === 'create' && $resource === 'customers' && !isset($input['email']) && !isset($input['phone'])) { throw new InvalidArgumentException('Shopify: crear cliente exige email o phone.'); }
            if ($operation === 'update') { $this->id($p['resource_id'] ?? null, $singular); $input['id'] = $p['resource_id']; }
            $root = $singular . ucfirst($operation);
            $inputType = $resource === 'products' ? 'Product' . ucfirst($operation) . 'Input!' : ($resource === 'customers' ? 'CustomerInput!' : 'OrderInput!');
            $argument = $resource === 'products' ? 'product' : 'input'; $variables['input'] = $input;
            $graphql = 'mutation P8PWrite($input:' . $inputType . '){' . $root . '(' . $argument . ':$input){' . $singular . '{' . $fields . '} userErrors{field message}}}';
        }
        return compact('resource','operation','singular','root','graphql','variables') + ['credential_id' => $p['credential_id']];
    }

    private function credential(string $id): array
    {
        try { $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id); }
        catch (Throwable $e) { throw new RuntimeException('Shopify: no se pudo recuperar o descifrar la credencial.'); }
        if (($credential['type'] ?? '') !== 'generic_bearer') { throw new InvalidArgumentException('Shopify: requiere generic_bearer.'); }
        $c = $this->object($credential['data']['api_key'] ?? null);
        if (!is_string($c['shop_domain'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $c['shop_domain'])) { throw new InvalidArgumentException('Shopify: shop_domain debe ser dominio canónico .myshopify.com sin ruta.'); }
        $this->text($c['access_token'] ?? null, 4096, false);
        if (preg_match('/[\x00-\x20\x7f]/', $c['access_token'])) { throw new InvalidArgumentException('Shopify: access_token inválido.'); }
        $c['api_version'] ??= '2026-10';
        if (!is_string($c['api_version']) || !preg_match('/^20[0-9]{2}-(?:01|04|07|10)$/D', $c['api_version'])) { throw new InvalidArgumentException('Shopify: versión estable YYYY-01/04/07/10 requerida.'); }
        return $c;
    }

    private function result(mixed $item, string $resource): array
    {
        if (!is_array($item)) { throw new RuntimeException('Shopify: recurso no encontrado o inválido.'); }
        $this->id($item['id'] ?? null, ['products'=>'product','customers'=>'customer','orders'=>'order'][$resource]);
        $keys = match ($resource) { 'products' => ['id','title','handle','status','vendor','productType','tags','createdAt','updatedAt'], 'customers' => ['id','createdAt','updatedAt'], default => ['id','name','createdAt','updatedAt','displayFinancialStatus','displayFulfillmentStatus','totalPriceSet'] };
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && (is_scalar($item[$key]) || $item[$key] === null)) { $out[$key] = $item[$key]; }
        }
        if ($resource === 'products' && isset($item['tags'])) {
            if (!is_array($item['tags']) || count($item['tags']) > 250) { throw new RuntimeException('Shopify: tags de respuesta inválidos.'); }
            foreach ($item['tags'] as $tag) { $this->text($tag, 255, false); }
            $out['tags'] = $item['tags'];
        }
        if ($resource === 'orders' && isset($item['totalPriceSet']['shopMoney'])) {
            $money = $item['totalPriceSet']['shopMoney'];
            if (!is_array($money) || !is_string($money['amount'] ?? null) || !is_string($money['currencyCode'] ?? null)) { throw new RuntimeException('Shopify: importe inválido.'); }
            $out['totalPriceSet'] = ['shopMoney'=>['amount'=>$money['amount'],'currencyCode'=>$money['currencyCode']]];
        }
        return $out;
    }

    private function id(mixed $id, string $singular): void
    {
        if (!is_string($id) || !preg_match('~^gid://shopify/' . ucfirst($singular) . '/[1-9][0-9]{0,24}$~D', $id)) { throw new InvalidArgumentException('Shopify: ID GraphQL incompatible.'); }
    }

    private function text(mixed $value, int $max, bool $allowEmpty): void
    {
        if (!is_string($value) || (!$allowEmpty && trim($value) === '') || strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) { throw new InvalidArgumentException('Shopify: texto inválido.'); }
    }

    private function object(mixed $value): array
    {
        try {
            if (is_string($value)) { if (strlen($value) > 131072) { throw new \Exception(); } $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR); }
            if ($value instanceof \stdClass) { $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR); }
            if (!is_array($value) || ($value !== [] && array_is_list($value)) || strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 131072) { throw new \Exception(); }
            $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
            return $value;
        } catch (Throwable $e) { throw new InvalidArgumentException('Shopify: objeto JSON inválido.'); }
    }
}
