<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class IntercomNode implements NodeInterface
{
    private const HOSTS = ['us' => 'https://api.intercom.io', 'eu' => 'https://api.eu.intercom.io', 'au' => 'https://api.au.intercom.io'];
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
            'type' => 'intercom', 'name' => 'Intercom', 'category' => 'Community',
            'description' => 'Crea, actualiza y busca contactos; envía mensajes reales por email o in-app.',
            'icon' => 'MessagesSquare', 'is_start' => false,
            'help_text' => 'API fijada en 2.17. Credencial generic_bearer con access token Intercom en data.api_key sin Bearer: token de app privada del workspace u OAuth autorizado; sin renovación automática. Región debe coincidir con el workspace. Crear requiere elegir user/lead; user exige email o external_id. contact_data acepta objeto, array asociativo o JSON: email, external_id, name, phone, language_override, custom_attributes. Actualizar requiere ID de Intercom, envía solo claves presentes; name/phone admiten vacío o null explícito. No cambia role en una actualización ni consentimiento, bajas, rebotes o verificación de email. Atributos personalizados deben existir y aceptar el tipo enviado. Buscar usa igualdad exacta por email/external_id, una página de 1–150; starting_after continúa. Resultados pueden tardar minutos en indexarse; no hay upsert, selección automática ni merge de duplicados. send_message usa admin_id como remitente y contact_id/recipient_role como destino. Canal email exige subject y email válido en el contacto; in_app crea un mensaje Messenger real (requiere Messenger y destinatario accesible), no una nota ni una campaña/push. La creación no confirma entrega/visualización y puede no abrir conversación hasta respuesta. API devuelve message_type inapp para petición in_app. Permisos del token: lectura/escritura de contactos y envío de mensajes según operación. Errores detienen flujo; sin reintentos, revisa Intercom antes de repetir una escritura incierta. Salida data.id o data.data y pagination/duplicates_detected. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Intercom (Bearer Token / API Key)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'search_contacts', 'options' => [
                    ['value' => 'create_contact', 'label' => 'Crear contacto'], ['value' => 'update_contact', 'label' => 'Actualizar contacto por ID'],
                    ['value' => 'search_contacts', 'label' => 'Buscar contactos (igualdad exacta)'], ['value' => 'send_message', 'label' => 'Enviar mensaje de admin']
                ]],
                'region' => ['type' => 'select', 'required' => false, 'label' => 'Región del workspace', 'default' => 'us', 'options' => [
                    ['value' => 'us', 'label' => 'Estados Unidos'], ['value' => 'eu', 'label' => 'Unión Europea'], ['value' => 'au', 'label' => 'Australia']
                ]],
                'contact_role' => ['type' => 'select', 'required' => false, 'label' => 'Rol al crear (selección obligatoria)', 'options' => [
                    ['value' => '', 'label' => 'Seleccionar rol'], ['value' => 'user', 'label' => 'Usuario'], ['value' => 'lead', 'label' => 'Lead']
                ]],
                'contact_id' => ['type' => 'string', 'required' => false, 'label' => 'ID Intercom del contacto (actualizar/enviar)'],
                'contact_data' => ['type' => 'textarea', 'required' => false, 'label' => 'Datos del contacto (objeto JSON; crear/actualizar)', 'placeholder' => '{"email":"demo@example.com","name":"Demo","custom_attributes":{"plan":"basic"}}'],
                'search_field' => ['type' => 'select', 'required' => false, 'label' => 'Campo de búsqueda', 'default' => 'email', 'options' => [
                    ['value' => 'email', 'label' => 'Email'], ['value' => 'external_id', 'label' => 'ID externo']
                ]],
                'search_value' => ['type' => 'string', 'required' => false, 'label' => 'Valor exacto de búsqueda'],
                'per_page' => ['type' => 'number', 'required' => false, 'label' => 'Coincidencias por página (1–150)', 'default' => 50],
                'starting_after' => ['type' => 'string', 'required' => false, 'label' => 'Cursor siguiente (starting_after)'],
                'message_type' => ['type' => 'select', 'required' => false, 'label' => 'Canal del mensaje (selección obligatoria)', 'options' => [
                    ['value' => '', 'label' => 'Seleccionar canal'], ['value' => 'in_app', 'label' => 'Mensaje in-app Messenger'], ['value' => 'email', 'label' => 'Email']
                ]],
                'admin_id' => ['type' => 'string', 'required' => false, 'label' => 'ID numérico del admin remitente'],
                'recipient_role' => ['type' => 'select', 'required' => false, 'label' => 'Rol del destinatario (enviar)', 'options' => [
                    ['value' => '', 'label' => 'Seleccionar rol'], ['value' => 'user', 'label' => 'Usuario'], ['value' => 'lead', 'label' => 'Lead']
                ]],
                'subject' => ['type' => 'string', 'required' => false, 'label' => 'Asunto (obligatorio en email)'],
                'body' => ['type' => 'textarea', 'required' => false, 'label' => 'Cuerpo del mensaje (texto o HTML)']
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
        // Engine ya resuelve expresiones; validar el resultado sin una segunda interpolación.
        $prepared = $this->prepareParameters($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($prepared['credential_id']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Intercom: fallo de almacenamiento o descifrado de credencial; revisa el gestor.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Intercom: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token) || strlen($token) > 8192
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1) {
            throw new InvalidArgumentException('Intercom: usa generic_bearer con un access token en data.api_key sin prefijo Bearer.');
        }
        $writing = $prepared['operation'] !== 'search_contacts';
        try {
            $response = ($this->client ?? new Client())->request($prepared['method'],
                self::HOSTS[$prepared['region']] . $prepared['path'], [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Intercom-Version' => '2.17'],
                    'json' => $prepared['payload'], 'verify' => true, 'timeout' => 30, 'connect_timeout' => 10,
                    'allow_redirects' => false, 'http_errors' => false, 'stream' => true
                ]);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            throw new RuntimeException('Intercom: fallo de transporte.' . ($writing
                ? ' El resultado de la escritura es incierto; comprueba Intercom antes de repetir. No se reintentó.' : ' No se reintentó la búsqueda.'));
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'token inválido o revocado; revisa la credencial y la región del workspace',
                403 => 'permisos insuficientes o canal no autorizado; revisa permisos de contactos/mensajes y el admin remitente',
                404 => 'contacto o admin inexistente, inaccesible o aún no disponible tras su creación',
                409 => 'contacto duplicado o conflicto; busca las coincidencias y elige un ID, sin crear ni fusionar automáticamente',
                429 => 'cuota o límite de solicitudes alcanzado; no se reintentó',
                400, 422 => 'datos rechazados; revisa identidad, atributos, roles, destinatario y canal',
                default => 'solicitud rechazada o servicio no disponible; revisa el recurso antes de repetir'
            };
            throw new RuntimeException("Intercom: {$reason} (HTTP {$status}).", $status);
        }
        $data = $this->readJson($response->getBody(), $writing);
        if (($data['type'] ?? null) === 'error.list' || array_key_exists('errors', $data) || array_key_exists('error', $data)) {
            throw new RuntimeException('Intercom: la respuesta contiene un error funcional; revisa permisos y datos antes de repetir.');
        }
        $result = ['success' => true, 'status_code' => $status];
        if ($prepared['operation'] === 'search_contacts') {
            if ($status !== 200 || ($data['type'] ?? null) !== 'list' || !is_array($data['data'] ?? null)
                || !array_is_list($data['data']) || count($data['data']) > $prepared['per_page']) {
                throw new RuntimeException('Intercom: respuesta de búsqueda inválida o superior al límite solicitado.');
            }
            $total = $data['total_count'] ?? null;
            if (!is_int($total) || $total < count($data['data'])) {
                throw new RuntimeException('Intercom: total de coincidencias inválido.');
            }
            $cursor = $data['pages']['next']['starting_after'] ?? null;
            if ($cursor !== null && (!is_string($cursor) || !$this->validCursor($cursor))) {
                throw new RuntimeException('Intercom: cursor de respuesta inválido.');
            }
            $result['pagination'] = ['next_starting_after' => $cursor, 'has_more' => $cursor !== null && $cursor !== '',
                'per_page' => $prepared['per_page'], 'returned_count' => count($data['data']), 'total_count' => $total];
            $result['duplicates_detected'] = $total > 1;
            $result['match_status'] = $total === 0 ? 'none' : ($total === 1 ? 'single' : 'multiple');
        } elseif (!in_array($status, [200, 201], true) || !$this->validId($data['id'] ?? null)) {
            throw new RuntimeException('Intercom: no confirmó el ID del recurso; comprueba Intercom antes de repetir.');
        } elseif ($prepared['operation'] === 'send_message') {
            $expected = $prepared['message_type'] === 'in_app' ? 'inapp' : 'email';
            if (($data['message_type'] ?? null) !== $expected) {
                throw new RuntimeException('Intercom: el canal de respuesta no corresponde al mensaje solicitado; comprueba Intercom antes de repetir.');
            }
        } elseif (($data['type'] ?? null) !== 'contact'
            || ($prepared['operation'] === 'update_contact' && $data['id'] !== $prepared['contact_id'])
            || ($prepared['operation'] === 'create_contact' && ($data['role'] ?? null) !== $prepared['payload']['role'])) {
            throw new RuntimeException('Intercom: respuesta de contacto incompatible; comprueba Intercom antes de repetir.');
        }
        $result['data'] = $this->sanitize($data, $token);
        if (isset($result['pagination']['next_starting_after']) && str_contains($result['pagination']['next_starting_after'], $token)) {
            throw new RuntimeException('Intercom: cursor de respuesta inseguro.');
        }
        return $result;
    }

    private function readJson(\Psr\Http\Message\StreamInterface $stream, bool $writing): array
    {
        try {
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
            if (!$object instanceof \stdClass || (property_exists($object, 'data') && !is_array($object->data))) {
                throw new RuntimeException();
            }
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('Intercom: respuesta JSON inválida, vacía o superior a 2 MiB.'
                . ($writing ? ' El recurso puede existir; comprueba Intercom antes de repetir.' : ''));
        }
    }

    private function prepareParameters(array $parameters): array
    {
        $credentialId = $parameters['credential_id'] ?? null;
        if (!is_string($credentialId) || trim($credentialId) === '' || strlen($credentialId) > 200) {
            throw new InvalidArgumentException('Intercom: credential_id es obligatorio y debe ser texto.');
        }
        $operation = $parameters['operation'] ?? 'search_contacts';
        if (!is_string($operation) || !in_array($operation, ['create_contact', 'update_contact', 'search_contacts', 'send_message'], true)) {
            throw new InvalidArgumentException('Intercom: operación inválida.');
        }
        $region = $parameters['region'] ?? 'us';
        if (!is_string($region) || !array_key_exists($region, self::HOSTS)) {
            throw new InvalidArgumentException('Intercom: region debe ser us, eu o au.');
        }
        $prepared = ['credential_id' => $credentialId, 'operation' => $operation, 'region' => $region, 'method' => 'POST'];
        if ($operation === 'search_contacts') {
            $field = $parameters['search_field'] ?? 'email';
            $value = $parameters['search_value'] ?? null;
            if (!is_string($field) || !in_array($field, ['email', 'external_id'], true)
                || !$this->validText($value, 320, false) || ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL))) {
                throw new InvalidArgumentException('Intercom: búsqueda requiere email válido o external_id de texto no vacío.');
            }
            $perPage = $parameters['per_page'] ?? 50;
            if (is_string($perPage) && preg_match('/^[1-9][0-9]{0,2}$/D', $perPage)) {
                $perPage = (int) $perPage;
            }
            if (!is_int($perPage) || $perPage < 1 || $perPage > 150) {
                throw new InvalidArgumentException('Intercom: per_page debe ser un entero entre 1 y 150.');
            }
            $cursor = $parameters['starting_after'] ?? '';
            if (!is_string($cursor) || !$this->validCursor($cursor)) {
                throw new InvalidArgumentException('Intercom: starting_after debe ser un cursor válido, no una URL.');
            }
            $pagination = ['per_page' => $perPage];
            if ($cursor !== '') {
                $pagination['starting_after'] = $cursor;
            }
            return $prepared + ['path' => '/contacts/search', 'per_page' => $perPage,
                'payload' => ['query' => ['field' => $field, 'operator' => '=', 'value' => $value], 'pagination' => $pagination]];
        }
        if ($operation === 'create_contact' || $operation === 'update_contact') {
            $data = $this->contactData(array_key_exists('contact_data', $parameters) ? $parameters['contact_data'] : '{}');
            if ($operation === 'create_contact') {
                $role = $parameters['contact_role'] ?? null;
                if (!in_array($role, ['user', 'lead'], true)) {
                    throw new InvalidArgumentException('Intercom: elige explícitamente contact_role user o lead al crear.');
                }
                if ($role === 'user' && !isset($data['email']) && !isset($data['external_id'])) {
                    throw new InvalidArgumentException('Intercom: un user requiere email o external_id.');
                }
                return $prepared + ['path' => '/contacts', 'payload' => ['role' => $role] + $data];
            }
            $contactId = $parameters['contact_id'] ?? null;
            if (!$this->validId($contactId) || $data === []) {
                throw new InvalidArgumentException('Intercom: actualizar requiere contact_id y al menos un campo permitido en contact_data.');
            }
            $prepared['method'] = 'PUT';
            return $prepared + ['path' => '/contacts/' . rawurlencode($contactId), 'payload' => $data, 'contact_id' => $contactId];
        }
        $channel = $parameters['message_type'] ?? null;
        $role = $parameters['recipient_role'] ?? null;
        $contactId = $parameters['contact_id'] ?? null;
        $adminId = $parameters['admin_id'] ?? null;
        if (is_string($adminId) && preg_match('/^[1-9][0-9]{0,17}$/D', $adminId)) {
            $adminId = (int) $adminId;
        }
        if (!in_array($channel, ['email', 'in_app'], true) || !in_array($role, ['user', 'lead'], true)
            || !$this->validId($contactId) || !is_int($adminId) || $adminId < 1) {
            throw new InvalidArgumentException('Intercom: enviar requiere canal email/in_app, recipient_role user/lead, contact_id y admin_id entero positivo.');
        }
        $body = $parameters['body'] ?? null;
        if (!$this->validText($body, 32768, false)) {
            throw new InvalidArgumentException('Intercom: body requiere texto UTF-8 no vacío de hasta 32768 bytes.');
        }
        $payload = ['message_type' => $channel, 'body' => $body, 'template' => 'plain',
            'from' => ['type' => 'admin', 'id' => $adminId], 'to' => ['type' => $role, 'id' => $contactId],
            'create_conversation_without_contact_reply' => false];
        if ($channel === 'email') {
            $subject = $parameters['subject'] ?? null;
            if (!$this->validText($subject, 255, false) || strpbrk($subject, "\r\n") !== false) {
                throw new InvalidArgumentException('Intercom: email requiere subject no vacío, sin saltos de línea y de hasta 255 bytes.');
            }
            $payload['subject'] = $subject;
        }
        return $prepared + ['path' => '/messages', 'payload' => $payload, 'message_type' => $channel];
    }

    private function contactData(mixed $input): array
    {
        if (is_string($input)) {
            if (strlen($input) > 65536) {
                throw new InvalidArgumentException('Intercom: contact_data supera el límite local de 64 KiB.');
            }
            try {
                $input = json_decode($input, false, 16, JSON_THROW_ON_ERROR);
                if (!$input instanceof \stdClass) {
                    throw new InvalidArgumentException();
                }
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Intercom: contact_data debe ser un objeto JSON válido.');
            }
        }
        if ($input instanceof \stdClass) {
            $input = get_object_vars($input);
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new InvalidArgumentException('Intercom: contact_data requiere objeto o array asociativo.');
        }
        $allowed = ['email', 'external_id', 'name', 'phone', 'language_override', 'custom_attributes'];
        foreach ($input as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Intercom: contact_data incluye un campo no permitido; no se modifican roles, verificación ni consentimiento.');
            }
            if ($key === 'custom_attributes') {
                if ($value instanceof \stdClass) {
                    $value = get_object_vars($value);
                }
                if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 100) {
                    throw new InvalidArgumentException('Intercom: custom_attributes debe ser un objeto con hasta 100 atributos.');
                }
                foreach ($value as $attribute => $item) {
                    if (!is_string($attribute) || !$this->validText($attribute, 128, false)
                        || (!is_null($item) && !is_scalar($item)) || (is_float($item) && !is_finite($item))
                        || (is_string($item) && !$this->validText($item, 4096, true))) {
                        throw new InvalidArgumentException('Intercom: los atributos requieren nombres de texto y valores escalares o null válidos.');
                    }
                }
                $input[$key] = (object) $value;
            } elseif ($key === 'email') {
                if (!is_string($value) || strlen($value) > 320 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Intercom: email debe ser una dirección válida no vacía.');
                }
            } elseif ($key === 'external_id') {
                if (!$this->validText($value, 255, false)) {
                    throw new InvalidArgumentException('Intercom: external_id requiere texto no vacío de hasta 255 bytes.');
                }
            } elseif ($key === 'phone') {
                if ($value !== null && $value !== '' && (!is_string($value) || preg_match('/^\+[1-9][0-9]{1,14}$/D', $value) !== 1)) {
                    throw new InvalidArgumentException('Intercom: phone debe ser E.164, texto vacío o null explícito.');
                }
            } elseif ($value !== null && !$this->validText($value, $key === 'name' ? 255 : 32, true)) {
                throw new InvalidArgumentException('Intercom: name/language_override requieren texto UTF-8 válido o null explícito.');
            }
        }
        try {
            if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 65536) {
                throw new InvalidArgumentException('Intercom: contact_data supera el límite local de 64 KiB.');
            }
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Intercom: contact_data contiene datos JSON inválidos.');
        }
        return $input;
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) === 1;
    }

    private function validText(mixed $value, int $maxBytes, bool $allowEmpty): bool
    {
        return is_string($value) && strlen($value) <= $maxBytes && mb_check_encoding($value, 'UTF-8')
            && !str_contains($value, "\0") && ($allowEmpty || trim($value) !== '');
    }

    private function validCursor(string $value): bool
    {
        return strlen($value) <= 2048 && ($value === '' || preg_match('/^[A-Za-z0-9_+\/=.-]+$/D', $value) === 1);
    }

    private function sanitize(mixed $value, string $token): mixed
    {
        if (is_string($value)) {
            return str_replace([$token, rawurlencode($token)], '[REDACTED]', $value);
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key) ? str_replace($token, '[REDACTED]', $key) : $key;
                $safe[$safeKey] = is_string($key) && preg_match('/^(?:authorization|access_token|refresh_token|api_key|client_secret)$/iD', $key)
                    ? '[REDACTED]' : $this->sanitize($item, $token);
            }
            return $safe;
        }
        return $value;
    }
}
