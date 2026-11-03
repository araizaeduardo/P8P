<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use stdClass;

class SalesforceNode implements NodeInterface
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
            'type' => 'salesforce', 'name' => 'Salesforce', 'category' => 'Community',
            'icon' => 'Cloud', 'is_start' => false,
            'description' => 'Crea Leads/Oportunidades, actualiza cuentas y consulta SOQL fijo con paginación.',
            'help_text' => 'generic_bearer: API Key contiene JSON {"instance_url":"https://empresa.my.salesforce.com","api_version":"v66.0","access_token":"TOKEN","queries":{"leads":"SELECT Id,Name FROM Lead LIMIT 200"}}. Usa instance_url devuelto por OAuth y una versión admitida por tu organización. Scope api y permisos CRUD/campos del usuario. Token caduca: renovar manualmente. fields es objeto JSON o expresión resuelta; solo envía claves proporcionadas; null/"" son vaciados explícitos sujetos al esquema remoto. Lead requiere LastName/Company; Opportunity Name/StageName/CloseDate. query usa query_name de queries fijo EN CREDENCIAL: el workflow no acepta SOQL ni valores interpolados ni parámetros enlazados REST. Escapes SOQL en consultas fijas: apóstrofo con barra invertida, barra invertida duplicada; Guzzle codifica q. batch_size 200–2000 es orientativo del proveedor; LIMIT en consulta fija limita total. Una página por ejecución, next_cursor de misma credencial/consulta; no sigue todas las páginas. Sin reintentos automáticos. Las escrituras con fallo de transporte pueden haberse realizado. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Salesforce (JSON en API Key)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'create_lead', 'options' => [
                    ['value' => 'create_lead', 'label' => 'Crear Lead'], ['value' => 'create_opportunity', 'label' => 'Crear Oportunidad'],
                    ['value' => 'update_account', 'label' => 'Actualizar Cuenta'], ['value' => 'query', 'label' => 'Consultar SOQL fijo de credencial']
                ]],
                'fields' => ['type' => 'textarea', 'label' => 'Crear/actualizar: objeto JSON de campos', 'placeholder' => '{"LastName":"Pérez","Company":"Ejemplo"}'],
                'account_id' => ['type' => 'string', 'label' => 'Actualizar: ID de Cuenta (15 o 18 caracteres)'],
                'query_name' => ['type' => 'string', 'label' => 'Consultar: nombre de consulta fija en credencial'],
                'batch_size' => ['type' => 'number', 'label' => 'Consultar: tamaño orientativo de página (200–2000)', 'default' => 200],
                'cursor' => ['type' => 'textarea', 'label' => 'Consultar: data.next_cursor de página anterior']
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
        // El motor resuelve expresiones en fields; SOQL fijo procede solo de credenciales.
        $p = $this->prepare($parameters);
        $config = $this->configuration($p['credential_id']);
        $root = '/services/data/' . $config['api_version'];
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $config['access_token'], 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false
        ];
        if ($p['operation'] === 'query') {
            $query = $config['queries'][$p['query_name']] ?? null;
            if (!is_string($query) || strlen($query) > 20000 || preg_match('/^\s*SELECT\s+/i', $query) !== 1
                || !mb_check_encoding($query, 'UTF-8') || str_contains($query, "\0") || str_contains($query, '{{') || str_contains($query, '}}')) {
                throw new InvalidArgumentException('Salesforce: consulta fija inexistente o inválida en la credencial; usa SELECT sin expresiones del workflow.');
            }
            $options['headers']['Sforce-Query-Options'] = 'batchSize=' . $p['batch_size'];
            if ($p['cursor'] !== '') {
                $url = $this->cursorUrl($p['cursor'], $config['instance_url'], $root);
            } else {
                $url = $config['instance_url'] . $root . '/query';
                $options['query'] = ['q' => $query];
            }
            $method = 'GET';
        } else {
            $object = match ($p['operation']) { 'create_lead' => 'Lead', 'create_opportunity' => 'Opportunity', default => 'Account' };
            $method = $p['operation'] === 'update_account' ? 'PATCH' : 'POST';
            $url = $config['instance_url'] . $root . '/sobjects/' . $object
                . ($method === 'PATCH' ? '/' . $p['account_id'] : '');
            $options['json'] = (object) $p['fields'];
        }
        $notice = $method === 'GET' ? '' : ' Resultado incierto: la escritura puede haberse realizado; revisa Salesforce antes de repetir.';
        try {
            $response = ($this->client ?? new Client())->request($method, $url, $options);
            $status = $response->getStatusCode();
            $raw = '';
            $stream = $response->getBody();
            while (!$stream->eof() && strlen($raw) <= 8 * 1024 * 1024) {
                $chunk = $stream->read(8192);
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Salesforce: fallo de transporte o lectura.' . $notice);
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'token inválido o caducado; renueva la credencial manualmente',
                403 => 'permisos insuficientes o cuota; revisa API Enabled, CRUD, campos y límites de organización',
                404 => 'registro, recurso o versión API inexistente o inaccesible',
                429 => 'límite de solicitudes; espera antes de repetir',
                400 => 'campos, consulta fija o cursor rechazados por Salesforce',
                default => 'el servicio rechazó la solicitud'
            };
            throw new RuntimeException("Salesforce: {$reason} (HTTP {$status})." . ($status >= 500 ? $notice : ''), $status);
        }
        $invalid = 'Salesforce: respuesta inválida o incompleta.' . $notice;
        if (strlen($raw) > 8 * 1024 * 1024) {
            throw new RuntimeException($invalid, $status);
        }
        if ($method === 'PATCH') {
            if ($status !== 204 || trim($raw) !== '') {
                throw new RuntimeException($invalid, $status);
            }
            return ['success' => true, 'status_code' => $status, 'data' => ['id' => $p['account_id'], 'updated' => true]];
        }
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body) || array_is_list($body)
            || isset($body['error']) || isset($body['errorCode']) || ($body['success'] ?? null) === false) {
            throw new RuntimeException($invalid, $status);
        }
        if ($method === 'POST') {
            if ($status !== 201 || !$this->recordId($body['id'] ?? null) || ($body['success'] ?? null) !== true || ($body['errors'] ?? null) !== []) {
                throw new RuntimeException($invalid, $status);
            }
            $data = ['id' => $body['id'], 'created' => true];
        } else {
            $shape = json_decode($raw);
            if (!is_array($shape->records ?? null) || $status !== 200 || !is_int($body['totalSize'] ?? null) || $body['totalSize'] < 0 || !is_bool($body['done'] ?? null)
                || !is_array($body['records'] ?? null) || !array_is_list($body['records']) || count($body['records']) > 2000) {
                throw new RuntimeException($invalid, $status);
            }
            unset($shape);
            foreach ($body['records'] as $record) {
                if (!is_array($record) || array_is_list($record)) {
                    throw new RuntimeException($invalid, $status);
                }
            }
            $cursor = null;
            if (!$body['done']) {
                try {
                    $cursor = $this->cursorUrl($body['nextRecordsUrl'] ?? null, $config['instance_url'], $root);
                } catch (\Throwable $e) {
                    throw new RuntimeException('Salesforce: cursor de respuesta inválido.', $status);
                }
            } elseif (isset($body['nextRecordsUrl'])) {
                throw new RuntimeException($invalid, $status);
            }
            $data = ['records' => $body['records'], 'total_size' => $body['totalSize'], 'done' => $body['done'], 'next_cursor' => $cursor];
        }
        if (str_contains(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $config['access_token'])) {
            throw new RuntimeException($invalid, $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        $id = $this->text($p['credential_id'] ?? null, true, 200);
        $op = array_key_exists('operation', $p) ? $p['operation'] : 'create_lead';
        if (!is_string($op) || !in_array($op, ['create_lead', 'create_opportunity', 'update_account', 'query'], true)) {
            throw new InvalidArgumentException('Salesforce: operación inválida.');
        }
        if (array_key_exists('soql', $p) || array_key_exists('query_values', $p)) {
            throw new InvalidArgumentException('Salesforce: SOQL dinámico y parámetros enlazados REST no admitidos; usa query_name de credencial.');
        }
        $r = ['credential_id' => $id, 'operation' => $op];
        if ($op === 'query') {
            $name = $p['query_name'] ?? null;
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name) !== 1) {
                throw new InvalidArgumentException('Salesforce: nombre de consulta fija inválido.');
            }
            $r['query_name'] = $name;
            $batch = array_key_exists('batch_size', $p) ? $p['batch_size'] : 200;
            if (is_string($batch) && preg_match('/^[0-9]{3,4}$/D', $batch)) {
                $batch = (int) $batch;
            }
            if (!is_int($batch) || $batch < 200 || $batch > 2000) {
                throw new InvalidArgumentException('Salesforce: batch_size entero entre 200 y 2000.');
            }
            $r['batch_size'] = $batch;
            $r['cursor'] = $this->text(array_key_exists('cursor', $p) ? $p['cursor'] : '', false, 2000);
        } else {
            $fields = $p['fields'] ?? null;
            if (is_string($fields)) {
                try {
                    $fields = json_decode($fields, false, 32, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    throw new InvalidArgumentException('Salesforce: fields debe ser objeto JSON válido.');
                }
            }
            if ($fields instanceof stdClass) {
                $fields = (array) $fields;
            }
            if (!is_array($fields) || array_is_list($fields) || count($fields) > 200) {
                throw new InvalidArgumentException('Salesforce: fields exige objeto no vacío de hasta 200 campos.');
            }
            $total = 0;
            foreach ($fields as $field => $value) {
                if (!is_string($field) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/D', $field) !== 1
                    || in_array(strtolower($field), ['id', 'attributes'], true) || (!is_scalar($value) && $value !== null)
                    || (is_float($value) && !is_finite($value))) {
                    throw new InvalidArgumentException('Salesforce: nombre de campo o valor escalar inválido.');
                }
                if (is_string($value)) {
                    $this->text($value, false, 1000000);
                    $total += strlen($value);
                }
            }
            if ($total > 1000000) {
                throw new InvalidArgumentException('Salesforce: fields excede 1 MB.');
            }
            $required = $op === 'create_lead' ? ['LastName', 'Company'] : ($op === 'create_opportunity' ? ['Name', 'StageName', 'CloseDate'] : []);
            foreach ($required as $field) {
                $this->text($fields[$field] ?? null, true, 10000);
            }
            if ($op === 'create_opportunity') {
                $date = $fields['CloseDate'];
                if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) !== 1
                    || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                    throw new InvalidArgumentException('Salesforce: CloseDate requiere fecha real YYYY-MM-DD.');
                }
            }
            if ($op === 'update_account') {
                if (!$this->recordId($p['account_id'] ?? null) || !str_starts_with($p['account_id'], '001')) {
                    throw new InvalidArgumentException('Salesforce: account_id debe ser ID de Cuenta (001, 15 o 18 caracteres).');
                }
                $r['account_id'] = $p['account_id'];
            }
            $r['fields'] = $fields;
        }
        return $r;
    }

    private function configuration(string $id): array
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('Salesforce: fallo del gestor al recuperar o descifrar la credencial.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Salesforce: credencial inexistente.');
        }
        $raw = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($raw)) {
            throw new InvalidArgumentException('Salesforce: usa generic_bearer con JSON en API Key.');
        }
        $config = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($config) || !is_string($config['access_token'] ?? null)
            || strlen($config['access_token']) < 8 || preg_match('/^[A-Za-z0-9._~+\/!\-=]+$/D', $config['access_token']) !== 1
            || !is_string($config['api_version'] ?? null) || preg_match('/^v[0-9]{2,3}\.0$/D', $config['api_version']) !== 1
            || !is_string($config['instance_url'] ?? null)) {
            throw new InvalidArgumentException('Salesforce: configuración JSON inválida (access_token, api_version, instance_url).');
        }
        $url = rtrim($config['instance_url'], '/');
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+salesforce\.com$/D', $parts['host'] ?? '') !== 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new InvalidArgumentException('Salesforce: instance_url requiere origen HTTPS de instancia *.salesforce.com, sin ruta ni puerto.');
        }
        $config['instance_url'] = $url;
        if (isset($config['queries']) && (!is_array($config['queries']) || (array_is_list($config['queries']) && $config['queries'] !== []))) {
            throw new InvalidArgumentException('Salesforce: queries debe ser un objeto de consultas fijas por nombre.');
        }
        return $config;
    }

    private function cursorUrl(mixed $cursor, string $origin, string $root): string
    {
        if (!is_string($cursor) || strlen($cursor) > 2000 || preg_match('/[\x00-\x20\x7f\\\\]/', $cursor)) {
            throw new InvalidArgumentException('Salesforce: cursor inválido.');
        }
        $path = str_starts_with($cursor, $origin . '/') ? substr($cursor, strlen($origin)) : $cursor;
        if (preg_match('~^' . preg_quote($root, '~') . '/query/[A-Za-z0-9_-]{1,256}$~D', $path) !== 1) {
            throw new InvalidArgumentException('Salesforce: origen, versión o ruta del cursor no permitidos.');
        }
        return $origin . $path;
    }

    private function recordId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^(?:[A-Za-z0-9]{15}|[A-Za-z0-9]{18})$/D', $id) === 1;
    }

    private function text(mixed $value, bool $required, int $max): string
    {
        if (!is_string($value) || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8')
            || str_contains($value, "\0") || ($required && trim($value) === '')) {
            throw new InvalidArgumentException('Salesforce: texto inválido, requerido o fuera de límite.');
        }
        return $value;
    }
}
