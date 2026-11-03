<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class ZendeskNode implements NodeInterface
{
    private const STATUSES = ['new', 'open', 'pending', 'hold', 'solved'];
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
            'type' => 'zendesk', 'name' => 'Zendesk', 'category' => 'Community',
            'description' => 'Crea tickets, actualiza estados y añade comentarios privados o públicos explícitos.',
            'icon' => 'Ticket', 'is_start' => false,
            'help_text' => 'Credencial generic_bearer: API Key contiene JSON {"subdomain":"equipo-demo","access_token":"TOKEN_OAUTH"} o {"subdomain":"equipo-demo","email":"agent@example.com","api_token":"TOKEN_API"}. OAuth recomendado; API tokens son compatibilidad heredada de Zendesk. Cuenta/usuario verificado con rol de agente y permisos sobre tickets; OAuth con tickets:write o write y lectura para consultar estados personalizados. Multi-cliente requiere OAuth global autorizado. Sin OAuth/renovación automática. Subdominio único sin URL ni .zendesk.com. Crear exige subject/body y requester_id o requester_data {"name":"Nombre","email":"persona@example.com"}; puede crear al solicitante según configuración de Zendesk. idempotency_key opcional solo al crear: clave estable no secreta de hasta 128 caracteres ASCII (letras, números, . _ : -). Misma clave y mismo cuerpo durante 2 horas recuperan el mismo ticket; otro cuerpo produce HTTP 400. Después de 2 horas la misma clave puede crear otro ticket. No se generan claves ni reintentos automáticos. Comentario inicial y adicionales privados por defecto: público requiere elegir true explícito. Las reglas Zendesk pueden generar notificaciones incluso con comentarios privados. Estado estándar: new/open/pending/hold/solved; closed no se ofrece en este nodo. Custom exige función habilitada y estado activo: se consulta categoría antes de escribir y solo se envía custom_status_id. Tickets cerrados y permisos/estados deshabilitados pueden impedir cambios. Sin adjuntos ni paginación. Una escritura con fallo de transporte puede existir y debes comprobar el ticket antes de repetir. Salida data.ticket.id/status/custom_status_id y audit cuando esté disponible. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Zendesk (API Key con JSON privado)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'create_ticket', 'options' => [
                    ['value' => 'create_ticket', 'label' => 'Crear ticket'], ['value' => 'update_status', 'label' => 'Actualizar estado'],
                    ['value' => 'add_comment', 'label' => 'Añadir comentario']
                ]],
                'ticket_id' => ['type' => 'string', 'required' => false, 'label' => 'ID del ticket (actualizar/comentar)'],
                'idempotency_key' => ['type' => 'string', 'required' => false, 'label' => 'Clave estable de idempotencia (solo crear; válida 2 horas)', 'placeholder' => 'ticket-pedido-12345'],
                'subject' => ['type' => 'string', 'required' => false, 'label' => 'Asunto (crear; máximo 255 caracteres)'],
                'body' => ['type' => 'textarea', 'required' => false, 'label' => 'Comentario de texto (crear/comentar; máximo 64 KiB)'],
                'comment_public' => ['type' => 'select', 'required' => false, 'label' => 'Visibilidad del comentario (también inicial)', 'default' => 'false', 'options' => [
                    ['value' => 'false', 'label' => 'Privado (nota interna)'], ['value' => 'true', 'label' => 'Público (selección explícita)']
                ]],
                'requester_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de solicitante existente (crear; alternativa a requester_data)'],
                'requester_data' => ['type' => 'textarea', 'required' => false, 'label' => 'Solicitante con nombre y email (objeto JSON)', 'placeholder' => '{"name":"Demo","email":"demo@example.com"}'],
                'priority' => ['type' => 'select', 'required' => false, 'label' => 'Prioridad (crear; opcional)', 'options' => [
                    ['value' => '', 'label' => 'No especificar'], ['value' => 'low', 'label' => 'Baja'], ['value' => 'normal', 'label' => 'Normal'],
                    ['value' => 'high', 'label' => 'Alta'], ['value' => 'urgent', 'label' => 'Urgente']
                ]],
                'status_mode' => ['type' => 'select', 'required' => false, 'label' => 'Tipo de estado (actualizar)', 'default' => 'standard', 'options' => [
                    ['value' => 'standard', 'label' => 'Estándar'], ['value' => 'custom', 'label' => 'Personalizado (verifica estado activo)']
                ]],
                'status' => ['type' => 'select', 'required' => false, 'label' => 'Estado estándar (actualizar)', 'default' => '', 'options' => [
                    ['value' => '', 'label' => 'Seleccionar estado'],
                    ['value' => 'new', 'label' => 'Nuevo'], ['value' => 'open', 'label' => 'Abierto'], ['value' => 'pending', 'label' => 'Pendiente'],
                    ['value' => 'hold', 'label' => 'En espera'], ['value' => 'solved', 'label' => 'Resuelto']
                ]],
                'custom_status_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de estado personalizado (actualizar en modo custom)']
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepareParameters($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine aplica ExpressionEvaluator antes de ejecutar; no volver a interpolar.
        $prepared = $this->prepareParameters($parameters);
        $auth = $this->getAuthentication($prepared['credential_id']);
        $client = $this->client ?? new Client();
        if (isset($prepared['custom_status_id'])) {
            [$statusCode, $data] = $this->request($client, $auth, 'GET',
                '/api/v2/custom_statuses/' . $prepared['custom_status_id'] . '.json');
            $custom = $data['custom_status'] ?? null;
            if ($statusCode !== 200 || !is_array($custom) || ($custom['active'] ?? null) !== true
                || !in_array($custom['status_category'] ?? null, self::STATUSES, true)
                || !$this->validNumericId($custom['id'] ?? null)
                || (string) $custom['id'] !== $prepared['custom_status_id']) {
                throw new RuntimeException('Zendesk: estado personalizado inexistente, inactivo o de categoría incompatible; no se modificó el ticket.');
            }
            // custom_status_id determina su categoría; no enviar un status contradictorio del formulario.
        }
        [$statusCode, $data] = $this->request($client, $auth, $prepared['method'], $prepared['path'],
            ['ticket' => $prepared['ticket']], $prepared['idempotency_key'] ?? null);
        $ticket = $data['ticket'] ?? null;
        if (!in_array($statusCode, $prepared['operation'] === 'create_ticket' ? [201] : [200], true)
            || !is_array($ticket) || !$this->validNumericId($ticket['id'] ?? null)
            || (isset($prepared['ticket_id']) && (string) $ticket['id'] !== $prepared['ticket_id'])) {
            throw new RuntimeException('Zendesk: no confirmó el ticket esperado; comprueba Zendesk antes de repetir la escritura.');
        }
        // Las reglas del workspace pueden modificar el estado solicitado; devolver el estado efectivo.
        return ['success' => true, 'status_code' => $statusCode, 'data' => $this->sanitize($data, $auth['secrets'])];
    }

    private function request(ClientInterface $client, array $auth, string $method, string $path,
        ?array $payload = null, ?string $idempotencyKey = null): array
    {
        $writing = $method !== 'GET';
        $options = ['headers' => ['Authorization' => $auth['authorization'], 'Accept' => 'application/json'],
            'verify' => true, 'timeout' => 30, 'connect_timeout' => 10, 'allow_redirects' => false,
            'http_errors' => false, 'stream' => true];
        if ($payload !== null) {
            $options['json'] = $payload;
        }
        if ($idempotencyKey !== null) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }
        try {
            $response = $client->request($method, $auth['origin'] . $path, $options);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            throw new RuntimeException('Zendesk: fallo de transporte.' . ($writing
                ? ' El resultado es incierto; comprueba el ticket antes de repetir. No se reintentó.' : ' No se modificó el ticket.'));
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'autenticación inválida, token revocado/expirado o cuenta incorrecta; revisa la credencial',
                403 => 'permisos insuficientes; requiere agente autorizado y scopes de tickets o función de estados habilitada',
                404 => 'ticket o estado inexistente o inaccesible para esta cuenta',
                409 => 'conflicto de actualización; consulta el ticket antes de repetir',
                400 => 'solicitud inválida o clave de idempotencia reutilizada con un cuerpo distinto',
                422 => 'datos rechazados; revisa solicitante, campos obligatorios de la cuenta, estado y si el ticket está cerrado',
                429 => 'cuota o límite de solicitudes alcanzado; no se reintentó',
                default => 'solicitud rechazada o servicio no disponible; revisa el recurso antes de repetir'
            };
            throw new RuntimeException("Zendesk: {$reason} (HTTP {$status}).", $status);
        }
        try {
            $stream = $response->getBody();
            $body = '';
            while (!$stream->eof() && strlen($body) <= 2097152) {
                $chunk = $stream->read(min(8192, 2097153 - strlen($body)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $body .= $chunk;
            }
            if (strlen($body) > 2097152) {
                throw new RuntimeException();
            }
            $object = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) {
                throw new RuntimeException();
            }
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('Zendesk: respuesta JSON inválida, vacía o superior a 2 MiB.'
                . ($writing ? ' El ticket puede haberse modificado; compruébalo antes de repetir.' : ' No se modificó el ticket.'));
        }
        if (array_key_exists('error', $data) || array_key_exists('errors', $data)
            || (isset($data['job_status']['status']) && $data['job_status']['status'] === 'failed')) {
            throw new RuntimeException('Zendesk: la respuesta contiene un error funcional; comprueba el recurso y los permisos antes de repetir.');
        }
        return [$status, $data];
    }

    private function getAuthentication(string $credentialId): array
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($credentialId);
        } catch (\Throwable $e) {
            throw new RuntimeException('Zendesk: fallo de almacenamiento o descifrado de la credencial; revisa el gestor.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Zendesk: la credencial seleccionada no existe.');
        }
        $raw = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($raw) || strlen($raw) > 16384) {
            throw new InvalidArgumentException('Zendesk: usa generic_bearer con JSON de configuración en data.api_key.');
        }
        try {
            $config = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Zendesk: API Key debe contener JSON válido con subdomain y la autenticación elegida.');
        }
        $subdomain = is_array($config) ? ($config['subdomain'] ?? null) : null;
        if (!is_string($subdomain) || strlen($subdomain) > 63
            || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $subdomain) !== 1) {
            throw new InvalidArgumentException('Zendesk: subdomain debe ser únicamente el nombre de la cuenta en minúsculas, sin URL ni dominio.');
        }
        $oauth = array_key_exists('access_token', $config);
        $allowed = $oauth ? ['subdomain', 'access_token'] : ['subdomain', 'email', 'api_token'];
        if (array_diff(array_keys($config), $allowed) !== []) {
            throw new InvalidArgumentException('Zendesk: credencial contiene campos incompatibles; elige OAuth o email/API token.');
        }
        $token = $config[$oauth ? 'access_token' : 'api_token'] ?? null;
        if (!is_string($token) || strlen($token) > 8192 || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1) {
            throw new InvalidArgumentException('Zendesk: la credencial requiere un token válido sin prefijo Bearer.');
        }
        if ($oauth) {
            $authorization = 'Bearer ' . $token;
            $secrets = [$token, $raw, $authorization];
        } else {
            $email = $config['email'] ?? null;
            if (!is_string($email) || strlen($email) > 320 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Zendesk: la autenticación API token requiere email válido de un agente.');
            }
            $basic = base64_encode($email . '/token:' . $token);
            $authorization = 'Basic ' . $basic;
            $secrets = [$token, $raw, $basic, $authorization, $email . '/token:' . $token];
        }
        return ['origin' => 'https://' . $subdomain . '.zendesk.com', 'authorization' => $authorization, 'secrets' => $secrets];
    }

    private function prepareParameters(array $parameters): array
    {
        $credentialId = $parameters['credential_id'] ?? null;
        if (!is_string($credentialId) || trim($credentialId) === '' || strlen($credentialId) > 200) {
            throw new InvalidArgumentException('Zendesk: credential_id es obligatorio y debe ser texto.');
        }
        $operation = $parameters['operation'] ?? 'create_ticket';
        if (!is_string($operation) || !in_array($operation, ['create_ticket', 'update_status', 'add_comment'], true)) {
            throw new InvalidArgumentException('Zendesk: operación inválida.');
        }
        $prepared = ['credential_id' => $credentialId, 'operation' => $operation, 'method' => 'PUT'];
        $idempotencyKey = array_key_exists('idempotency_key', $parameters) ? $parameters['idempotency_key'] : '';
        if (!is_string($idempotencyKey) || ($idempotencyKey !== ''
            && (preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $idempotencyKey) !== 1 || $operation !== 'create_ticket'))) {
            throw new InvalidArgumentException('Zendesk: idempotency_key solo se admite al crear, con 1–128 caracteres ASCII de letras, números, punto, guion bajo, dos puntos o guion; usa vacío para omitir.');
        }
        if ($idempotencyKey !== '') {
            $prepared['idempotency_key'] = $idempotencyKey;
        }
        if ($operation === 'create_ticket') {
            $subject = $parameters['subject'] ?? null;
            if (!$this->validText($subject, 1020) || mb_strlen($subject, 'UTF-8') > 255) {
                throw new InvalidArgumentException('Zendesk: subject requiere texto UTF-8 no vacío de hasta 255 caracteres.');
            }
            $ticket = ['subject' => $subject, 'comment' => $this->comment($parameters)];
            $requesterId = $parameters['requester_id'] ?? '';
            $requesterInput = $parameters['requester_data'] ?? '';
            if ($requesterId !== '') {
                if (!$this->validNumericId($requesterId) || ($requesterInput !== '' && $requesterInput !== '{}')) {
                    throw new InvalidArgumentException('Zendesk: usa requester_id positivo o requester_data, sin combinar ambos.');
                }
                $ticket['requester_id'] = (int) $requesterId;
            } else {
                $requester = $this->requester($requesterInput);
                $ticket['requester'] = $requester;
            }
            if (array_key_exists('priority', $parameters) && $parameters['priority'] !== '') {
                if (!in_array($parameters['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
                    throw new InvalidArgumentException('Zendesk: priority debe ser low, normal, high o urgent.');
                }
                $ticket['priority'] = $parameters['priority'];
            }
            $prepared['method'] = 'POST';
            return $prepared + ['path' => '/api/v2/tickets.json', 'ticket' => $ticket];
        }
        $ticketId = $parameters['ticket_id'] ?? null;
        if (!$this->validNumericId($ticketId)) {
            throw new InvalidArgumentException('Zendesk: ticket_id debe ser un ID entero positivo.');
        }
        $prepared += ['path' => '/api/v2/tickets/' . $ticketId . '.json', 'ticket_id' => (string) $ticketId];
        if ($operation === 'add_comment') {
            return $prepared + ['ticket' => ['comment' => $this->comment($parameters)]];
        }
        $mode = $parameters['status_mode'] ?? 'standard';
        if ($mode === 'custom') {
            $customId = $parameters['custom_status_id'] ?? null;
            if (!$this->validNumericId($customId)) {
                throw new InvalidArgumentException('Zendesk: custom_status_id debe ser entero positivo.');
            }
            return $prepared + ['custom_status_id' => (string) $customId, 'ticket' => ['custom_status_id' => (int) $customId]];
        }
        $status = $parameters['status'] ?? null;
        if ($mode !== 'standard' || !is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Zendesk: estado estándar debe ser new, open, pending, hold o solved; closed queda fuera de este nodo.');
        }
        return $prepared + ['ticket' => ['status' => $status]];
    }

    private function comment(array $parameters): array
    {
        $body = $parameters['body'] ?? null;
        $public = array_key_exists('comment_public', $parameters) ? $parameters['comment_public'] : false;
        if (!$this->validText($body, 65536) || !in_array($public, [true, false, 'true', 'false'], true)) {
            throw new InvalidArgumentException('Zendesk: body debe ser texto UTF-8 no vacío de hasta 64 KiB y comment_public debe ser true/false explícito.');
        }
        return ['body' => $body, 'public' => $public === true || $public === 'true'];
    }

    private function requester(mixed $input): array
    {
        if (is_string($input)) {
            if (strlen($input) > 4096) {
                throw new InvalidArgumentException('Zendesk: requester_data supera el límite local de 4 KiB.');
            }
            try {
                $input = json_decode($input, false, 8, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Zendesk: requester_data requiere un objeto JSON con name y email.');
            }
        }
        if ($input instanceof \stdClass) {
            $input = get_object_vars($input);
        }
        if (!is_array($input) || array_diff(array_keys($input), ['name', 'email']) !== []
            || !$this->validText($input['name'] ?? null, 255) || !is_string($input['email'] ?? null)
            || strlen($input['email']) > 320 || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Zendesk: requester_data requiere solo name no vacío y email válido.');
        }
        return ['name' => $input['name'], 'email' => $input['email']];
    }

    private function validNumericId(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1);
    }

    private function validText(mixed $value, int $maxBytes): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $maxBytes
            && mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0");
    }

    private function sanitize(mixed $value, array $secrets): mixed
    {
        if (is_string($value)) {
            foreach ($secrets as $secret) {
                $value = str_replace([$secret, rawurlencode($secret)], '[REDACTED]', $value);
            }
            return $value;
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key) ? $this->sanitize($key, $secrets) : $key;
                $safe[$safeKey] = is_string($key) && preg_match('/^(?:authorization|access_token|refresh_token|api_token|api_key|client_secret)$/iD', $key)
                    ? '[REDACTED]' : $this->sanitize($item, $secrets);
            }
            return $safe;
        }
        return $value;
    }
}
