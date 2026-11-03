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

class ClickUpNode implements NodeInterface
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
            'type' => 'clickup', 'name' => 'ClickUp', 'category' => 'Community', 'icon' => 'CheckSquare', 'is_start' => false,
            'description' => 'Crea tareas y subtareas, actualiza estados y añade comentarios de ClickUp.',
            'help_text' => 'Credencial generic_bearer: API Key contiene token personal pk_… o access token OAuth sin prefijo Bearer. Personal usa Authorization directo; OAuth usa Bearer. Sólo IDs nativos de tarea (alfanuméricos), no IDs personalizados. list_id obligatorio: consulta la lista y sus estados antes de escribir y confirma que tarea/padre pertenece a esa lista de origen. No acepta tareas cuya lista sólo es secundaria. status debe existir en esa lista; si se omite al crear, ClickUp elige su estado inicial. Subtarea usa parent oficial y debe compartir lista. task_fields es objeto JSON opcional de description o markdown_content, priority (1–4 o null), assignees, tags, due_date, due_date_time, start_date, start_date_time, time_estimate y notify_all. No actualiza esos campos: update_status envía sólo status. Se exige respetar campos personalizados obligatorios al crear; listas que los requieren pueden rechazar la creación. Comentarios notifican a asignados/observadores según ClickUp incluso con notify_all=false. Sin reintentos automáticos ni paginación. task_fields admite JSON, objeto o expresión; tipos se validan tras evaluación del motor. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial ClickUp'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'create_task', 'options' => [
                    ['value' => 'create_task', 'label' => 'Crear tarea'], ['value' => 'update_status', 'label' => 'Actualizar estado'],
                    ['value' => 'add_comment', 'label' => 'Añadir comentario'], ['value' => 'create_subtask', 'label' => 'Crear subtarea']
                ]],
                'list_id' => ['type' => 'string', 'required' => true, 'label' => 'ID nativo de lista de origen'],
                'task_id' => ['type' => 'string', 'required' => false, 'label' => 'ID nativo de tarea (estado / comentario)'],
                'parent_task_id' => ['type' => 'string', 'required' => false, 'label' => 'ID nativo de tarea padre (subtarea)'],
                'name' => ['type' => 'string', 'required' => false, 'label' => 'Nombre de nueva tarea / subtarea'],
                'status' => ['type' => 'string', 'required' => false, 'label' => 'Estado existente en la lista (obligatorio actualizar)'],
                'task_fields' => ['type' => 'textarea', 'required' => false, 'label' => 'Campos opcionales al crear (objeto JSON)', 'placeholder' => '{"description":"Detalle","priority":3,"assignees":[183],"tags":["proyecto"]}'],
                'comment_text' => ['type' => 'textarea', 'required' => false, 'label' => 'Texto del comentario'],
                'notify_all' => ['type' => 'select', 'required' => false, 'label' => 'Notificar también al autor del comentario', 'default' => 'false', 'options' => [
                    ['value' => 'false', 'label' => 'No (otros asignados/observadores pueden recibir aviso)'], ['value' => 'true', 'label' => 'Sí']
                ]]
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
        // Engine resuelve ExpressionEvaluator una vez; no reinterpretar textos del usuario.
        $p = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($p['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('ClickUp: no se pudo recuperar o descifrar la credencial en p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('ClickUp: la credencial no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || !preg_match('/^[A-Za-z0-9._~+\/=\-]{8,2048}$/D', $token)) {
            throw new InvalidArgumentException('ClickUp: se requiere generic_bearer con token personal u OAuth sin prefijo ni JSON.');
        }
        $client = $this->client ?? new Client();
        $listId = $p['list_id'];
        [, $list] = $this->request($client, $token, 'GET', '/list/' . $listId);
        if (!$this->sameList($list['id'] ?? null, $listId)) {
            throw new RuntimeException('ClickUp: la respuesta no identifica la lista solicitada.');
        }
        $status = $p['status'];
        if ($status !== null) {
            if (!is_array($list['statuses'] ?? null) || !array_is_list($list['statuses']) || count($list['statuses']) > 1000) {
                throw new RuntimeException('ClickUp: no se pudieron verificar los estados de esta lista.');
            }
            $matches = [];
            foreach ($list['statuses'] as $item) {
                $name = is_array($item) ? ($item['status'] ?? null) : null;
                if (!is_string($name) || trim($name) === '' || !mb_check_encoding($name, 'UTF-8')) {
                    throw new RuntimeException('ClickUp: respuesta de estados de lista inválida.');
                }
                if (mb_strtolower($name, 'UTF-8') === mb_strtolower($status, 'UTF-8')) {
                    $matches[] = $name;
                }
            }
            if (count($matches) !== 1) {
                throw new InvalidArgumentException('ClickUp: el estado indicado no existe de forma inequívoca en esta lista.');
            }
            $status = $matches[0];
        }
        $operation = $p['operation'];
        $taskId = $p['task_id'];
        if ($taskId !== null) {
            [, $task] = $this->request($client, $token, 'GET', '/task/' . $taskId);
            if (($task['id'] ?? null) !== $taskId || !$this->sameList($task['list']['id'] ?? null, $listId)) {
                throw new InvalidArgumentException('ClickUp: la tarea o padre no pertenece a la lista de origen indicada.');
            }
        }
        if (in_array($operation, ['create_task', 'create_subtask'], true)) {
            $body = $p['body'];
            if ($status !== null) {
                $body['status'] = $status;
            }
            if ($operation === 'create_subtask') {
                $body['parent'] = $taskId;
            }
            [$code, $data] = $this->request($client, $token, 'POST', '/list/' . $listId . '/task', $body);
        } elseif ($operation === 'update_status') {
            [$code, $data] = $this->request($client, $token, 'PUT', '/task/' . $taskId, ['status' => $status]);
        } else {
            [$code, $data] = $this->request($client, $token, 'POST', '/task/' . $taskId . '/comment', $p['body']);
        }
        if ($operation === 'add_comment') {
            $id = $data['id'] ?? null;
            if ((!is_int($id) || $id <= 0) && (!is_string($id) || !preg_match('/^[1-9][0-9]{0,31}$/D', $id))) {
                throw new RuntimeException('ClickUp: respuesta sin ID de comentario válido; comprueba el resultado antes de repetir.');
            }
            $result = array_intersect_key($data, array_flip(['id', 'date']));
            $result['task_id'] = $taskId;
        } else {
            $id = $data['id'] ?? null;
            if (!$this->nativeTaskId($id) || ($operation === 'update_status' && $id !== $taskId)) {
                throw new RuntimeException('ClickUp: respuesta sin ID de tarea válido; comprueba el resultado antes de repetir.');
            }
            if ($operation === 'update_status') {
                $returnedStatus = $data['status']['status'] ?? null;
                if (!is_string($returnedStatus) || mb_strtolower($returnedStatus, 'UTF-8') !== mb_strtolower($status, 'UTF-8')) {
                    throw new RuntimeException('ClickUp: la respuesta no confirma el estado solicitado; comprueba el recurso antes de repetir.');
                }
            }
            if ($operation === 'create_subtask' && array_key_exists('parent', $data) && $data['parent'] !== $taskId) {
                throw new RuntimeException('ClickUp: la respuesta no confirma el padre solicitado; comprueba el recurso antes de repetir.');
            }
            if (isset($data['list']['id']) && !$this->sameList($data['list']['id'], $listId)) {
                throw new RuntimeException('ClickUp: la lista del resultado no coincide; comprueba el recurso antes de repetir.');
            }
            $result = array_intersect_key($data, array_flip(['id', 'name', 'status', 'parent', 'priority', 'assignees', 'tags', 'date_created', 'date_updated', 'due_date', 'start_date']));
            $result['list_id'] = $listId;
        }
        return ['success' => true, 'status_code' => $code, 'data' => $this->redact($result, $token)];
    }

    private function request(ClientInterface $client, string $token, string $method, string $path, ?array $body = null): array
    {
        $options = ['headers' => ['Authorization' => str_starts_with($token, 'pk_') ? $token : 'Bearer ' . $token, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'http_errors' => false, 'allow_redirects' => false, 'stream' => true];
        if ($body !== null) {
            $options['json'] = (object) $body;
        }
        try {
            $response = $client->request($method, 'https://api.clickup.com/api/v2' . $path, $options);
            $code = $response->getStatusCode();
            $stream = $response->getBody();
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= 2097152) {
                $chunk = $stream->read(min(8192, 2097153 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Lectura incompleta.');
                }
                $raw .= $chunk;
            }
            if (strlen($raw) > 2097152) {
                throw new RuntimeException('Respuesta demasiado grande.');
            }
        } catch (Throwable $e) {
            throw new RuntimeException($method === 'GET'
                ? 'ClickUp: fallo de transporte o lectura al verificar el recurso; no se realizó la escritura.'
                : 'ClickUp: fallo de transporte o lectura; resultado de escritura incierto. Comprueba ClickUp antes de repetir para evitar duplicados.');
        }
        if ($code < 200 || $code >= 300) {
            $category = match ($code) {
                401 => 'autenticación rechazada', 403 => 'permisos o Workspace no autorizados',
                404 => 'recurso inexistente o inaccesible', 429 => 'cuota o límite de solicitudes',
                default => 'solicitud rechazada por el proveedor'
            };
            throw new RuntimeException('ClickUp: ' . $category . ' (HTTP ' . $code . ').');
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('ClickUp: respuesta JSON inválida; comprueba el resultado antes de repetir una escritura.');
        }
        // Las operaciones soportadas documentan 200 con representación, no 204 vacío.
        if (!is_array($data) || $data === [] || array_is_list($data)
            || isset($data['err']) || isset($data['error']) || isset($data['ECODE']) || ($data['success'] ?? true) === false) {
            throw new RuntimeException('ClickUp: respuesta inválida o error funcional del proveedor.');
        }
        return [$code, $data];
    }

    private function prepare(array $p): array
    {
        $operation = $p['operation'] ?? 'create_task';
        if (!in_array($operation, ['create_task', 'update_status', 'add_comment', 'create_subtask'], true)) {
            throw new InvalidArgumentException('ClickUp: operación inválida.');
        }
        foreach (['custom_task_ids', 'team_id', 'custom_task_id'] as $unsupported) {
            if (array_key_exists($unsupported, $p)) {
                throw new InvalidArgumentException('ClickUp: esta versión sólo admite IDs nativos; no custom_task_ids ni team_id.');
            }
        }
        $credentialId = $this->text($p['credential_id'] ?? null, 255);
        $listId = (string) $this->integer($p['list_id'] ?? null, 1, 9007199254740991);
        $taskId = null;
        $status = null;
        $body = [];
        if (in_array($operation, ['update_status', 'add_comment', 'create_subtask'], true)) {
            $taskId = $p[$operation === 'create_subtask' ? 'parent_task_id' : 'task_id'] ?? null;
            if (!$this->nativeTaskId($taskId)) {
                throw new InvalidArgumentException('ClickUp: task_id o parent_task_id requiere un ID nativo alfanumérico, no un ID personalizado.');
            }
        }
        if ($operation === 'update_status') {
            $status = $this->text($p['status'] ?? null, 255);
        } elseif (in_array($operation, ['create_task', 'create_subtask'], true)) {
            $body = ['name' => $this->text($p['name'] ?? null, 255), 'check_required_custom_fields' => true];
            if (array_key_exists('status', $p) && $p['status'] !== '') {
                $status = $this->text($p['status'], 255);
            }
            if (array_key_exists('task_fields', $p) && $p['task_fields'] !== '') {
                $fields = $this->object($p['task_fields']);
                $allowed = ['description', 'markdown_content', 'priority', 'assignees', 'tags', 'due_date', 'due_date_time', 'start_date', 'start_date_time', 'time_estimate', 'notify_all'];
                if (array_diff(array_keys($fields), $allowed) !== [] || (array_key_exists('description', $fields) && array_key_exists('markdown_content', $fields))) {
                    throw new InvalidArgumentException('ClickUp: campos de creación no soportados o description y markdown_content simultáneos.');
                }
                foreach ($fields as $key => $value) {
                    if (in_array($key, ['description', 'markdown_content'], true)) {
                        $body[$key] = $this->text($value, 100000, true, true);
                    } elseif ($key === 'priority') {
                        $body[$key] = $value === null ? null : $this->integer($value, 1, 4);
                    } elseif (in_array($key, ['due_date', 'start_date', 'time_estimate'], true)) {
                        $body[$key] = $this->integer($value, 0, 9007199254740991);
                    } elseif (in_array($key, ['due_date_time', 'start_date_time', 'notify_all'], true)) {
                        if (!is_bool($value)) {
                            throw new InvalidArgumentException('ClickUp: indicadores dentro de task_fields requieren booleanos JSON.');
                        }
                        $body[$key] = $value;
                    } else {
                        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
                            throw new InvalidArgumentException('ClickUp: assignees/tags requieren listas de hasta 100 elementos.');
                        }
                        $items = array_map(fn($item) => $key === 'assignees'
                            ? $this->integer($item, 1, 9007199254740991) : $this->text($item, 255), $value);
                        if (count(array_unique($items)) !== count($items)) {
                            throw new InvalidArgumentException('ClickUp: no se permiten asignados o etiquetas duplicados.');
                        }
                        $body[$key] = $items;
                    }
                }
                foreach (['due', 'start'] as $date) {
                    if (array_key_exists($date . '_date_time', $fields) && !array_key_exists($date . '_date', $fields)) {
                        throw new InvalidArgumentException('ClickUp: indicador de hora requiere su fecha correspondiente.');
                    }
                }
                if (isset($body['start_date'], $body['due_date']) && $body['start_date'] > $body['due_date']) {
                    throw new InvalidArgumentException('ClickUp: start_date no puede ser posterior a due_date.');
                }
            }
        } else {
            $notify = $p['notify_all'] ?? 'false';
            if (!in_array($notify, [true, false, 'true', 'false'], true)) {
                throw new InvalidArgumentException('ClickUp: notify_all requiere true o false.');
            }
            $body = ['comment_text' => $this->text($p['comment_text'] ?? null, 100000, false, true),
                'notify_all' => $notify === true || $notify === 'true'];
        }
        return ['credential_id' => $credentialId, 'operation' => $operation, 'list_id' => $listId, 'task_id' => $taskId, 'status' => $status, 'body' => $body];
    }

    private function sameList(mixed $value, string $listId): bool
    {
        return (is_string($value) || is_int($value)) && (string) $value === $listId;
    }

    private function nativeTaskId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9]{1,64}$/D', $value) === 1;
    }

    private function object(mixed $value): array
    {
        if (is_string($value)) {
            if (strlen($value) > 200000) {
                throw new InvalidArgumentException('ClickUp: JSON de campos excede el límite local.');
            }
            try {
                $value = json_decode($value, false, 16, JSON_THROW_ON_ERROR);
                if (!$value instanceof stdClass) {
                    throw new InvalidArgumentException('Se requiere objeto JSON.');
                }
            } catch (Throwable $e) {
                throw new InvalidArgumentException('ClickUp: JSON de campos inválido.');
            }
        }
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('ClickUp: task_fields requiere un objeto JSON.');
        }
        return $value;
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,15})$/D', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('ClickUp: ID o valor numérico debe ser entero dentro del rango admitido.');
        }
        return $value;
    }

    private function text(mixed $value, int $max, bool $empty = false, bool $multiline = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > $max
            || (!$empty && trim($value) === '')
            || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('ClickUp: texto vacío, tipo incorrecto, UTF-8 inválido o límite excedido.');
        }
        return $value;
    }

    private function redact(mixed $value, string $token): mixed
    {
        if (is_string($value)) {
            return str_replace(array_unique([$token, rawurlencode($token), urlencode($token)]), '[REDACTED]', $value);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/token|secret|password|authorization|api.?key|url|link|email|profile.?picture|avatar|thumbnail/i', $key)) {
                    continue;
                }
                $result[is_string($key) ? $this->redact($key, $token) : $key] = $this->redact($item, $token);
            }
            return $result;
        }
        return $value;
    }
}
