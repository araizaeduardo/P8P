<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class JiraNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'jira', 'name' => 'Jira', 'category' => 'Community', 'icon' => 'Ticket', 'is_start' => false,
            'description' => 'Crea issues, consulta transiciones, cambia estado y comenta en Jira Cloud REST v3.',
            'help_text' => 'Solo Jira Cloud *.atlassian.net. Credencial generic_bearer: API Key contiene JSON {"site_url":"https://example.atlassian.net","email":"bot@example.com","api_token":"TOKEN_FICTICIO"}. API token SIN scopes; no admite tokens scoped (requieren gateway api.atlassian.com/cloudId), OAuth, Server ni Data Center. Tokens caducan entre 1 y 365 días, reemplazo manual. Permisos del usuario: Browse projects y Create issues / Transition issues / Add comments; además seguridad del issue. issue_type_id es ID numérico, no nombre. content_format=text convierte descripción/comentario a ADF; adf acepta JSON, array u objeto resuelto. Subconjunto ADF: doc, paragraph, text, hardBreak y marcas strong/em/code/strike/underline. Máximo 32768 caracteres de texto, 128 KiB JSON y profundidad 16. description omitida no se envía; texto vacío envía documento vacío. fields opcional JSON permite labels, assignee, priority, parent, components, duedate; se envían solo claves proporcionadas ([], null y texto vacío explícitos según campo). Transición comprueba previamente que ID esté disponible; Jira valida requisitos de la pantalla. Sin reintentos; verificar escrituras inciertas antes de repetir. Transiciones hasta 1000, sin paginación. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Jira Cloud JSON'],
                'operation' => ['type' => 'select', 'default' => 'create_issue', 'label' => 'Operación', 'options' => [
                    ['value' => 'create_issue', 'label' => 'Crear issue'], ['value' => 'list_transitions', 'label' => 'Consultar transiciones'],
                    ['value' => 'transition_issue', 'label' => 'Cambiar estado'], ['value' => 'add_comment', 'label' => 'Añadir comentario']]],
                'project_key' => ['type' => 'string', 'label' => 'Clave de proyecto (crear)', 'placeholder' => 'DEMO'],
                'issue_type_id' => ['type' => 'string', 'label' => 'ID del tipo de issue (crear)', 'placeholder' => '10001'],
                'summary' => ['type' => 'string', 'label' => 'Resumen (crear)'],
                'description' => ['type' => 'textarea', 'label' => 'Descripción (crear, opcional)'],
                'issue_key' => ['type' => 'string', 'label' => 'Clave del issue (otras operaciones)', 'placeholder' => 'DEMO-42'],
                'transition_id' => ['type' => 'string', 'label' => 'ID de transición (cambiar estado)'],
                'comment' => ['type' => 'textarea', 'label' => 'Comentario'],
                'content_format' => ['type' => 'select', 'default' => 'text', 'label' => 'Formato descripción/comentario', 'options' => [
                    ['value' => 'text', 'label' => 'Texto simple'], ['value' => 'adf', 'label' => 'Documento ADF JSON']]],
                'fields' => ['type' => 'textarea', 'label' => 'Campos opcionales (crear/transición, JSON)', 'placeholder' => '{"labels":["p8p"],"priority":{"id":"3"}}']
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
        // Engine resuelve ExpressionEvaluator; no reevaluar texto resultante.
        $p = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($p['credential_id']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Jira: no se pudo recuperar o descifrar la credencial; revisa el gestor y su almacenamiento.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Jira: la credencial seleccionada no existe.');
        }
        $c = $this->parseCredential($credential);
        $auth = base64_encode($c['email'] . ':' . $c['api_token']);
        $options = ['headers' => ['Authorization' => 'Basic ' . $auth, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false];
        $client = $this->client ?? new Client();
        if (in_array($p['operation'], ['list_transitions', 'transition_issue'], true)) {
            [$status, $body] = $this->request($client, 'GET', $c['site_url'] . $p['path'], $options);
            $transitions = $this->transitions($body, [$c['api_token'], $auth]);
            if ($p['operation'] === 'list_transitions') {
                return ['success' => true, 'status_code' => $status, 'data' => ['issue_key' => $p['issue_key'],
                    'transitions' => $transitions, 'pagination' => ['supported' => false, 'limit' => 1000, 'count' => count($transitions)]]];
            }
            if (!in_array($p['transition_id'], array_column($transitions, 'id'), true)) {
                throw new InvalidArgumentException('Jira: la transición solicitada no está disponible para este issue y usuario. No se cambió el estado.');
            }
        }
        $options['json'] = $p['payload'];
        [$status, $body] = $this->request($client, 'POST', $c['site_url'] . $p['path'], $options);
        try {
            if ($p['operation'] === 'transition_issue') {
                if ($status !== 204) {
                    throw new InvalidArgumentException();
                }
                $data = ['issue_key' => $p['issue_key'], 'transition_id' => $p['transition_id']];
            } else {
                if (!is_array($body) || !is_string($body['id'] ?? null) || !preg_match('/^[1-9][0-9]{0,19}$/D', $body['id'])) {
                    throw new InvalidArgumentException();
                }
                $data = ['id' => $body['id']];
                if ($p['operation'] === 'create_issue') {
                    if (!is_string($body['key'] ?? null) || !preg_match('/^[A-Z][A-Z0-9_]*-[1-9][0-9]*$/D', $body['key'])) {
                        throw new InvalidArgumentException();
                    }
                    $data['key'] = $body['key'];
                    $data['url'] = $c['site_url'] . '/browse/' . $body['key'];
                } else {
                    $data['issue_key'] = $p['issue_key'];
                }
            }
            $this->assertNoSecrets($data, [$c['api_token'], $auth]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Jira: respuesta de escritura inválida o incompleta. La operación puede haberse realizado; comprueba el issue antes de repetirla.', $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        $id = $this->text($p['credential_id'] ?? null, 1000, true);
        $op = $p['operation'] ?? 'create_issue';
        if (!in_array($op, ['create_issue', 'list_transitions', 'transition_issue', 'add_comment'], true)) {
            throw new InvalidArgumentException('Jira: operación inválida.');
        }
        $format = $p['content_format'] ?? 'text';
        if (!in_array($format, ['text', 'adf'], true)) {
            throw new InvalidArgumentException('Jira: formato de contenido inválido.');
        }
        $payload = [];
        $issueKey = '';
        $transitionId = '';
        if ($op === 'create_issue') {
            $project = $this->text($p['project_key'] ?? null, 100, true);
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $project)) {
                throw new InvalidArgumentException('Jira: clave de proyecto inválida.');
            }
            $payload['fields'] = ['project' => ['key' => $project], 'issuetype' => ['id' => $this->numericId($p['issue_type_id'] ?? null)],
                'summary' => $this->text($p['summary'] ?? null, 255, true)];
            if (array_key_exists('description', $p)) {
                $payload['fields']['description'] = $this->document($p['description'], $format);
            }
            $path = '/rest/api/3/issue';
        } else {
            $issueKey = $this->text($p['issue_key'] ?? null, 150, true);
            if (!preg_match('/^[A-Z][A-Z0-9_]*-[1-9][0-9]*$/D', $issueKey)) {
                throw new InvalidArgumentException('Jira: issue_key inválido.');
            }
            $path = '/rest/api/3/issue/' . rawurlencode($issueKey);
            if ($op === 'add_comment') {
                $path .= '/comment';
                $payload['body'] = $this->document($p['comment'] ?? null, $format);
                if (!$this->hasText($payload['body'])) {
                    throw new InvalidArgumentException('Jira: el comentario debe contener texto.');
                }
            } else {
                $path .= '/transitions';
                if ($op === 'transition_issue') {
                    $transitionId = $this->numericId($p['transition_id'] ?? null);
                    $payload['transition'] = ['id' => $transitionId];
                }
            }
        }
        if (in_array($op, ['create_issue', 'transition_issue'], true) && array_key_exists('fields', $p) && $p['fields'] !== '') {
            $fields = $this->fields($p['fields']);
            if ($fields !== []) {
                $payload['fields'] = ($payload['fields'] ?? []) + $fields;
            }
        }
        return ['credential_id' => $id, 'operation' => $op, 'payload' => $payload, 'path' => $path,
            'issue_key' => $issueKey, 'transition_id' => $transitionId];
    }

    private function parseCredential(array $credential): array
    {
        $raw = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($raw) || strlen($raw) > 16384) {
            throw new InvalidArgumentException('Jira: selecciona generic_bearer con configuración JSON en API Key.');
        }
        $c = json_decode($raw, true);
        if (!is_array($c) || array_is_list($c) || !is_string($c['site_url'] ?? null)
            || !preg_match('~^https://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.atlassian\.net/?$~D', $c['site_url'])
            || !is_string($c['email'] ?? null) || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)
            || str_contains($c['email'], ':') || !is_string($c['api_token'] ?? null)
            || !preg_match('/^[\x21-\x7e]{8,4096}$/D', $c['api_token'])
            || (isset($c['auth_mode']) && $c['auth_mode'] !== 'basic_unscoped')) {
            throw new InvalidArgumentException('Jira: configuración de credencial inválida. Requiere site_url HTTPS *.atlassian.net sin rutas, email y api_token sin scopes; no contraseña ni token scoped.');
        }
        $c['site_url'] = rtrim($c['site_url'], '/');
        return $c;
    }

    private function request(ClientInterface $client, string $method, string $url, array $options): array
    {
        $notice = $method === 'POST' ? ' La escritura puede haberse realizado; comprueba el issue antes de repetirla.' : '';
        try {
            $response = $client->request($method, $url, $options);
            $status = $response->getStatusCode();
            $raw = $response->getBody()->read(1048577);
        } catch (\Throwable $e) {
            throw new RuntimeException('Jira: fallo de transporte o lectura.' . $notice);
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'autenticación rechazada; revisa token, caducidad y variante sin scopes',
                403 => 'permisos insuficientes o acceso bloqueado',
                404 => 'issue o proyecto inexistente o inaccesible',
                400, 422 => 'datos rechazados; revisa campos requeridos, ADF y requisitos de transición',
                429 => 'cuota o límite de solicitudes alcanzado; espera antes de reintentar',
                default => 'solicitud rechazada o error del servicio'
            };
            throw new RuntimeException("Jira: {$reason} (HTTP {$status})." . $notice, $status);
        }
        if ($status === 204 && $raw === '') {
            return [$status, null];
        }
        $body = json_decode($raw, true);
        if (strlen($raw) > 1048576 || json_last_error() !== JSON_ERROR_NONE || !is_array($body)
            || isset($body['errorMessages']) || isset($body['errors'])) {
            throw new RuntimeException('Jira: respuesta inválida o superior a 1 MiB.' . $notice, $status);
        }
        return [$status, $body];
    }

    private function transitions(mixed $body, array $secrets): array
    {
        try {
            if (!is_array($body) || !is_array($body['transitions'] ?? null) || !array_is_list($body['transitions']) || count($body['transitions']) > 1000) {
                throw new InvalidArgumentException();
            }
            $items = [];
            foreach ($body['transitions'] as $item) {
                if (!is_array($item)) {
                    throw new InvalidArgumentException();
                }
                $items[] = ['id' => $this->numericId($item['id'] ?? null), 'name' => $this->text($item['name'] ?? null, 255, true)];
            }
            $this->assertNoSecrets($items, $secrets);
            return $items;
        } catch (\Throwable $e) {
            throw new RuntimeException('Jira: respuesta de transiciones inválida o fuera de límites.');
        }
    }

    private function text(mixed $value, int $max, bool $required = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max
            || ($required && trim($value) === '') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|{{\s*[A-Za-z0-9_.]+\s*}}/', $value)) {
            throw new InvalidArgumentException('Jira: texto inválido, demasiado largo, obligatorio ausente o expresión sin resolver.');
        }
        return $value;
    }

    private function numericId(mixed $value): string
    {
        if (!(is_string($value) || is_int($value)) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $value)) {
            throw new InvalidArgumentException('Jira: ID numérico positivo requerido.');
        }
        return (string) $value;
    }

    private function document(mixed $value, string $format): array
    {
        if (is_string($value) && $format === 'text') {
            $value = $this->text($value, 32768);
            return ['type' => 'doc', 'version' => 1, 'content' => $value === '' ? [] : [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $value]]]]];
        }
        try {
            $json = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
            if (strlen($json) > 131072) {
                throw new InvalidArgumentException();
            }
            $doc = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($doc) || ($doc['type'] ?? null) !== 'doc' || ($doc['version'] ?? null) !== 1) {
                throw new InvalidArgumentException();
            }
            $length = 0;
            $this->checkAdf($doc, 0, $length, 'root');
            if ($length > 32768) {
                throw new InvalidArgumentException();
            }
            return $doc;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Jira: documento ADF inválido o fuera del subconjunto/límites admitidos.');
        }
    }

    private function checkAdf(mixed $node, int $depth, int &$length, string $parent): void
    {
        if (!is_array($node) || $depth > 16 || !is_string($node['type'] ?? null)) {
            throw new InvalidArgumentException();
        }
        $type = $node['type'];
        $allowed = match ($parent) { 'root' => ['doc'], 'doc' => ['paragraph'], 'paragraph' => ['text', 'hardBreak'], default => [] };
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException();
        }
        $keys = match ($type) { 'doc' => ['type', 'version', 'content'], 'paragraph' => ['type', 'content'], 'text' => ['type', 'text', 'marks'], default => ['type'] };
        if (array_diff(array_keys($node), $keys) !== []) {
            throw new InvalidArgumentException();
        }
        if (in_array($type, ['doc', 'paragraph'], true)) {
            if (!is_array($node['content'] ?? null) || !array_is_list($node['content']) || count($node['content']) > 1000) {
                throw new InvalidArgumentException();
            }
            foreach ($node['content'] as $child) {
                $this->checkAdf($child, $depth + 1, $length, $type);
            }
        } elseif ($type === 'text') {
            $text = $this->text($node['text'] ?? null, 32768, true);
            $length += mb_strlen($text, 'UTF-8');
            if (isset($node['marks'])) {
                if (!is_array($node['marks']) || !array_is_list($node['marks']) || count($node['marks']) > 5) {
                    throw new InvalidArgumentException();
                }
                foreach ($node['marks'] as $mark) {
                    if (!is_array($mark) || array_keys($mark) !== ['type'] || !in_array($mark['type'], ['strong', 'em', 'code', 'strike', 'underline'], true)) {
                        throw new InvalidArgumentException();
                    }
                }
            }
        }
    }

    private function hasText(array $node): bool
    {
        if (($node['type'] ?? '') === 'text' && trim($node['text']) !== '') {
            return true;
        }
        foreach ($node['content'] ?? [] as $child) {
            if ($this->hasText($child)) {
                return true;
            }
        }
        return false;
    }

    private function fields(mixed $value): array
    {
        try {
            $json = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
            if (strlen($json) > 131072 || !str_starts_with(ltrim($json), '{')) {
                throw new InvalidArgumentException();
            }
            $fields = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (array_diff(array_keys($fields), ['labels', 'assignee', 'priority', 'parent', 'components', 'duedate']) !== []) {
                throw new InvalidArgumentException();
            }
            foreach ($fields as $key => $v) {
                if ($key === 'labels') {
                    if (!is_array($v) || !array_is_list($v) || count($v) > 100) {
                        throw new InvalidArgumentException();
                    }
                    foreach ($v as $label) {
                        $this->text($label, 255, true);
                        if (preg_match('/\s/', $label)) {
                            throw new InvalidArgumentException();
                        }
                    }
                } elseif ($key === 'duedate') {
                    if ($v !== null && $v !== '') {
                        $this->text($v, 10, true);
                        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                        if (!$date || $date->format('Y-m-d') !== $v) {
                            throw new InvalidArgumentException();
                        }
                    }
                } elseif ($key === 'components') {
                    if (!is_array($v) || !array_is_list($v) || count($v) > 100) {
                        throw new InvalidArgumentException();
                    }
                    foreach ($v as $component) {
                        if (!is_array($component) || array_keys($component) !== ['id']) {
                            throw new InvalidArgumentException();
                        }
                        $this->numericId($component['id']);
                    }
                } else {
                    $field = match ($key) { 'assignee' => 'accountId', 'parent' => 'key', default => 'id' };
                    if (!is_array($v) || array_keys($v) !== [$field]) {
                        throw new InvalidArgumentException();
                    }
                    if ($key === 'assignee' && $v[$field] === null) {
                        continue;
                    }
                    $this->text($v[$field], 150, true);
                    if ($key === 'priority') {
                        $this->numericId($v[$field]);
                    } elseif ($key === 'parent' && !preg_match('/^[A-Z][A-Z0-9_]*-[1-9][0-9]*$/D', $v[$field])) {
                        throw new InvalidArgumentException();
                    }
                }
            }
            return $fields;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Jira: fields debe ser objeto JSON de campos admitidos y valores válidos.');
        }
    }

    private function assertNoSecrets(array $data, array $secrets): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($secrets as $secret) {
            if (str_contains($json, $secret)) {
                throw new InvalidArgumentException();
            }
        }
    }
}
