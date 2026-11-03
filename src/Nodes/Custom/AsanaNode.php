<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class AsanaNode implements NodeInterface
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
            'type' => 'asana', 'name' => 'Asana', 'category' => 'Community', 'icon' => 'CheckCircle',
            'is_start' => false, 'description' => 'Crea tareas, actualiza campos o finalización y añade comentarios.',
            'help_text' => 'Credencial generic_bearer: API Key = PAT o access token vigente. Scopes OAuth tasks:write / stories:write y acceso al recurso. fields acepta JSON u objeto con name, notes, assignee, completed, due_on, custom_fields; projects solo al crear. completed es booleano; un estado personalizado se envía en custom_fields por GID del campo y opción. PUT solo modifica campos proporcionados; null limpia assignee/due_on/custom fields. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Asana'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'create_task', 'options' => [
                    ['value' => 'create_task', 'label' => 'Crear tarea'],
                    ['value' => 'update_task', 'label' => 'Actualizar tarea/finalización'],
                    ['value' => 'add_comment', 'label' => 'Añadir comentario']
                ]],
                'workspace_id' => ['type' => 'string', 'required' => false, 'label' => 'GID workspace (crear)'],
                'task_id' => ['type' => 'string', 'required' => false, 'label' => 'GID tarea (actualizar/comentar)'],
                'fields' => ['type' => 'textarea', 'required' => false, 'label' => 'Campos de tarea (JSON)', 'placeholder' => '{"name":"Nueva tarea","completed":false}'],
                'text' => ['type' => 'textarea', 'required' => false, 'label' => 'Texto del comentario']
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
        [$operation, $method, $path, $data] = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted(trim($parameters['credential_id']));
        } catch (\Throwable $e) {
            throw new RuntimeException('Asana: no se pudo recuperar o descifrar la credencial.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Asana: credencial inexistente.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || preg_match('/^[A-Za-z0-9._:\/-]+$/D', $token) !== 1) {
            throw new InvalidArgumentException('Asana: usa generic_bearer con un PAT o access token vigente en API Key.');
        }
        try {
            $response = ($this->client ?? new Client())->request($method, 'https://app.asana.com/api/1.0/' . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'json' => ['data' => $data],
                'query' => ['opt_fields' => $operation === 'add_comment' ? 'gid,resource_type' : 'gid,resource_type,completed'],
                'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
                'allow_redirects' => false, 'http_errors' => false
            ]);
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
        } catch (\Throwable $e) {
            throw new RuntimeException('Asana: fallo de transporte; la escritura puede haber ocurrido. Comprueba Asana antes de reintentar.');
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'autenticación rechazada', 403 => 'permisos o scopes insuficientes',
                404 => 'recurso inexistente o inaccesible', 429 => 'cuota alcanzada; espera antes de reintentar',
                default => 'solicitud rechazada; verifica el resultado antes de reintentar'
            };
            throw new RuntimeException("Asana: {$reason} (HTTP {$status}).");
        }
        $body = json_decode($raw, true);
        $result = is_array($body) ? ($body['data'] ?? null) : null;
        $expectedStatus = $operation === 'update_task' ? 200 : 201;
        if ($status !== $expectedStatus || !is_array($body) || array_key_exists('errors', $body)
            || !is_array($result) || !$this->gid($result['gid'] ?? null)
            || ($result['resource_type'] ?? null) !== ($operation === 'add_comment' ? 'story' : 'task')) {
            throw new RuntimeException('Asana: respuesta inválida o error funcional; verifica el resultado antes de reintentar.');
        }
        $output = ['gid' => $result['gid'], 'resource_type' => $result['resource_type']];
        if ($operation !== 'add_comment' && array_key_exists('completed', $result)) {
            if (!is_bool($result['completed'])) {
                throw new RuntimeException('Asana: finalización inválida en respuesta.');
            }
            $output['completed'] = $result['completed'];
        }
        // Evitar que texto remoto reflejado o URLs acaben en historial con secretos.
        return ['success' => true, 'status_code' => $status, 'data' => $output];
    }

    private function prepare(array $p): array
    {
        if (!is_string($p['credential_id'] ?? null) || trim($p['credential_id']) === '') {
            throw new InvalidArgumentException('Asana: selecciona una credencial.');
        }
        $operation = $p['operation'] ?? 'create_task';
        if (!in_array($operation, ['create_task', 'update_task', 'add_comment'], true)) {
            throw new InvalidArgumentException('Asana: operación inválida.');
        }
        if ($operation !== 'create_task' && !$this->gid($p['task_id'] ?? null)) {
            throw new InvalidArgumentException('Asana: task_id debe ser un GID textual válido.');
        }
        if ($operation === 'add_comment') {
            if (!$this->text($p['text'] ?? null, 65000, 1) || trim($p['text']) === '') {
                throw new InvalidArgumentException('Asana: el comentario requiere texto UTF-8 no vacío (hasta 65000 caracteres).');
            }
            return [$operation, 'POST', 'tasks/' . $p['task_id'] . '/stories', ['text' => $p['text']]];
        }
        $data = $this->objectValue($p['fields'] ?? null);
        if ($data === [] || array_diff(array_keys($data), ['name', 'notes', 'assignee', 'completed', 'due_on', 'custom_fields', 'projects']) !== []) {
            throw new InvalidArgumentException('Asana: indica campos válidos; completed y custom_fields son conceptos diferentes.');
        }
        foreach (['name' => 1000, 'notes' => 65000] as $key => $limit) {
            if (array_key_exists($key, $data) && !$this->text($data[$key], $limit, $key === 'name' ? 1 : 0)) {
                throw new InvalidArgumentException('Asana: nombre o notas inválidos.');
            }
        }
        if (array_key_exists('completed', $data) && !is_bool($data['completed'])) {
            throw new InvalidArgumentException('Asana: completed debe ser booleano.');
        }
        if (array_key_exists('assignee', $data) && $data['assignee'] !== null
            && $data['assignee'] !== 'me' && !$this->gid($data['assignee'])) {
            throw new InvalidArgumentException('Asana: assignee debe ser un GID, me o null para desasignar.');
        }
        if (array_key_exists('due_on', $data) && $data['due_on'] !== null) {
            $date = $data['due_on'];
            if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1
                || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw new InvalidArgumentException('Asana: due_on debe ser fecha YYYY-MM-DD válida o null.');
            }
        }
        if (array_key_exists('custom_fields', $data)) {
            $custom = $this->objectValue($data['custom_fields']);
            foreach ($custom as $id => $value) {
                if (!$this->gid((string) $id) || (!$this->text($value, 65000) && $value !== null
                    && !is_int($value) && !(is_float($value) && is_finite($value)) && !is_array($value))) {
                    throw new InvalidArgumentException('Asana: custom_fields requiere GID de campo y valor texto/número/null o lista de GIDs de opciones.');
                }
                if (is_array($value)) {
                    $this->gidList($value);
                }
            }
            $data['custom_fields'] = (object) $custom;
        }
        if ($operation === 'create_task') {
            if (!$this->gid($p['workspace_id'] ?? null) || !isset($data['name']) || trim($data['name']) === '') {
                throw new InvalidArgumentException('Asana: crear requiere workspace_id y fields.name no vacío.');
            }
            $data['workspace'] = $p['workspace_id'];
            if (array_key_exists('projects', $data)) {
                $this->gidList($data['projects']);
            }
        } elseif (array_key_exists('projects', $data)) {
            throw new InvalidArgumentException('Asana: projects solo se admite al crear; los cambios de pertenencia necesitan endpoints específicos.');
        }
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new InvalidArgumentException('Asana: fields supera 1 MiB.');
        }
        return [$operation, $operation === 'create_task' ? 'POST' : 'PUT',
            $operation === 'create_task' ? 'tasks' : 'tasks/' . $p['task_id'], $data];
    }

    private function objectValue(mixed $value): array
    {
        if (is_string($value)) {
            if (strlen($value) > 1048576) {
                throw new InvalidArgumentException('Asana: JSON supera 1 MiB.');
            }
            try {
                $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Asana: JSON inválido.');
            }
        }
        if ($value instanceof \stdClass) {
            return (array) $value;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Asana: fields/custom_fields debe ser un objeto JSON.');
        }
        return $value;
    }

    private function text(mixed $value, int $max, int $min = 0): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') >= $min && mb_strlen($value, 'UTF-8') <= $max;
    }

    private function gid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,39}$/D', $value) === 1;
    }

    private function gidList(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new InvalidArgumentException('Asana: se requiere lista de hasta 100 GIDs.');
        }
        foreach ($value as $gid) {
            if (!$this->gid($gid)) {
                throw new InvalidArgumentException('Asana: GID inválido en lista.');
            }
        }
    }
}
