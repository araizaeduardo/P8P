<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class LinearNode implements NodeInterface
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
            'type' => 'linear', 'name' => 'Linear', 'category' => 'Community', 'icon' => 'CircleDot',
            'is_start' => false, 'description' => 'Crea issues, cambia su estado y asigna o retira un responsable.',
            'help_text' => 'Credencial generic_bearer, API Key = JSON {"auth_type":"api_key","token":"..."} o {"auth_type":"oauth","token":"..."}. API key usa Authorization directo; OAuth Bearer. Scopes OAuth issues:create para crear, write para cambios; acceso al equipo. assignee_id admite un UUID o null JSON explícito para desasignar; solo un responsable. GraphQL fijo con variables. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Linear'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'create_issue', 'options' => [
                    ['value' => 'create_issue', 'label' => 'Crear issue'],
                    ['value' => 'update_state', 'label' => 'Cambiar estado'],
                    ['value' => 'assign_issue', 'label' => 'Asignar/desasignar responsable']
                ]],
                'team_id' => ['type' => 'string', 'required' => false, 'label' => 'UUID del equipo (crear)'],
                'title' => ['type' => 'string', 'required' => false, 'label' => 'Título (crear, hasta 1000 caracteres)'],
                'fields' => ['type' => 'textarea', 'required' => false, 'label' => 'Opciones de creación (JSON, opcional)', 'placeholder' => '{"description":"Markdown","priority":2,"state_id":"UUID","assignee_id":null,"label_ids":[]}'],
                'issue_id' => ['type' => 'string', 'required' => false, 'label' => 'UUID o identificador del issue (ENG-123)'],
                'state_id' => ['type' => 'string', 'required' => false, 'label' => 'UUID del estado del equipo (cambiar estado)'],
                'assignee_id' => ['type' => 'textarea', 'required' => false, 'label' => 'UUID de usuario o null JSON (asignar/desasignar)']
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
        [$field, $query, $variables] = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted(trim($parameters['credential_id']));
        } catch (\Throwable $e) {
            throw new RuntimeException('Linear: no se pudo recuperar o descifrar la credencial.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Linear: credencial inexistente.');
        }
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException('Linear: usa generic_bearer con configuración JSON en API Key.');
        }
        $config = $this->objectValue($credential['data']['api_key']);
        $authType = $config['auth_type'] ?? null;
        $token = $config['token'] ?? null;
        if (!in_array($authType, ['api_key', 'oauth'], true) || !is_string($token)
            || preg_match('/^[A-Za-z0-9._-]+$/D', $token) !== 1) {
            throw new InvalidArgumentException('Linear: configura auth_type api_key/oauth y token válido sin prefijos.');
        }
        try {
            $response = ($this->client ?? new Client())->request('POST', 'https://api.linear.app/graphql', [
                'headers' => ['Authorization' => ($authType === 'oauth' ? 'Bearer ' : '') . $token, 'Accept' => 'application/json'],
                'json' => ['query' => $query, 'variables' => $variables],
                'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
                'allow_redirects' => false, 'http_errors' => false
            ]);
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
        } catch (\Throwable $e) {
            throw new RuntimeException('Linear: fallo de transporte; la escritura puede haber ocurrido. Comprueba Linear antes de reintentar.');
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'autenticación rechazada', 403 => 'permisos o scopes insuficientes',
                404 => 'recurso inexistente o inaccesible', 429 => 'cuota alcanzada; espera antes de reintentar',
                default => 'solicitud rechazada; verifica el resultado antes de reintentar'
            };
            throw new RuntimeException("Linear: {$reason} (HTTP {$status}).");
        }
        $body = json_decode($raw, true);
        if ($status !== 200 || !is_array($body) || (array_key_exists('errors', $body) && $body['errors'] !== [])) {
            throw new RuntimeException('Linear: errores GraphQL o respuesta inválida; verifica el resultado antes de reintentar.');
        }
        $payload = is_array($body['data'] ?? null) ? ($body['data'][$field] ?? null) : null;
        $issue = is_array($payload) ? ($payload['issue'] ?? null) : null;
        if (!is_array($payload) || ($payload['success'] ?? null) !== true || !is_array($issue) || !$this->uuid($issue['id'] ?? null)) {
            throw new RuntimeException('Linear: mutación rechazada o respuesta incompleta; verifica el resultado antes de reintentar.');
        }
        $data = ['id' => $issue['id']];
        if (array_key_exists('identifier', $issue)) {
            if (!$this->identifier($issue['identifier'])) {
                throw new RuntimeException('Linear: identificador de respuesta inválido.');
            }
            $data['identifier'] = $issue['identifier'];
        }
        foreach (['state' => 'state_id', 'assignee' => 'assignee_id'] as $source => $destination) {
            if (array_key_exists($source, $issue)) {
                if ($issue[$source] !== null && (!is_array($issue[$source]) || !$this->uuid($issue[$source]['id'] ?? null))) {
                    throw new RuntimeException('Linear: estado o responsable inválido en respuesta.');
                }
                $data[$destination] = $issue[$source]['id'] ?? null;
            }
        }
        // Solo IDs tipados: nunca texto remoto, headers ni configuración secreta.
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        if (!is_string($p['credential_id'] ?? null) || trim($p['credential_id']) === '') {
            throw new InvalidArgumentException('Linear: selecciona una credencial.');
        }
        $operation = $p['operation'] ?? 'create_issue';
        if (!in_array($operation, ['create_issue', 'update_state', 'assign_issue'], true)) {
            throw new InvalidArgumentException('Linear: operación inválida.');
        }
        if ($operation === 'create_issue') {
            if (!$this->uuid($p['team_id'] ?? null) || !$this->text($p['title'] ?? null, 1000, 1) || trim($p['title']) === '') {
                throw new InvalidArgumentException('Linear: crear requiere team_id UUID y título no vacío hasta 1000 caracteres.');
            }
            $input = ['teamId' => $p['team_id'], 'title' => $p['title']];
            $fields = $this->objectValue(($p['fields'] ?? '') === '' ? new \stdClass() : $p['fields']);
            if (array_diff(array_keys($fields), ['description', 'priority', 'state_id', 'assignee_id', 'label_ids']) !== []) {
                throw new InvalidArgumentException('Linear: opciones de creación no admitidas.');
            }
            foreach ($fields as $key => $value) {
                if ($key === 'description') {
                    if (!$this->text($value, 100000)) {
                        throw new InvalidArgumentException('Linear: description debe ser texto UTF-8 hasta 100000 caracteres.');
                    }
                    $input['description'] = $value;
                } elseif ($key === 'priority') {
                    if (!is_int($value) || $value < 0 || $value > 4) {
                        throw new InvalidArgumentException('Linear: priority debe ser entero 0–4.');
                    }
                    $input['priority'] = $value;
                } elseif ($key === 'label_ids') {
                    if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
                        throw new InvalidArgumentException('Linear: label_ids debe ser lista de hasta 100 UUIDs.');
                    }
                    foreach ($value as $id) {
                        if (!$this->uuid($id)) {
                            throw new InvalidArgumentException('Linear: UUID de etiqueta inválido.');
                        }
                    }
                    $input['labelIds'] = $value;
                } else {
                    if (!$this->uuid($value) && !($key === 'assignee_id' && $value === null)) {
                        throw new InvalidArgumentException('Linear: UUID de estado o responsable inválido.');
                    }
                    $input[$key === 'state_id' ? 'stateId' : 'assigneeId'] = $value;
                }
            }
            return ['issueCreate', 'mutation P8PCreateIssue($input: IssueCreateInput!) { issueCreate(input: $input) { success issue { id identifier state { id } assignee { id } } } }', ['input' => $input]];
        }
        if (!$this->uuid($p['issue_id'] ?? null) && !$this->identifier($p['issue_id'] ?? null)) {
            throw new InvalidArgumentException('Linear: issue_id debe ser UUID o identificador como ENG-123.');
        }
        if ($operation === 'update_state') {
            if (!$this->uuid($p['state_id'] ?? null)) {
                throw new InvalidArgumentException('Linear: state_id debe ser UUID de un estado del equipo.');
            }
            $input = ['stateId' => $p['state_id']];
        } else {
            if (!array_key_exists('assignee_id', $p)) {
                throw new InvalidArgumentException('Linear: indica assignee_id o null JSON explícito para desasignar.');
            }
            $assignee = $p['assignee_id'] === 'null' ? null : $p['assignee_id'];
            if ($assignee !== null && !$this->uuid($assignee)) {
                throw new InvalidArgumentException('Linear: assignee_id debe ser un UUID o null JSON; no admite múltiples responsables.');
            }
            $input = ['assigneeId' => $assignee];
        }
        return ['issueUpdate', 'mutation P8PUpdateIssue($id: String!, $input: IssueUpdateInput!) { issueUpdate(id: $id, input: $input) { success issue { id identifier state { id } assignee { id } } } }',
            ['id' => $p['issue_id'], 'input' => $input]];
    }

    private function objectValue(mixed $value): array
    {
        if (is_string($value)) {
            if (strlen($value) > 1048576) {
                throw new InvalidArgumentException('Linear: JSON supera 1 MiB.');
            }
            try {
                $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Linear: JSON inválido.');
            }
        }
        if ($value instanceof \stdClass) {
            return (array) $value;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Linear: se requiere un objeto JSON.');
        }
        return $value;
    }

    private function text(mixed $value, int $max, int $min = 0): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') >= $min && mb_strlen($value, 'UTF-8') <= $max;
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $value) === 1;
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 100 && preg_match('/^[A-Za-z][A-Za-z0-9_]*-[1-9][0-9]*$/D', $value) === 1;
    }
}
