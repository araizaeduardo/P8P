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

class BrevoNode implements NodeInterface
{
    private ?ClientInterface $client;
    private ?CredentialManager $credentials;
    private const OPERATIONS = ['create_contact', 'upsert_contact', 'update_contact', 'list_campaigns', 'get_campaign', 'create_campaign', 'update_campaign', 'send_campaign'];

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null)
    {
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'brevo', 'name' => 'Brevo', 'category' => 'Community', 'icon' => 'Mail', 'is_start' => false,
            'description' => 'Gestiona contactos, valores de atributos, listas y campañas de email de Brevo.',
            'help_text' => 'Credencial generic_bearer con API Key de Brevo (no token OAuth). Contactos: crear/upsert identifican por email; actualizar por contact_identifier y tipo. attributes es objeto JSON con atributos existentes EN MAYÚSCULAS y valores texto/número/bool/lista de textos; EMAIL está prohibido porque cambiarlo puede resuscribir bloqueados. No gestiona definiciones, consentimiento, bajas, bloqueos ni fusión. list_ids añade listas explícitamente, [] no elimina membresías. Actualizar conserva campos omitidos; texto vacío y listas de valores [] se envían sin convertirlos en omisión. Brevo puede ignorar valores cuyo tipo no coincide con el atributo de la cuenta. Campañas: campaign_fields usa name, subject, sender, html_content, template_id (solo crear), recipient_list_ids, exclude_list_ids, reply_to, preview_text, header, footer, tag. sender es objeto con id o email y name opcional. Crear requiere name, subject, sender y html_content (>10 caracteres, <1MiB) o template_id activo y crea borrador. Actualizar envía solo claves presentes; header/footer/preview_text/tag vacíos son explícitos. No programa ni envía al crear/actualizar. send_campaign exige confirm_send=send_now. Una página por llamada, máximo 100 campañas; salida resume metadatos sin HTML ni enlaces compartidos. Sin reintentos automáticos. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Brevo API Key'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'create_contact', 'options' => [
                    ['value' => 'create_contact', 'label' => 'Crear contacto'], ['value' => 'upsert_contact', 'label' => 'Crear o actualizar contacto'],
                    ['value' => 'update_contact', 'label' => 'Actualizar contacto'], ['value' => 'list_campaigns', 'label' => 'Listar campañas'],
                    ['value' => 'get_campaign', 'label' => 'Obtener campaña'], ['value' => 'create_campaign', 'label' => 'Crear campaña (borrador)'],
                    ['value' => 'update_campaign', 'label' => 'Actualizar campaña'], ['value' => 'send_campaign', 'label' => 'Enviar campaña ahora']
                ]],
                'email' => ['type' => 'string', 'required' => false, 'label' => 'Email (crear / upsert contacto)'],
                'contact_identifier' => ['type' => 'string', 'required' => false, 'label' => 'Identificador del contacto (actualizar)'],
                'identifier_type' => ['type' => 'select', 'required' => false, 'label' => 'Tipo de identificador de contacto', 'default' => 'email_id', 'options' => [
                    ['value' => 'email_id', 'label' => 'Email'], ['value' => 'contact_id', 'label' => 'ID de contacto'], ['value' => 'ext_id', 'label' => 'ID externo']
                ]],
                'attributes' => ['type' => 'textarea', 'required' => false, 'label' => 'Valores de atributos existentes (objeto JSON)', 'placeholder' => '{"FIRSTNAME":"Ana","SCORE":0,"ACTIVE":false}'],
                'list_ids' => ['type' => 'textarea', 'required' => false, 'label' => 'Listas a las que añadir el contacto (lista JSON de IDs)', 'placeholder' => '[2,3]'],
                'campaign_id' => ['type' => 'number', 'required' => false, 'label' => 'ID de campaña (obtener / actualizar / enviar)'],
                'campaign_fields' => ['type' => 'textarea', 'required' => false, 'label' => 'Campos de campaña (objeto JSON)', 'placeholder' => '{"name":"Boletín","subject":"Novedades","sender":{"id":1},"html_content":"<p>Hola mundo</p>","recipient_list_ids":[2]}'],
                'confirm_send' => ['type' => 'select', 'required' => false, 'label' => 'Confirmación de envío de campaña', 'default' => '', 'options' => [
                    ['value' => '', 'label' => 'Sin autorización de envío'], ['value' => 'send_now', 'label' => 'Enviar esta campaña ahora']
                ]],
                'limit' => ['type' => 'number', 'required' => false, 'label' => 'Campañas por página (1–100)', 'default' => 20],
                'offset' => ['type' => 'number', 'required' => false, 'label' => 'Offset (listar)', 'default' => 0],
                'campaign_type' => ['type' => 'select', 'required' => false, 'label' => 'Filtro tipo (listar)', 'default' => '', 'options' => [
                    ['value' => '', 'label' => 'Todos'], ['value' => 'classic', 'label' => 'Classic'], ['value' => 'trigger', 'label' => 'Trigger']
                ]],
                'sort' => ['type' => 'select', 'required' => false, 'label' => 'Orden (listar)', 'default' => 'desc', 'options' => [['value' => 'desc', 'label' => 'Descendente'], ['value' => 'asc', 'label' => 'Ascendente']]]
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
        // Engine usa ExpressionEvaluator: validar aquí los tipos resueltos sin segunda interpolación.
        $request = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($request['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('Brevo: no se pudo recuperar o descifrar la credencial en p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Brevo: la credencial no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || !preg_match('/^[A-Za-z0-9_.-]{8,512}$/D', $token)) {
            throw new InvalidArgumentException('Brevo: selecciona generic_bearer con una API Key de Brevo válida, sin JSON ni Bearer.');
        }
        $options = [
            'headers' => ['Accept' => 'application/json', 'api-key' => $token],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false, 'stream' => true,
            'query' => $request['query']
        ];
        if ($request['body'] !== null) {
            $options['json'] = (object) $request['body'];
        }
        try {
            $response = ($this->client ?? new Client())->request($request['method'], 'https://api.brevo.com/v3' . $request['path'], $options);
            $status = $response->getStatusCode();
            $stream = $response->getBody();
            $body = '';
            while (!$stream->eof() && strlen($body) <= 2097152) {
                $chunk = $stream->read(min(8192, 2097153 - strlen($body)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Lectura incompleta.');
                }
                $body .= $chunk;
            }
            if (strlen($body) > 2097152) {
                throw new RuntimeException('Respuesta demasiado grande.');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Brevo: fallo de transporte o lectura; resultado incierto. Comprueba Brevo antes de repetir una escritura o envío para evitar duplicados.');
        }
        if ($status < 200 || $status >= 300) {
            $category = match ($status) {
                401 => 'autenticación rechazada', 402 => 'créditos o saldo insuficientes',
                403 => 'permisos, cuenta o remitente no autorizados', 404 => 'recurso inexistente o inaccesible',
                409 => 'conflicto o contacto duplicado', 429 => 'cuota o límite de solicitudes',
                default => 'solicitud rechazada por el proveedor'
            };
            throw new RuntimeException('Brevo: ' . $category . ' (HTTP ' . $status . ').');
        }
        $operation = $request['operation'];
        $allowEmpty = in_array($operation, ['upsert_contact', 'update_contact', 'update_campaign', 'send_campaign'], true);
        if ($body === '' && $status === 204 && $allowEmpty) {
            $data = ['accepted' => true];
            if (isset($request['campaign_id'])) {
                $data['id'] = $request['campaign_id'];
            }
        } else {
            try {
                $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new RuntimeException('Brevo: respuesta JSON inválida; comprueba el resultado antes de repetir.');
            }
            if (!is_array($data) || array_is_list($data) || isset($data['code']) || isset($data['error'])
                || ($data['success'] ?? true) === false) {
                throw new RuntimeException('Brevo: respuesta inválida o error funcional del proveedor.');
            }
            if ($operation === 'list_campaigns') {
                if (!is_array($data['campaigns'] ?? null) || !array_is_list($data['campaigns'])
                    || count($data['campaigns']) > $request['query']['limit']) {
                    throw new RuntimeException('Brevo: página de campañas inválida o excede el límite solicitado.');
                }
                $campaigns = array_map(fn($campaign) => $this->campaignResult($campaign), $data['campaigns']);
                $returned = count($campaigns);
                // No seguir páginas ni confiar en URLs del proveedor; offset siempre local.
                $data = ['campaigns' => $campaigns, 'pagination' => [
                    'limit' => $request['query']['limit'], 'offset' => $request['query']['offset'], 'returned' => $returned,
                    'has_more' => $returned === $request['query']['limit'] ? null : false,
                    'next_offset' => $returned === $request['query']['limit'] ? $request['query']['offset'] + $returned : null
                ]];
            } elseif ($operation === 'get_campaign') {
                $data = $this->campaignResult($data);
            } elseif (in_array($operation, ['create_contact', 'upsert_contact', 'create_campaign'], true)) {
                if (!$this->positiveId($data['id'] ?? null)) {
                    throw new RuntimeException('Brevo: respuesta de creación sin ID válido; comprueba el resultado antes de repetir.');
                }
                $data = ['id' => $data['id'], 'accepted' => true];
            } else {
                // Actualización y sendNow documentan 204 sin cuerpo.
                throw new RuntimeException('Brevo: respuesta inesperada para una operación que requiere HTTP 204 vacío.');
            }
        }
        return ['success' => true, 'status_code' => $status, 'data' => $this->redact($data, $token)];
    }

    private function prepare(array $p): array
    {
        $credentialId = $this->text($p['credential_id'] ?? null, 255);
        $operation = $p['operation'] ?? 'create_contact';
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('Brevo: operación inválida.');
        }
        foreach (['scheduled_at', 'scheduledAt', 'sendAt', 'send_at', 'sendAtBestTime', 'send_at_best_time', 'emailBlacklisted', 'smsBlacklisted', 'smtpBlacklistSender', 'unlinkListIds', 'forceMerge', 'email_blacklisted', 'sms_blacklisted', 'consent'] as $unsupported) {
            if (array_key_exists($unsupported, $p)) {
                throw new InvalidArgumentException('Brevo: el nodo no permite programación, fusión ni cambios de consentimiento, bajas o bloqueos.');
            }
        }
        $request = ['credential_id' => $credentialId, 'operation' => $operation, 'method' => 'GET', 'path' => '/emailCampaigns', 'query' => [], 'body' => null];
        if (in_array($operation, ['create_contact', 'upsert_contact', 'update_contact'], true)) {
            $body = [];
            if (array_key_exists('attributes', $p) && $p['attributes'] !== '') {
                $attributes = $this->object($p['attributes']);
                if (count($attributes) > 100) {
                    throw new InvalidArgumentException('Brevo: máximo local de 100 atributos por operación.');
                }
                foreach ($attributes as $name => $value) {
                    if (!is_string($name) || !preg_match('/^[A-Z][A-Z0-9_]{0,49}$/D', $name) || $name === 'EMAIL') {
                        throw new InvalidArgumentException('Brevo: atributos requieren nombres en mayúsculas y no pueden modificar EMAIL.');
                    }
                    $this->attributeValue($value);
                    if (in_array($name, ['SMS', 'WHATSAPP', 'LANDLINE_NUMBER'], true) && $value !== ''
                        && (!is_string($value) || !preg_match('/^\+[1-9][0-9]{1,14}$/D', $value))) {
                        throw new InvalidArgumentException('Brevo: atributos telefónicos requieren E.164 o texto vacío explícito.');
                    }
                }
                $body['attributes'] = (object) $attributes;
            }
            if (array_key_exists('list_ids', $p) && $p['list_ids'] !== '') {
                $body['listIds'] = $this->idList($p['list_ids']);
            }
            if ($operation === 'update_contact') {
                $type = $p['identifier_type'] ?? 'email_id';
                $id = $p['contact_identifier'] ?? null;
                if ($type === 'email_id') {
                    $id = $this->email($id);
                } elseif ($type === 'contact_id') {
                    $id = (string) $this->integer($id, 1, 9007199254740991);
                } elseif ($type === 'ext_id') {
                    $id = $this->text($id, 255);
                } else {
                    throw new InvalidArgumentException('Brevo: tipo de identificador de contacto inválido.');
                }
                if ($body === []) {
                    throw new InvalidArgumentException('Brevo: actualizar contacto requiere attributes o list_ids proporcionados.');
                }
                $request['method'] = 'PUT';
                $request['path'] = '/contacts/' . rawurlencode($id);
                $request['query'] = ['identifierType' => $type];
            } else {
                $body['email'] = $this->email($p['email'] ?? null);
                $body['updateEnabled'] = $operation === 'upsert_contact';
                $request['method'] = 'POST';
                $request['path'] = '/contacts';
            }
            $request['body'] = $body;
        } elseif ($operation === 'list_campaigns') {
            $request['query'] = ['limit' => $this->integer($p['limit'] ?? 20, 1, 100),
                'offset' => $this->integer($p['offset'] ?? 0, 0, 1000000000), 'excludeHtmlContent' => 'true'];
            $sort = $p['sort'] ?? 'desc';
            $type = $p['campaign_type'] ?? '';
            if (!in_array($sort, ['asc', 'desc'], true) || !in_array($type, ['', 'classic', 'trigger'], true)) {
                throw new InvalidArgumentException('Brevo: orden o filtro de campañas inválido.');
            }
            $request['query']['sort'] = $sort;
            if ($type !== '') {
                $request['query']['type'] = $type;
            }
        } else {
            if ($operation !== 'create_campaign') {
                $id = $this->integer($p['campaign_id'] ?? null, 1, 9007199254740991);
                $request['campaign_id'] = $id;
                $request['path'] .= '/' . $id;
            }
            if ($operation === 'send_campaign') {
                if (($p['confirm_send'] ?? null) !== 'send_now') {
                    throw new InvalidArgumentException('Brevo: enviar requiere confirm_send=send_now explícito.');
                }
                $request['method'] = 'POST';
                $request['path'] .= '/sendNow';
            } elseif (in_array($operation, ['create_campaign', 'update_campaign'], true)) {
                $request['method'] = $operation === 'create_campaign' ? 'POST' : 'PUT';
                $request['body'] = $this->campaignFields($this->object($p['campaign_fields'] ?? null), $operation === 'create_campaign');
            }
        }
        return $request;
    }

    private function campaignFields(array $fields, bool $create): array
    {
        $map = ['name' => 'name', 'subject' => 'subject', 'sender' => 'sender', 'html_content' => 'htmlContent',
            'recipient_list_ids' => 'listIds', 'exclude_list_ids' => 'exclusionListIds', 'reply_to' => 'replyTo',
            'preview_text' => 'previewText', 'header' => 'header', 'footer' => 'footer', 'tag' => 'tag'];
        if ($create) {
            $map['template_id'] = 'templateId';
        }
        if ($fields === [] || array_diff(array_keys($fields), array_keys($map)) !== []) {
            throw new InvalidArgumentException('Brevo: campaign_fields vacío o con campos no soportados; no se admite programación.');
        }
        if ($create && (array_diff(['name', 'subject', 'sender'], array_keys($fields)) !== []
            || (array_key_exists('html_content', $fields) === array_key_exists('template_id', $fields)))) {
            throw new InvalidArgumentException('Brevo: crear campaña requiere name, subject, sender y exclusivamente html_content o template_id.');
        }
        $body = [];
        foreach ($fields as $name => $value) {
            $key = $map[$name];
            if ($name === 'sender') {
                $sender = $this->object($value);
                if (array_diff(array_keys($sender), ['id', 'email', 'name']) !== []
                    || (array_key_exists('id', $sender) === array_key_exists('email', $sender))) {
                    throw new InvalidArgumentException('Brevo: sender requiere exclusivamente id o email, y name opcional.');
                }
                if (array_key_exists('id', $sender)) {
                    $sender['id'] = $this->integer($sender['id'], 1, 9007199254740991);
                } else {
                    $sender['email'] = $this->email($sender['email']);
                }
                if (array_key_exists('name', $sender)) {
                    $sender['name'] = $this->text($sender['name'], 255, true);
                }
                $body[$key] = $sender;
            } elseif (in_array($name, ['recipient_list_ids', 'exclude_list_ids'], true)) {
                $body['recipients'][$key] = $this->idList($value);
            } elseif ($name === 'template_id') {
                $body[$key] = $this->integer($value, 1, 9007199254740991);
            } elseif ($name === 'reply_to') {
                $body[$key] = $this->email($value);
            } elseif ($name === 'html_content') {
                $body[$key] = $this->text($value, 1048575, false, true);
                if (mb_strlen($value, 'UTF-8') <= 10) {
                    throw new InvalidArgumentException('Brevo: html_content requiere más de 10 caracteres y menos de 1 MiB.');
                }
            } else {
                $body[$key] = $this->text($value, in_array($name, ['header', 'footer'], true) ? 10000 : 998,
                    in_array($name, ['header', 'footer', 'preview_text', 'tag'], true), in_array($name, ['header', 'footer'], true));
            }
        }
        return $body;
    }

    private function attributeValue(mixed $value): void
    {
        if (is_string($value)) {
            $this->text($value, 10000, true, true);
        } elseif (is_bool($value) || is_int($value)) {
            if (is_int($value) && abs((float) $value) > 9007199254740991) {
                throw new InvalidArgumentException('Brevo: valor numérico de atributo fuera del rango entero seguro.');
            }
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Brevo: atributo numérico no finito.');
            }
        } elseif (is_array($value) && array_is_list($value) && count($value) <= 100) {
            foreach ($value as $item) {
                $this->text($item, 1000, true);
            }
        } else {
            throw new InvalidArgumentException('Brevo: atributo requiere texto, número, booleano o lista de textos; null y objetos no están admitidos.');
        }
    }

    private function campaignResult(mixed $campaign): array
    {
        if (!is_array($campaign) || !$this->positiveId($campaign['id'] ?? null)) {
            throw new RuntimeException('Brevo: campaña sin ID válido en la respuesta.');
        }
        $result = array_intersect_key($campaign, array_flip(['id', 'name', 'status', 'type', 'subject', 'createdAt', 'modifiedAt', 'sender', 'recipients', 'previewText', 'replyTo', 'tag']));
        if (is_array($campaign['statistics']['globalStats'] ?? null)) {
            $result['statistics'] = ['globalStats' => $campaign['statistics']['globalStats']];
        }
        return $result;
    }

    private function redact(mixed $value, string $token): mixed
    {
        if (is_string($value)) {
            return str_replace(array_unique([$token, rawurlencode($token), urlencode($token)]), '[REDACTED]', $value);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/token|secret|password|authorization|api.?key|url|link/i', $key)) {
                    continue;
                }
                $cleanKey = is_string($key) ? $this->redact($key, $token) : $key;
                $result[$cleanKey] = $this->redact($item, $token);
            }
            return $result;
        }
        return $value;
    }

    private function idList(mixed $value): array
    {
        if (is_string($value)) {
            $value = $this->json($value);
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new InvalidArgumentException('Brevo: se requiere lista JSON de hasta 100 IDs.');
        }
        $ids = array_map(fn($id) => $this->integer($id, 1, 9007199254740991), $value);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('Brevo: IDs duplicados en la lista.');
        }
        return $ids;
    }

    private function object(mixed $value): array
    {
        if (is_string($value)) {
            $value = $this->json($value);
        }
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Brevo: se requiere un objeto JSON.');
        }
        return $value;
    }

    private function json(string $value): mixed
    {
        if (strlen($value) > 1100000) {
            throw new InvalidArgumentException('Brevo: JSON de entrada excede el límite local.');
        }
        try {
            return json_decode($value, false, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Brevo: JSON inválido o demasiado anidado.');
        }
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,15})$/D', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Brevo: ID, límite u offset debe ser un entero dentro del rango admitido.');
        }
        return $value;
    }

    private function positiveId(mixed $value): bool
    {
        return is_int($value) && $value > 0 && $value <= 9007199254740991;
    }

    private function email(mixed $value): string
    {
        $value = $this->text($value, 254);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Brevo: email simple inválido.');
        }
        return $value;
    }

    private function text(mixed $value, int $max, bool $empty = false, bool $multiline = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > $max
            || (!$empty && trim($value) === '')
            || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Brevo: texto requerido, tipo incorrecto, UTF-8 inválido o límite excedido.');
        }
        return $value;
    }
}
