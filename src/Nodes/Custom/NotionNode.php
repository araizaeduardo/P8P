<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use stdClass;
use Throwable;

class NotionNode implements NodeInterface
{
    private const VERSION = '2026-03-11';
    private const PROPERTY_TYPES = ['title', 'rich_text', 'number', 'checkbox', 'select', 'status', 'multi_select',
        'date', 'people', 'relation', 'files', 'url', 'email', 'phone_number'];

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
            'type' => 'notion', 'name' => 'Notion', 'category' => 'Community', 'icon' => 'Notebook',
            'description' => 'Crea páginas, modifica propiedades o consulta una fuente de datos de Notion.',
            'is_start' => false,
            'help_text' => 'API 2026-03-11. Guarda el token de integración en API Key de una credencial Bearer Token / API Key, sin Bearer. Comparte la página/fuente con la integración y habilita insert content, update content o read content según la operación. Crear exige parent_type, parent_id y properties: bajo page_id usa solo {"title":{"title":[{"text":{"content":"Título"}}]}}; bajo data_source_id usa nombres/tipos del esquema. Actualizar envía únicamente properties proporcionadas: [] vacía listas y null vacía campos anulables; no se sustituyen otras propiedades. Objetos/listas aceptan JSON o {{origen.data}} resuelto. Consultar exige data_source_id, no database_id, y devuelve una sola página (máximo 100), data.records y data.pagination.has_more/next_cursor. Usa next_cursor como start_cursor en otra ejecución. Sin reintentos. Los errores detienen el flujo; una escritura incierta debe comprobarse en Notion antes de repetir. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial de integración Notion'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'query_records',
                    'options' => [
                        ['value' => 'create_page', 'label' => 'Crear página'],
                        ['value' => 'update_properties', 'label' => 'Actualizar propiedades'],
                        ['value' => 'query_records', 'label' => 'Consultar registros']
                    ]],
                'parent_type' => ['type' => 'select', 'required' => false, 'label' => 'Tipo de padre (crear)', 'default' => 'data_source_id',
                    'options' => [['value' => 'data_source_id', 'label' => 'Fuente de datos'], ['value' => 'page_id', 'label' => 'Página']]],
                'parent_id' => ['type' => 'string', 'required' => false, 'label' => 'ID del padre (crear)'],
                'page_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de página (actualizar)'],
                'properties' => ['type' => 'textarea', 'required' => false, 'label' => 'Propiedades (objeto JSON; crear/actualizar)',
                    'placeholder' => '{"Name":{"title":[{"text":{"content":"Ejemplo"}}]}}'],
                'data_source_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de fuente de datos (consultar)'],
                'filter' => ['type' => 'textarea', 'required' => false, 'label' => 'Filtro (objeto JSON, opcional)',
                    'placeholder' => '{"property":"Done","checkbox":{"equals":true}}'],
                'sorts' => ['type' => 'textarea', 'required' => false, 'label' => 'Orden (lista JSON, opcional)',
                    'placeholder' => '[{"property":"Name","direction":"ascending"}]'],
                'start_cursor' => ['type' => 'string', 'required' => false, 'label' => 'Cursor (consultar, opcional)'],
                'page_size' => ['type' => 'number', 'required' => false, 'label' => 'Registros por página (1–100)', 'default' => 100]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepareRequest($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine aplica ExpressionEvaluator una vez. No reinterpretar el contenido de propiedades.
        $request = $this->prepareRequest($parameters);
        try {
            $manager = $this->credentials ?? new CredentialManager();
            $credential = $manager->getCredentialDecrypted($request['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('Notion: no se pudo recuperar o descifrar la credencial en el gestor de p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Notion: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || preg_match('/^[A-Za-z0-9._~-]{10,4096}$/D', trim($token)) !== 1) {
            throw new InvalidArgumentException('Notion: selecciona una credencial Bearer Token / API Key con un token de integración, sin el prefijo Bearer.');
        }
        $token = trim($token);
        $notice = $request['operation'] === 'query_records' ? ''
            : ' La escritura puede haberse realizado; comprueba la página antes de repetirla.';
        try {
            $client = $this->client ?? new Client();
            $response = $client->request($request['method'], 'https://api.notion.com/v1' . $request['path'], [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Notion-Version' => self::VERSION, 'Accept' => 'application/json'],
                'json' => $request['payload'], 'timeout' => 30, 'connect_timeout' => 10,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false
            ]);
            $status = $response->getStatusCode();
            $rawBody = (string) $response->getBody();
        } catch (Throwable $e) {
            throw new RuntimeException('Notion: fallo de transporte o lectura de la respuesta.' . $notice);
        }
        if ($status < 200 || $status >= 300) {
            $reason = match (true) {
                $status === 401 => 'token inválido, expirado o revocado',
                $status === 403 => 'permisos insuficientes; revisa las capacidades y el acceso de la integración',
                $status === 404 => 'página o fuente inexistente o no compartida con la integración',
                $status === 429 => 'límite de solicitudes o cuota alcanzado',
                $status >= 500 => 'servicio temporalmente no disponible',
                $status === 409 => 'conflicto al escribir; revisa el estado actual del recurso',
                $status >= 300 && $status < 400 => 'redirección rechazada',
                default => 'solicitud rechazada; revisa IDs, esquema de propiedades, filtro y orden'
            };
            $retry = $response->getHeaderLine('Retry-After');
            $wait = preg_match('/^[0-9]{1,9}$/D', $retry) === 1 && in_array($status, [429, 503, 529], true)
                ? ' Espera ' . (int) $retry . ' segundos antes de volver a intentar.' : '';
            throw new RuntimeException("Notion: {$reason} (HTTP {$status})." . $wait . $notice, $status);
        }
        try {
            $body = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
            if ($request['operation'] === 'query_records') {
                if (!is_array($body) || ($body['object'] ?? '') !== 'list' || !is_array($body['results'] ?? null)
                    || !array_is_list($body['results']) || count($body['results']) > $request['payload']['page_size']
                    || !is_bool($body['has_more'] ?? null) || !array_key_exists('next_cursor', $body)
                    || ($body['next_cursor'] !== null && (!is_string($body['next_cursor']) || $body['next_cursor'] === ''))
                    || ($body['has_more'] && $body['next_cursor'] === null)) {
                    throw new InvalidArgumentException();
                }
                $data = ['records' => array_map(fn ($page): array => $this->normalizePage($page), $body['results']),
                    'pagination' => ['has_more' => $body['has_more'], 'next_cursor' => $body['next_cursor'],
                        'page_size' => $request['payload']['page_size'], 'count' => count($body['results'])]];
            } else {
                $data = $this->normalizePage($body, true);
                if ($request['operation'] === 'update_properties' && $data['id'] !== $request['page_id']) {
                    throw new InvalidArgumentException();
                }
            }
            // Rechazar incluso secretos reflejados por el proveedor dentro de propiedades conocidas.
            if (str_contains(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $token)) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Notion: respuesta inválida o incompleta.' . $notice, $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepareRequest(array $parameters): array
    {
        $credentialId = $this->text($parameters['credential_id'] ?? null);
        if (trim($credentialId) === '') {
            throw new InvalidArgumentException('Notion: selecciona una credencial.');
        }
        $operation = array_key_exists('operation', $parameters) ? $parameters['operation'] : 'query_records';
        if (!in_array($operation, ['create_page', 'update_properties', 'query_records'], true)) {
            throw new InvalidArgumentException('Notion: operación inválida.');
        }
        $pageId = null;
        if ($operation === 'query_records') {
            $id = $this->uuid($parameters['data_source_id'] ?? null);
            $size = array_key_exists('page_size', $parameters) ? $parameters['page_size'] : 100;
            if ((!is_int($size) && !is_string($size)) || preg_match('/^[1-9][0-9]{0,2}$/D', (string) $size) !== 1 || (int) $size > 100) {
                throw new InvalidArgumentException('Notion: page_size debe ser un entero de 1 a 100.');
            }
            $payload = ['page_size' => (int) $size, 'result_type' => 'page'];
            foreach (['filter', 'sorts'] as $field) {
                if (!array_key_exists($field, $parameters) || $parameters[$field] === '') {
                    continue;
                }
                $value = $field === 'filter' ? $this->object($parameters[$field]) : $this->listValue($parameters[$field]);
                if ($field === 'filter' && (array) $value !== []) {
                    $this->validateFilter($value);
                }
                if ($field === 'sorts') {
                    if (count($value) > 100) {
                        throw new InvalidArgumentException('Notion: sorts admite como máximo 100 criterios.');
                    }
                    foreach ($value as $sort) {
                        $sort = $this->object($sort, false);
                        if (count(array_intersect(array_keys((array) $sort), ['property', 'timestamp'])) !== 1
                            || !in_array($sort->direction ?? null, ['ascending', 'descending'], true)
                            || array_diff(array_keys((array) $sort), ['property', 'timestamp', 'direction']) !== []) {
                            throw new InvalidArgumentException('Notion: cada orden requiere property o timestamp y direction.');
                        }
                        if (isset($sort->property) && trim($this->text($sort->property)) === '') {
                            throw new InvalidArgumentException('Notion: propiedad de orden vacía.');
                        }
                        if (isset($sort->timestamp) && !in_array($sort->timestamp, ['created_time', 'last_edited_time'], true)) {
                            throw new InvalidArgumentException('Notion: timestamp de orden inválido.');
                        }
                    }
                }
                if ((array) $value !== []) {
                    $payload[$field] = $value;
                }
            }
            if (array_key_exists('start_cursor', $parameters)) {
                $cursor = $this->text($parameters['start_cursor']);
                if (strlen($cursor) > 4096 || preg_match('/[\x00-\x20\x7f]/', $cursor)) {
                    throw new InvalidArgumentException('Notion: cursor inválido.');
                }
                if ($cursor !== '') {
                    $payload['start_cursor'] = $cursor;
                }
            }
            $method = 'POST';
            $path = '/data_sources/' . $id . '/query';
        } else {
            $properties = $this->object($parameters['properties'] ?? null);
            if ((array) $properties === []) {
                throw new InvalidArgumentException('Notion: indica al menos una propiedad para crear o actualizar.');
            }
            foreach ($properties as $name => $property) {
                if ((string) $name === '' || strlen((string) $name) > 512) {
                    throw new InvalidArgumentException('Notion: nombre de propiedad inválido.');
                }
                $this->validateProperty($property);
            }
            $payload = ['properties' => $properties];
            $method = 'POST';
            $path = '/pages';
            if ($operation === 'create_page') {
                $parentType = $parameters['parent_type'] ?? 'data_source_id';
                if (!in_array($parentType, ['page_id', 'data_source_id'], true)) {
                    throw new InvalidArgumentException('Notion: parent_type debe ser page_id o data_source_id.');
                }
                $payload['parent'] = ['type' => $parentType, $parentType => $this->uuid($parameters['parent_id'] ?? null)];
                if ($parentType === 'page_id' && (array_keys((array) $properties) !== ['title']
                    || !property_exists($this->object($properties->title, false), 'title'))) {
                    throw new InvalidArgumentException('Notion: bajo una página solo se admite la propiedad title de tipo title.');
                }
            } else {
                $pageId = $this->uuid($parameters['page_id'] ?? null);
                $method = 'PATCH';
                $path .= '/' . $pageId;
            }
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 500000) {
            throw new InvalidArgumentException('Notion: la solicitud supera el límite local de 500000 bytes.');
        }
        return ['credential_id' => trim($credentialId), 'operation' => $operation, 'method' => $method,
            'path' => $path, 'payload' => $payload, 'page_id' => $pageId];
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")
            || preg_match('/{{\s*[A-Za-z0-9_.]+\s*}}/', $value)) {
            throw new InvalidArgumentException('Notion: se requiere texto UTF-8 con expresiones resueltas.');
        }
        return $value;
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})$/iD', trim($value)) !== 1) {
            throw new InvalidArgumentException('Notion: indica un ID UUID de página o fuente, no una URL.');
        }
        $value = strtolower(str_replace('-', '', trim($value)));
        return substr($value, 0, 8) . '-' . substr($value, 8, 4) . '-' . substr($value, 12, 4) . '-'
            . substr($value, 16, 4) . '-' . substr($value, 20);
    }

    private function plainData(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32 || is_resource($value) || (is_object($value) && !$value instanceof stdClass)
            || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException('Notion: los datos deben ser JSON con profundidad máxima de 32.');
        }
        if (is_string($value)) {
            $this->text($value);
        } elseif (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $this->text($key);
                }
                $this->plainData($item, $depth + 1);
            }
        }
        return $value;
    }

    private function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Notion: JSON inválido.');
            }
        }
        return $this->plainData($value);
    }

    private function object(mixed $value, bool $decode = true): stdClass
    {
        $value = $decode ? $this->decode($value) : $this->plainData($value);
        if ($value instanceof stdClass) {
            return $value;
        }
        if (is_array($value) && ($value === [] || !array_is_list($value))) {
            return (object) $value;
        }
        throw new InvalidArgumentException('Notion: se esperaba un objeto de propiedades, filtro u orden.');
    }

    private function listValue(mixed $value, bool $decode = true): array
    {
        $value = $decode ? $this->decode($value) : $this->plainData($value);
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Notion: se esperaba una lista JSON.');
        }
        return $value;
    }

    private function validateProperty(mixed $property): void
    {
        $property = $this->object($property, false);
        $keys = array_keys((array) $property);
        $types = array_values(array_intersect($keys, self::PROPERTY_TYPES));
        if (count($types) !== 1 || array_diff($keys, [...self::PROPERTY_TYPES, 'type']) !== []
            || (property_exists($property, 'type') && $property->type !== $types[0])) {
            throw new InvalidArgumentException('Notion: cada propiedad debe tener exactamente un tipo escribible compatible.');
        }
        $type = $types[0];
        $value = $property->{$type};
        if (in_array($type, ['title', 'rich_text', 'multi_select', 'people', 'relation', 'files'], true)) {
            $items = $this->listValue($value, false);
            if (count($items) > 100) {
                throw new InvalidArgumentException('Notion: una lista de propiedad admite como máximo 100 elementos.');
            }
            foreach ($items as $item) {
                $item = $this->object($item, false);
                if ((array) $item === []) {
                    throw new InvalidArgumentException('Notion: elemento vacío en una propiedad.');
                }
                if (in_array($type, ['title', 'rich_text'], true)) {
                    $richType = $item->type ?? (property_exists($item, 'text') ? 'text' : null);
                    if (!in_array($richType, ['text', 'mention', 'equation'], true) || !property_exists($item, $richType)) {
                        throw new InvalidArgumentException('Notion: rich text requiere text, mention o equation.');
                    }
                    $richValue = $this->object($item->{$richType}, false);
                    if (in_array($richType, ['text', 'equation'], true)) {
                        $text = $this->text($richValue->{$richType === 'text' ? 'content' : 'expression'} ?? null);
                        if (mb_strlen($text, 'UTF-8') > ($richType === 'equation' ? 1000 : 2000)) {
                            throw new InvalidArgumentException('Notion: cada fragmento de texto admite 2000 caracteres y cada ecuación 1000.');
                        }
                    } elseif ((array) $richValue === []) {
                        throw new InvalidArgumentException('Notion: mención vacía.');
                    }
                } elseif (in_array($type, ['people', 'relation'], true)) {
                    $this->uuid($item->id ?? null);
                } elseif ($type === 'multi_select') {
                    $this->validateSelection($item);
                } elseif ($type === 'files') {
                    if (!is_string($item->name ?? null) || trim($item->name) === ''
                        || (!property_exists($item, 'external') && !property_exists($item, 'file_upload'))) {
                        throw new InvalidArgumentException('Notion: archivo requiere nombre y external o file_upload.');
                    }
                }
            }
        } elseif ($type === 'checkbox' && !is_bool($value)) {
            throw new InvalidArgumentException('Notion: checkbox requiere un booleano.');
        } elseif ($type === 'number' && $value !== null && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Notion: number requiere un número o null.');
        } elseif (in_array($type, ['url', 'email', 'phone_number'], true) && $value !== null && !is_string($value)) {
            throw new InvalidArgumentException('Notion: url, email y phone_number requieren texto o null.');
        } elseif (in_array($type, ['url', 'email', 'phone_number'], true) && is_string($value)
            && mb_strlen($value, 'UTF-8') > ($type === 'url' ? 2000 : 200)) {
            throw new InvalidArgumentException('Notion: url admite 2000 caracteres; email y phone_number admiten 200.');
        } elseif (in_array($type, ['date', 'select', 'status'], true) && $value !== null) {
            $value = $this->object($value, false);
            if ((array) $value === []) {
                throw new InvalidArgumentException('Notion: usa null para vaciar date, select o status.');
            }
            if ($type === 'date') {
                if (trim($this->text($value->start ?? null)) === '') {
                    throw new InvalidArgumentException('Notion: date requiere start no vacío.');
                }
                foreach (['end', 'time_zone'] as $field) {
                    if (property_exists($value, $field) && $value->{$field} !== null) {
                        $this->text($value->{$field});
                    }
                }
            } else {
                $this->validateSelection($value);
            }
        }
    }

    private function validateSelection(stdClass $value): void
    {
        if (property_exists($value, 'id') === property_exists($value, 'name')) {
            throw new InvalidArgumentException('Notion: select/status requieren id o name, exclusivamente.');
        }
        if (trim($this->text($value->id ?? $value->name ?? null)) === '') {
            throw new InvalidArgumentException('Notion: selección vacía; usa null para quitarla.');
        }
    }

    private function validateFilter(stdClass $filter, int $depth = 0): void
    {
        if ($depth > 2) {
            throw new InvalidArgumentException('Notion: filtro compuesto demasiado profundo.');
        }
        $keys = array_keys((array) $filter);
        foreach (['and', 'or'] as $join) {
            if (property_exists($filter, $join)) {
                if ($keys !== [$join]) {
                    throw new InvalidArgumentException('Notion: no combines and/or con otros campos al mismo nivel.');
                }
                $parts = $this->listValue($filter->{$join}, false);
                if ($parts === [] || count($parts) > 100) {
                    throw new InvalidArgumentException('Notion: and/or requieren de 1 a 100 filtros.');
                }
                foreach ($parts as $part) {
                    $this->validateFilter($this->object($part, false), $depth + 1);
                }
                return;
            }
        }
        $types = [...self::PROPERTY_TYPES, 'created_by', 'created_time', 'last_edited_by', 'last_edited_time',
            'formula', 'rollup', 'unique_id', 'verification'];
        $conditions = array_values(array_intersect($keys, $types));
        if (count($conditions) !== 1 || array_diff($keys, [...$types, 'property', 'timestamp', 'type']) !== []
            || property_exists($filter, 'property') === property_exists($filter, 'timestamp')) {
            throw new InvalidArgumentException('Notion: filtro inválido; indica property o timestamp y una condición tipada.');
        }
        if (property_exists($filter, 'property') && trim($this->text($filter->property)) === '') {
            throw new InvalidArgumentException('Notion: propiedad de filtro vacía.');
        }
        if (property_exists($filter, 'timestamp') && (!in_array($filter->timestamp, ['created_time', 'last_edited_time'], true)
            || $filter->timestamp !== $conditions[0])) {
            throw new InvalidArgumentException('Notion: timestamp del filtro inválido.');
        }
        if (property_exists($filter, 'type') && $filter->type !== $conditions[0]) {
            throw new InvalidArgumentException('Notion: tipo de filtro incompatible.');
        }
        if ((array) $this->object($filter->{$conditions[0]}, false) === []) {
            throw new InvalidArgumentException('Notion: condición de filtro vacía.');
        }
    }

    private function normalizePage(mixed $page, bool $allowPartial = false): array
    {
        if (!is_array($page) || ($page['object'] ?? null) !== 'page'
            || (!$allowPartial && (!array_key_exists('url', $page) || !array_key_exists('properties', $page)))) {
            throw new InvalidArgumentException();
        }
        if ((array_key_exists('url', $page) && (!is_string($page['url']) || !filter_var($page['url'], FILTER_VALIDATE_URL)
                || parse_url($page['url'], PHP_URL_SCHEME) !== 'https' || parse_url($page['url'], PHP_URL_USER) !== null
                || parse_url($page['url'], PHP_URL_PASS) !== null))
            || (array_key_exists('properties', $page) && !is_array($page['properties']))) {
            throw new InvalidArgumentException();
        }
        $data = ['id' => $this->uuid($page['id'] ?? null), 'url' => $page['url'] ?? null,
            'properties' => array_key_exists('properties', $page) ? [] : null];
        foreach ($page['properties'] ?? [] as $name => $property) {
            if (!is_array($property) || !is_string($property['type'] ?? null) || !is_string($property['id'] ?? null)) {
                throw new InvalidArgumentException();
            }
            $type = $property['type'];
            if (!in_array($type, [...self::PROPERTY_TYPES, 'formula', 'rollup', 'created_time', 'last_edited_time',
                'created_by', 'last_edited_by', 'unique_id', 'verification', 'button', 'place'], true)) {
                continue;
            }
            if (!array_key_exists($type, $property)) {
                throw new InvalidArgumentException();
            }
            $data['properties'][$name] = ['id' => $property['id'], 'type' => $type, $type => $property[$type]];
        }
        foreach (['created_time', 'last_edited_time'] as $field) {
            if (isset($page[$field])) {
                if (!is_string($page[$field])) {
                    throw new InvalidArgumentException();
                }
                $data[$field] = $page[$field];
            }
        }
        return $data;
    }
}
