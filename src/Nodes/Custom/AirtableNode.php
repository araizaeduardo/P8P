<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class AirtableNode implements NodeInterface
{
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
            'type' => 'airtable', 'name' => 'Airtable', 'category' => 'Community', 'icon' => 'Table',
            'is_start' => false, 'description' => 'Crea y actualiza registros; lista una página de una tabla de Airtable.',
            'help_text' => 'Credencial generic_bearer: API Key = Personal Access Token vigente con acceso a la base y scopes data.records:read/write. fields acepta objeto o JSON; PATCH conserva campos omitidos y envía null, false, [] y texto vacío explícitos. Lista hasta 100 registros por ejecución; reutiliza data.offset para continuar. No renueva tokens. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Airtable (PAT)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'create_record', 'options' => [
                    ['value' => 'create_record', 'label' => 'Crear registro'],
                    ['value' => 'update_record', 'label' => 'Actualizar registro (PATCH)'],
                    ['value' => 'list_records', 'label' => 'Listar registros (una página)']
                ]],
                'base_id' => ['type' => 'string', 'required' => true, 'label' => 'ID de base (app...)'],
                'table' => ['type' => 'string', 'required' => true, 'label' => 'ID (tbl...) o nombre exacto de tabla'],
                'record_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de registro a actualizar (rec...)'],
                'fields' => ['type' => 'textarea', 'required' => false, 'label' => 'Campos (objeto JSON, crear/actualizar)', 'placeholder' => '{"Nombre":"Ejemplo","Activo":false}'],
                'page_size' => ['type' => 'number', 'required' => false, 'label' => 'Tamaño de página (1–100)', 'default' => 100],
                'offset' => ['type' => 'string', 'required' => false, 'label' => 'Cursor offset (listar)'],
                'view' => ['type' => 'string', 'required' => false, 'label' => 'Vista ID/nombre (listar, opcional)']
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepare($parameters);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        [$operation, $method, $url, $requestData] = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted(trim($parameters['credential_id']));
        } catch (\Throwable $e) {
            throw new RuntimeException('Airtable: no se pudo recuperar o descifrar la credencial.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Airtable: credencial inexistente.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || preg_match('/^pat[A-Za-z0-9._-]+$/D', $token) !== 1) {
            throw new InvalidArgumentException('Airtable: usa generic_bearer con un Personal Access Token vigente en API Key.');
        }
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false,
            $method === 'GET' ? 'query' : 'json' => $requestData
        ];
        try {
            $response = ($this->client ?? new Client())->request($method, $url, $options);
            $status = $response->getStatusCode();
            // Las páginas son acotadas; evitar materializar una respuesta arbitrariamente grande.
            $stream = $response->getBody();
            $raw = $stream->read(2097153);
            if (!$stream->eof() || strlen($raw) > 2097152) {
                throw new RuntimeException('oversize');
            }
        } catch (\Throwable $e) {
            $message = $operation === 'list_records' ? 'fallo de transporte o respuesta demasiado grande.'
                : 'fallo de transporte o respuesta demasiado grande; resultado de escritura incierto. Comprueba Airtable antes de reintentar.';
            throw new RuntimeException('Airtable: ' . $message);
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'autenticación rechazada', 403 => 'permisos, scopes o acceso a la base insuficientes',
                404 => 'base, tabla o registro inexistente o inaccesible', 422 => 'campos o tipos incompatibles con la tabla',
                429 => 'cuota alcanzada; espera antes de reintentar', default => 'solicitud rechazada'
            };
            throw new RuntimeException("Airtable: {$reason} (HTTP {$status}). No se reintentó la operación.");
        }
        try {
            $body = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('Airtable: respuesta JSON inválida; verifica el resultado antes de reintentar una escritura.');
        }
        if (!$body instanceof \stdClass || property_exists($body, 'error') || property_exists($body, 'errors')) {
            throw new RuntimeException('Airtable: error funcional o respuesta inválida.');
        }
        if ($operation === 'list_records') {
            if (!is_array($body->records ?? null) || count($body->records) > $requestData['pageSize']) {
                throw new RuntimeException('Airtable: respuesta de registros inválida.');
            }
            $records = [];
            foreach ($body->records as $record) {
                $records[] = $this->record($record, $token);
            }
            $offset = $body->offset ?? null;
            if ($offset !== null && !$this->text($offset, 2048, 1)) {
                throw new RuntimeException('Airtable: cursor de respuesta inválido.');
            }
            return ['success' => true, 'status_code' => $status, 'data' => [
                'records' => $records, 'offset' => $offset === null ? null : str_replace($token, '[REDACTED]', $offset),
                'has_more' => $offset !== null
            ]];
        }
        return ['success' => true, 'status_code' => $status, 'data' => $this->record($body, $token)];
    }

    private function prepare(array $p): array
    {
        if (!is_string($p['credential_id'] ?? null) || trim($p['credential_id']) === '') {
            throw new InvalidArgumentException('Airtable: selecciona una credencial.');
        }
        $operation = $p['operation'] ?? 'create_record';
        if (!in_array($operation, ['create_record', 'update_record', 'list_records'], true)
            || !$this->id($p['base_id'] ?? null, 'app') || !$this->text($p['table'] ?? null, 256, 1)
            || trim($p['table']) === '' || in_array($p['table'], ['.', '..'], true)) {
            throw new InvalidArgumentException('Airtable: operación, ID de base o tabla inválidos.');
        }
        $url = 'https://api.airtable.com/v0/' . $p['base_id'] . '/' . rawurlencode($p['table']);
        if ($operation === 'list_records') {
            $size = $p['page_size'] ?? 100;
            if (is_string($size) && ctype_digit($size)) {
                $size = strlen($size) <= 3 ? (int) $size : 0;
            }
            if (!is_int($size) || $size < 1 || $size > 100) {
                throw new InvalidArgumentException('Airtable: page_size debe ser un entero entre 1 y 100.');
            }
            $query = ['pageSize' => $size];
            foreach (['offset' => 2048, 'view' => 256] as $key => $limit) {
                if (array_key_exists($key, $p) && $p[$key] !== '') {
                    if (!$this->text($p[$key], $limit, 1)) {
                        throw new InvalidArgumentException('Airtable: cursor o vista inválidos.');
                    }
                    $query[$key] = $p[$key];
                }
            }
            return [$operation, 'GET', $url, $query];
        }
        if ($operation === 'update_record') {
            if (!$this->id($p['record_id'] ?? null, 'rec')) {
                throw new InvalidArgumentException('Airtable: record_id inválido.');
            }
            $url .= '/' . $p['record_id'];
        }
        $fields = $p['fields'] ?? null;
        if (is_string($fields)) {
            if (strlen($fields) > 1048576) {
                throw new InvalidArgumentException('Airtable: fields supera 1 MiB.');
            }
            try {
                $fields = json_decode($fields, false, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Airtable: fields debe contener JSON válido.');
            }
        }
        if ($fields instanceof \stdClass) {
            $fields = (array) $fields;
        }
        if (!is_array($fields) || $fields === [] || array_is_list($fields)) {
            throw new InvalidArgumentException('Airtable: fields debe ser un objeto no vacío de campos.');
        }
        foreach ($fields as $key => $value) {
            if (!$this->text($key, 256, 1)) {
                throw new InvalidArgumentException('Airtable: nombre/ID de campo inválido.');
            }
            $this->jsonCompatible($value, 0);
        }
        try {
            if (strlen(json_encode($fields, JSON_THROW_ON_ERROR)) > 1048576) {
                throw new RuntimeException('oversize');
            }
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Airtable: fields contiene tipos inválidos o supera 1 MiB.');
        }
        return [$operation, $operation === 'create_record' ? 'POST' : 'PATCH', $url,
            ['fields' => (object) $fields, 'typecast' => false]];
    }

    private function id(mixed $id, string $prefix): bool
    {
        return is_string($id) && preg_match('/^' . $prefix . '[A-Za-z0-9]{14}$/D', $id) === 1;
    }

    private function text(mixed $value, int $max, int $min = 0): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') >= $min && mb_strlen($value, 'UTF-8') <= $max
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

    private function jsonCompatible(mixed $value, int $depth): void
    {
        if ($depth > 32 || (is_object($value) && !$value instanceof \stdClass)
            || is_resource($value) || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException('Airtable: valor JSON incompatible.');
        }
        if (is_array($value) || $value instanceof \stdClass) {
            foreach ($value as $child) {
                $this->jsonCompatible($child, $depth + 1);
            }
        }
    }

    private function record(mixed $record, string $token): array
    {
        if (!$record instanceof \stdClass || !$this->id($record->id ?? null, 'rec')
            || !($record->fields ?? null) instanceof \stdClass) {
            throw new RuntimeException('Airtable: estructura de registro inválida.');
        }
        // Solo datos documentados; no propagar headers ni campos remotos de autenticación.
        $data = ['id' => $record->id, 'fields' => $this->redact((array) $record->fields, $token)];
        if (isset($record->createdTime)) {
            if (!is_string($record->createdTime)) {
                throw new RuntimeException('Airtable: fecha de registro inválida.');
            }
            $data['created_time'] = str_replace($token, '[REDACTED]', $record->createdTime);
        }
        return $data;
    }

    private function redact(mixed $value, string $token): mixed
    {
        if (is_string($value)) {
            return str_replace($token, '[REDACTED]', $value);
        }
        if (is_array($value) || $value instanceof \stdClass) {
            $result = [];
            foreach ($value as $key => $child) {
                $result[is_string($key) ? str_replace($token, '[REDACTED]', $key) : $key] = $this->redact($child, $token);
            }
            return $value instanceof \stdClass ? (object) $result : $result;
        }
        return $value;
    }
}
