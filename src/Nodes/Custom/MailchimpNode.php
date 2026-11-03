<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class MailchimpNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentialManager = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'mailchimp', 'name' => 'Mailchimp', 'category' => 'Community', 'icon' => 'Mail', 'is_start' => false,
            'description' => 'Actualiza contactos y etiquetas; crea, consulta y modifica borradores de campañas.',
            'help_text' => 'generic_bearer: API Key contiene JSON {"server_prefix":"us21","token":"TOKEN"}; token puede ser API Key o access token OAuth. No renueva tokens. Upsert nunca envía status ni cambia consentimiento de contactos existentes; status_if_new solo se aplica a nuevos. Predeterminado transactional (sin suscripción). subscribed/pending requieren declaración explícita de consentimiento para nuevos; pending puede enviar confirmación. Etiquetas usan is_syncing=true para no disparar automatizaciones por tags. Campañas regular, actualizaciones de settings solo en estado save; no envía ni programa campañas. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Mailchimp (JSON)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'upsert_subscriber', 'options' => [
                    ['value' => 'upsert_subscriber', 'label' => 'Añadir o actualizar contacto'],
                    ['value' => 'manage_tags', 'label' => 'Añadir o quitar etiquetas'],
                    ['value' => 'create_campaign', 'label' => 'Crear borrador regular'],
                    ['value' => 'get_campaign', 'label' => 'Consultar campaña'],
                    ['value' => 'update_campaign', 'label' => 'Actualizar settings de borrador regular']
                ]],
                'list_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de audiencia (contacto/etiquetas/crear campaña)'],
                'email_address' => ['type' => 'string', 'required' => false, 'label' => 'Correo del contacto (contacto/etiquetas)'],
                'status_if_new' => ['type' => 'select', 'required' => false, 'label' => 'Estado solo para contacto nuevo', 'default' => 'transactional', 'options' => [
                    ['value' => 'transactional', 'label' => 'No suscrito (transactional)'],
                    ['value' => 'pending', 'label' => 'Solicitar confirmación (puede enviar correo)'],
                    ['value' => 'subscribed', 'label' => 'Suscrito con consentimiento previo explícito']
                ]],
                'new_contact_consent' => ['type' => 'select', 'required' => false, 'label' => 'Confirmo consentimiento de contactos nuevos (subscribed/pending)', 'default' => 'no', 'options' => [
                    ['value' => 'no', 'label' => 'No'], ['value' => 'yes', 'label' => 'Sí, tengo consentimiento para esta solicitud']
                ]],
                'merge_fields' => ['type' => 'textarea', 'required' => false, 'label' => 'Campos del contacto (objeto JSON; omitidos se conservan)', 'placeholder' => '{"FNAME":"Ana","LNAME":""}'],
                'tags' => ['type' => 'textarea', 'required' => false, 'label' => 'Etiquetas a modificar (lista JSON)', 'placeholder' => '[{"name":"Cliente","status":"active"},{"name":"Anterior","status":"inactive"}]'],
                'campaign_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de campaña (consultar/actualizar)'],
                'campaign_settings' => ['type' => 'textarea', 'required' => false, 'label' => 'Settings del borrador (objeto JSON)', 'placeholder' => '{"subject_line":"Novedades","from_name":"Equipo","reply_to":"equipo@example.com","title":"Borrador"}']
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
        [$operation, $method, $path, $body] = $this->prepare($parameters);
        [$base, $token] = $this->credentials($parameters['credential_id']);
        if ($operation === 'update_campaign') {
            $existing = $this->request('GET', $base . $path, null, $token);
            if (($existing['data']['id'] ?? null) !== $parameters['campaign_id'] || ($existing['data']['status'] ?? null) !== 'save' || ($existing['data']['type'] ?? null) !== 'regular') {
                throw new RuntimeException('Mailchimp: solo se modifican borradores regulares en estado save; no se realizó la escritura.');
            }
        }
        $result = $this->request($method, $base . $path, $body, $token, $operation === 'manage_tags' ? 204 : 200);
        if ($operation === 'manage_tags') {
            $result['data'] = ['list_id' => $parameters['list_id'], 'subscriber_hash' => md5(strtolower($parameters['email_address'])), 'tags' => $body['tags'], 'is_syncing' => true];
        } elseif ($operation === 'upsert_subscriber') {
            if (($result['data']['id'] ?? null) !== md5(strtolower($parameters['email_address'])) || !in_array($result['data']['status'] ?? null, ['subscribed', 'unsubscribed', 'cleaned', 'pending', 'transactional', 'archived'], true)) {
                throw new RuntimeException('Mailchimp: respuesta de contacto inesperada; comprueba el resultado de la escritura antes de repetir.');
            }
        } else {
            if (!isset($result['data']['id'], $result['data']['status'], $result['data']['type'])
                || !$this->validId($result['data']['id'])
                || !is_string($result['data']['status']) || preg_match('/^[a-z_]{1,32}$/D', $result['data']['status']) !== 1
                || !in_array($result['data']['type'], ['regular', 'plaintext', 'absplit', 'rss', 'variate'], true)
                || ($operation !== 'create_campaign' && $result['data']['id'] !== $parameters['campaign_id'])) {
                throw new RuntimeException('Mailchimp: respuesta de campaña inesperada; comprueba el resultado antes de repetir una escritura.');
            }
            if ($operation !== 'get_campaign' && ($result['data']['status'] !== 'save' || $result['data']['type'] !== 'regular')) {
                throw new RuntimeException('Mailchimp: no se confirmó un borrador regular en estado save; comprueba su estado antes de repetir.');
            }
        }
        return $result;
    }

    private function prepare(array $p): array
    {
        if (!isset($p['credential_id']) || !is_string($p['credential_id']) || trim($p['credential_id']) === '') {
            throw new InvalidArgumentException('Mailchimp: credential_id requerido.');
        }
        $operation = array_key_exists('operation', $p) ? $p['operation'] : 'upsert_subscriber';
        if (!in_array($operation, ['upsert_subscriber', 'manage_tags', 'create_campaign', 'get_campaign', 'update_campaign'], true)) {
            throw new InvalidArgumentException('Mailchimp: operación inválida.');
        }
        if (in_array($operation, ['upsert_subscriber', 'manage_tags', 'create_campaign'], true) && !$this->validId($p['list_id'] ?? null)) {
            throw new InvalidArgumentException('Mailchimp: list_id requerido e inválido.');
        }
        $body = null;
        if (in_array($operation, ['upsert_subscriber', 'manage_tags'], true)) {
            if (!isset($p['email_address']) || !is_string($p['email_address']) || strlen($p['email_address']) > 254 || filter_var($p['email_address'], FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Mailchimp: email_address debe ser un correo válido.');
            }
            $path = '/lists/' . rawurlencode($p['list_id']) . '/members/' . md5(strtolower($p['email_address']));
            if ($operation === 'manage_tags') {
                $tags = $this->jsonValue($p['tags'] ?? null);
                if (!is_array($tags) || !array_is_list($tags) || count($tags) < 1 || count($tags) > 50) {
                    throw new InvalidArgumentException('Mailchimp: tags requiere una lista de 1 a 50 objetos name/status.');
                }
                $clean = [];
                $names = [];
                foreach ($tags as $tag) {
                    $tag = $tag instanceof \stdClass ? (array) $tag : $tag;
                    if (!is_array($tag) || array_diff(array_keys($tag), ['name', 'status']) || !isset($tag['name'], $tag['status'])
                        || !is_string($tag['name']) || trim($tag['name']) === '' || strlen($tag['name']) > 100 || preg_match('//u', $tag['name']) !== 1
                        || !in_array($tag['status'], ['active', 'inactive'], true) || in_array($tag['name'], $names, true)) {
                        throw new InvalidArgumentException('Mailchimp: cada tag requiere name único y status active/inactive.');
                    }
                    $names[] = $tag['name'];
                    $clean[] = ['name' => $tag['name'], 'status' => $tag['status']];
                }
                return [$operation, 'POST', $path . '/tags', ['tags' => $clean, 'is_syncing' => true]];
            }
            $status = array_key_exists('status_if_new', $p) ? $p['status_if_new'] : 'transactional';
            $consent = array_key_exists('new_contact_consent', $p) ? $p['new_contact_consent'] : 'no';
            if (!in_array($status, ['transactional', 'pending', 'subscribed'], true) || !in_array($consent, ['no', 'yes'], true)
                || ($status !== 'transactional' && $consent !== 'yes')) {
                throw new InvalidArgumentException('Mailchimp: nuevos contactos subscribed/pending requieren consentimiento explícito; existentes conservan su estado.');
            }
            // status_if_new never overwrites the consent/status of an existing subscriber.
            $body = ['email_address' => $p['email_address'], 'status_if_new' => $status];
            if (array_key_exists('merge_fields', $p) && $p['merge_fields'] !== '') {
                $fields = $this->objectValue($p['merge_fields']);
                foreach ($fields as $key => $value) {
                    if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,50}$/D', $key) !== 1) {
                        throw new InvalidArgumentException('Mailchimp: clave de merge_fields inválida.');
                    }
                    $this->validMergeValue($value);
                }
                $body['merge_fields'] = (object) $fields;
            }
            $this->boundedJson($body);
            return [$operation, 'PUT', $path, $body];
        }
        if ($operation !== 'create_campaign' && !$this->validId($p['campaign_id'] ?? null)) {
            throw new InvalidArgumentException('Mailchimp: campaign_id requerido e inválido.');
        }
        $path = $operation === 'create_campaign' ? '/campaigns' : '/campaigns/' . rawurlencode($p['campaign_id']);
        if ($operation === 'get_campaign') {
            return [$operation, 'GET', $path, null];
        }
        $settings = $this->objectValue($p['campaign_settings'] ?? null);
        if ($settings === [] || array_diff(array_keys($settings), ['subject_line', 'preview_text', 'title', 'from_name', 'reply_to'])) {
            throw new InvalidArgumentException('Mailchimp: campaign_settings requiere campos subject_line/preview_text/title/from_name/reply_to.');
        }
        foreach ($settings as $key => $value) {
            if (!is_string($value) || strlen($value) > 4096 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)
                || ($key === 'reply_to' && filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
                throw new InvalidArgumentException('Mailchimp: settings deben ser textos válidos y reply_to un correo.');
            }
        }
        if ($operation === 'create_campaign') {
            foreach (['subject_line', 'from_name', 'reply_to'] as $key) {
                if (!isset($settings[$key]) || trim($settings[$key]) === '') {
                    throw new InvalidArgumentException('Mailchimp: el borrador requiere subject_line, from_name y reply_to.');
                }
            }
            $body = ['type' => 'regular', 'recipients' => ['list_id' => $p['list_id']], 'settings' => (object) $settings];
        } else {
            // Omitted settings remain omitted; explicit empty title/preview/subject are sent.
            $body = ['settings' => (object) $settings];
        }
        $this->boundedJson($body);
        return [$operation, $operation === 'create_campaign' ? 'POST' : 'PATCH', $path, $body];
    }

    private function validMergeValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 16 || is_resource($value) || (is_object($value) && !$value instanceof \stdClass)
            || (is_float($value) && !is_finite($value)) || (is_array($value) && $value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Mailchimp: merge_fields requiere valores JSON escalares u objetos válidos.');
        }
        if (is_array($value) || $value instanceof \stdClass) {
            foreach ($value as $child) {
                $this->validMergeValue($child, $depth + 1);
            }
        }
    }

    private function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) === 1;
    }

    private function jsonValue(mixed $value): mixed
    {
        if (is_string($value)) {
            try {
                return json_decode($value, false, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Mailchimp: JSON de entrada inválido.');
            }
        }
        return $value;
    }

    private function objectValue(mixed $value): array
    {
        $value = $this->jsonValue($value);
        if ($value instanceof \stdClass) {
            return (array) $value;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Mailchimp: se requiere un objeto de campos, array asociativo o JSON.');
        }
        return $value;
    }

    private function boundedJson(array $body): void
    {
        try {
            $raw = json_encode($body, JSON_THROW_ON_ERROR, 32);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Mailchimp: valores JSON inválidos o demasiado anidados.');
        }
        if (strlen($raw) > 1048576) {
            throw new InvalidArgumentException('Mailchimp: la entrada supera 1 MiB.');
        }
    }

    private function credentials(string $id): array
    {
        try {
            $credential = ($this->credentialManager ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('Mailchimp: fallo del gestor de credenciales (almacenamiento o descifrado), anterior a contactar Mailchimp.');
        }
        if ($credential === null) {
            throw new RuntimeException('Mailchimp: credencial inexistente.');
        }
        if (($credential['type'] ?? null) !== 'generic_bearer') {
            throw new RuntimeException('Mailchimp: se requiere credencial generic_bearer.');
        }
        $raw = $credential['data']['api_key'] ?? null;
        $config = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($config) || !isset($config['server_prefix'], $config['token']) || !is_string($config['server_prefix'])
            || preg_match('/^us[1-9][0-9]{0,2}$/D', $config['server_prefix']) !== 1 || !is_string($config['token'])
            || strlen($config['token']) > 8192 || preg_match('#^[A-Za-z0-9._+~/-]+=*$#D', $config['token']) !== 1) {
            throw new RuntimeException('Mailchimp: API Key debe contener JSON con server_prefix (us...) y token válidos.');
        }
        return ['https://' . $config['server_prefix'] . '.api.mailchimp.com/3.0', $config['token']];
    }

    private function request(string $method, string $url, ?array $body, string $token, int $expected = 200): array
    {
        $write = $method !== 'GET';
        $uncertain = $write ? ' Resultado de escritura incierto; comprueba Mailchimp antes de repetir.' : '';
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        try {
            $response = ($this->client ?? new Client())->request($method, $url, $options);
        } catch (\Throwable $e) {
            throw new RuntimeException('Mailchimp: fallo de transporte, sin reintentos.' . $uncertain);
        }
        $status = $response->getStatusCode();
        if ($status !== $expected) {
            $response->getBody()->close();
            $reason = match ($status) {
                400, 422 => 'campos inválidos o contacto con restricciones de cumplimiento',
                401 => 'autenticación rechazada o token revocado', 403 => 'rol o permisos insuficientes',
                404 => 'audiencia, contacto o campaña inexistente', 429 => 'cuota o concurrencia alcanzada',
                301, 302, 307, 308 => 'redirección bloqueada; comprueba server_prefix',
                default => 'respuesta remota inesperada'
            };
            throw new RuntimeException('Mailchimp: HTTP ' . $status . ', ' . $reason . '. Sin reintentos.' . ($write && $status >= 500 ? $uncertain : ''));
        }
        try {
            $raw = '';
            $stream = $response->getBody();
            while (!$stream->eof()) {
                $chunk = $stream->read(min(65536, 2097153 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Lectura incompleta.');
                }
                $raw .= $chunk;
                if (strlen($raw) > 2097152) {
                    throw new RuntimeException('Respuesta excesiva.');
                }
            }
            if ($expected === 204) {
                if ($raw !== '') {
                    throw new RuntimeException('Respuesta 204 con cuerpo.');
                }
                return ['success' => true, 'status_code' => 204, 'data' => []];
            }
            $data = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            if (!$data instanceof \stdClass || isset($data->detail) || isset($data->errors) || isset($data->error)
                || (isset($data->status) && !is_string($data->status))) {
                throw new RuntimeException('Error funcional.');
            }
            // No server-supplied link is followed; omit unnecessary link metadata from history.
            unset($data->_links);
            $safe = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (str_contains($safe, $token)) {
                throw new RuntimeException('Secreto reflejado.');
            }
            return ['success' => true, 'status_code' => $status, 'data' => (array) $data];
        } catch (\Throwable $e) {
            throw new RuntimeException('Mailchimp: respuesta inválida, insegura, incompleta o superior a 2 MiB.' . $uncertain);
        } finally {
            $response->getBody()->close();
        }
    }
}
