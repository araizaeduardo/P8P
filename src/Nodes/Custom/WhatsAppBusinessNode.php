<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use Throwable;

class WhatsAppBusinessNode implements NodeInterface
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
            'type' => 'whatsapp_business',
            'name' => 'WhatsApp Business',
            'category' => 'Community',
            'description' => 'Envía texto, plantillas aprobadas o medios mediante WhatsApp Cloud API de Meta.',
            'icon' => 'MessageCircle',
            'is_start' => false,
            'help_text' => 'Guarda el access token de Meta en API Key de una credencial Bearer Token / API Key, sin prefijo Bearer. Requiere whatsapp_business_messaging, número registrado y acceso al activo. Indica una versión Graph soportada por tu app (formato vNN.0; no se comprueba disponibilidad). Texto y medios libres requieren ventana de atención de 24 horas: el nodo no puede comprobarla localmente. Fuera de ella usa una plantilla aprobada con idioma y parámetros coincidentes. Componentes admitidos: header/body con text; header también image/video/document; button quick_reply con payload o url con text. Máximo 10 componentes y 100 parámetros por componente, texto de parámetro hasta 1024 caracteres. No admite carruseles, flows, currency ni date_time. Medios: ID ya subido o URL HTTPS pública; JPEG/PNG, AAC/MP4/MPEG/AMR/OGG Opus, MP4/3GP, documentos compatibles o WebP según tipo. Meta verifica MIME, codecs y tamaño; el nodo no descarga ni sube archivos. Caption solo image/video/document (1024); filename solo document. Usa {{origen.campo}}. Salida data.message_id y message_status si Meta lo devuelve: aceptación no garantiza entrega. Sin reintentos automáticos. Errores detienen el flujo; comprueba envíos inciertos antes de repetir. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Meta (access token en API Key)'],
                'api_version' => ['type' => 'string', 'required' => true, 'label' => 'Versión Graph soportada por tu app (vNN.0)', 'placeholder' => 'Consulta la versión en Meta for Developers'],
                'phone_number_id' => ['type' => 'string', 'required' => true, 'label' => 'Phone Number ID de Meta (no el teléfono)'],
                'to' => ['type' => 'string', 'required' => true, 'label' => 'Destinatario internacional', 'placeholder' => '50255550123'],
                'operation' => [
                    'type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'send_text',
                    'options' => [
                        ['value' => 'send_text', 'label' => 'Enviar texto'],
                        ['value' => 'send_template', 'label' => 'Enviar plantilla aprobada'],
                        ['value' => 'send_media', 'label' => 'Enviar medio existente']
                    ]
                ],
                'text' => ['type' => 'textarea', 'required' => false, 'label' => 'Texto (1–4096 caracteres, enviar texto)'],
                'template_name' => ['type' => 'string', 'required' => false, 'label' => 'Nombre de plantilla aprobada'],
                'language' => ['type' => 'string', 'required' => false, 'label' => 'Idioma exacto de la plantilla', 'placeholder' => 'es o en_US'],
                'components' => ['type' => 'textarea', 'required' => false, 'label' => 'Componentes de plantilla (array JSON o expresión)', 'default' => '[]', 'placeholder' => '[{"type":"body","parameters":[{"type":"text","text":"Ana"}]}]'],
                'media_type' => [
                    'type' => 'select', 'required' => false, 'label' => 'Tipo de medio', 'default' => 'image',
                    'options' => array_map(static fn(string $type): array => ['value' => $type, 'label' => $type], ['image', 'audio', 'video', 'document', 'sticker'])
                ],
                'media_id' => ['type' => 'string', 'required' => false, 'label' => 'ID del medio ya subido (alternativo a URL)'],
                'media_link' => ['type' => 'string', 'required' => false, 'label' => 'URL HTTPS pública del medio (alternativa a ID)'],
                'caption' => ['type' => 'textarea', 'required' => false, 'label' => 'Leyenda (solo imagen, video o documento)'],
                'filename' => ['type' => 'string', 'required' => false, 'label' => 'Nombre de archivo (solo documento)']
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepareRequest($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine aplica ExpressionEvaluator una vez; no reinterpretar los datos resultantes.
        $request = $this->prepareRequest($parameters);
        try {
            $manager = $this->credentials ?? new CredentialManager();
            $credential = $manager->getCredentialDecrypted($request['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('WhatsApp Business: no se pudo recuperar o descifrar la credencial en el gestor de p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('WhatsApp Business: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', trim($token)) !== 1) {
            throw new InvalidArgumentException('WhatsApp Business: selecciona una credencial Bearer Token / API Key con un access token de Meta válido, sin prefijo Bearer.');
        }
        $token = trim($token);
        try {
            $client = $this->client ?? new Client();
            $response = $client->request('POST', 'https://graph.facebook.com/' . $request['version'] . '/'
                . $request['phone_number_id'] . '/messages', [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                    'json' => $request['payload'],
                    'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
                    'allow_redirects' => false, 'http_errors' => false
                ]);
            $status = $response->getStatusCode();
            $rawBody = (string)$response->getBody();
            $body = json_decode($rawBody, true);
        } catch (Throwable $e) {
            throw new RuntimeException('WhatsApp Business: fallo de transporte o lectura. El envío puede haber ocurrido; comprueba su estado antes de repetirlo.');
        }
        if ($status < 200 || $status >= 300 || (is_array($body) && array_key_exists('error', $body))) {
            $code = is_array($body) && is_array($body['error'] ?? null) ? ($body['error']['code'] ?? null) : null;
            $reason = match (true) {
                $status === 401 || in_array($code, [102, 190], true) => 'autenticación rechazada; revisa la vigencia y el acceso del token',
                $status === 429 || in_array($code, [4, 17, 32, 613, 80007, 130429, 131048, 131056], true) => 'cuota o límite de mensajería alcanzado; espera y revisa los límites antes de repetir',
                $code === 131047 => 'ventana de atención de 24 horas cerrada; utiliza una plantilla aprobada',
                is_int($code) && $code >= 132000 && $code <= 132999 => 'plantilla rechazada; revisa aprobación, estado, nombre, idioma y parámetros',
                $status === 403 || in_array($code, [10, 200, 131005], true) => 'permisos insuficientes; revisa whatsapp_business_messaging y el acceso al número',
                $status === 404 || $code === 100 => 'recurso, versión o configuración rechazados; revisa Phone Number ID, destinatario y campos',
                $status >= 500 => 'error del servicio Meta; el envío puede haber ocurrido, comprueba su estado antes de repetir',
                $status >= 300 && $status < 400 => 'redirección rechazada por seguridad',
                default => 'Meta rechazó la solicitud; revisa la configuración, el destinatario y el medio'
            };
            throw new RuntimeException("WhatsApp Business: {$reason} (HTTP {$status}).", $status);
        }
        // Solo identificadores y estados conocidos; nunca contactos, mensajes ni cuerpos arbitrarios.
        $message = is_array($body) && is_array($body['messages'] ?? null) && count($body['messages']) === 1
            ? ($body['messages'][0] ?? null) : null;
        $id = is_array($message) ? ($message['id'] ?? null) : null;
        if (!is_string($id) || preg_match('/^wamid\.[A-Za-z0-9+\/=_-]{1,512}$/D', $id) !== 1
            || str_contains($id, $token) || ($body['messaging_product'] ?? null) !== 'whatsapp') {
            throw new RuntimeException('WhatsApp Business: respuesta JSON inválida o incompleta. El envío puede haber ocurrido; comprueba su estado antes de repetirlo.', $status);
        }
        $data = ['message_id' => $id];
        if (array_key_exists('message_status', $message)) {
            if (!in_array($message['message_status'], ['accepted', 'held_for_quality_assessment'], true)) {
                throw new RuntimeException('WhatsApp Business: estado de respuesta inválido; comprueba el envío antes de repetirlo.', $status);
            }
            $data['message_status'] = $message['message_status'];
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepareRequest(array $parameters): array
    {
        $credentialId = trim($this->text($parameters['credential_id'] ?? null, 255));
        $version = $this->text($parameters['api_version'] ?? null, 6);
        $phoneId = $this->text($parameters['phone_number_id'] ?? null, 32);
        $to = $this->text($parameters['to'] ?? null, 16);
        if (preg_match('/^v[0-9]{2}\.0$/D', $version) !== 1 || preg_match('/^[1-9][0-9]{0,31}$/D', $phoneId) !== 1
            || preg_match('/^\+?[1-9][0-9]{6,14}$/D', $to) !== 1) {
            throw new InvalidArgumentException('WhatsApp Business: indica versión vNN.0, Phone Number ID numérico y teléfono internacional de 7 a 15 dígitos.');
        }
        $operation = $parameters['operation'] ?? 'send_text';
        if (!in_array($operation, ['send_text', 'send_template', 'send_media'], true)) {
            throw new InvalidArgumentException('WhatsApp Business: operación inválida.');
        }
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($to, '+')];
        if ($operation === 'send_text') {
            $payload += ['type' => 'text', 'text' => ['body' => $this->text($parameters['text'] ?? null, 4096), 'preview_url' => false]];
        } elseif ($operation === 'send_template') {
            $name = $this->text($parameters['template_name'] ?? null, 512);
            $language = $this->text($parameters['language'] ?? null, 12);
            if (preg_match('/^[a-z0-9_]+$/D', $name) !== 1 || preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D', $language) !== 1) {
                throw new InvalidArgumentException('WhatsApp Business: nombre o código de idioma de plantilla inválido.');
            }
            $template = ['name' => $name, 'language' => ['code' => $language]];
            $components = $this->components($parameters['components'] ?? []);
            if ($components !== []) {
                $template['components'] = $components;
            }
            $payload += ['type' => 'template', 'template' => $template];
        } else {
            $type = $parameters['media_type'] ?? 'image';
            if (!in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
                throw new InvalidArgumentException('WhatsApp Business: tipo de medio inválido.');
            }
            $media = $this->media($type, [
                'id' => $parameters['media_id'] ?? '', 'link' => $parameters['media_link'] ?? '',
                'caption' => $parameters['caption'] ?? '', 'filename' => $parameters['filename'] ?? ''
            ]);
            $payload += ['type' => $type, $type => $media];
        }
        return ['credential_id' => $credentialId, 'version' => $version, 'phone_number_id' => $phoneId, 'payload' => $payload];
    }

    private function text(mixed $value, int $limit, bool $allowEmpty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit
            || (!$allowEmpty && trim($value) === '') || str_contains($value, "\0")
            || preg_match('/{{\s*[A-Za-z0-9_.]+\s*}}/', $value) === 1) {
            throw new InvalidArgumentException('WhatsApp Business: campo vacío, tipo o longitud inválidos, o expresión sin resolver.');
        }
        return $value;
    }

    private function media(string $type, array $data): array
    {
        if (array_diff(array_keys($data), ['id', 'link', 'caption', 'filename']) !== []) {
            throw new InvalidArgumentException('WhatsApp Business: propiedades de medio no admitidas.');
        }
        $id = $this->text($data['id'] ?? '', 64, true);
        $link = $this->text($data['link'] ?? '', 2048, true);
        if (($id === '') === ($link === '')) {
            throw new InvalidArgumentException('WhatsApp Business: proporciona exactamente un ID de medio o una URL HTTPS.');
        }
        if ($id !== '' && preg_match('/^[1-9][0-9]{0,63}$/D', $id) !== 1) {
            throw new InvalidArgumentException('WhatsApp Business: ID de medio inválido.');
        }
        if ($link !== '') {
            $parts = parse_url($link);
            if (!filter_var($link, FILTER_VALIDATE_URL) || $parts === false || ($parts['scheme'] ?? '') !== 'https'
                || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $link) === 1) {
                throw new InvalidArgumentException('WhatsApp Business: el medio requiere una URL HTTPS pública sin usuario, contraseña ni fragmento.');
            }
        }
        $result = $id !== '' ? ['id' => $id] : ['link' => $link];
        $caption = $this->text($data['caption'] ?? '', 1024, true);
        $filename = $this->text($data['filename'] ?? '', 255, true);
        if (($caption !== '' && !in_array($type, ['image', 'video', 'document'], true))
            || ($filename !== '' && $type !== 'document')) {
            throw new InvalidArgumentException('WhatsApp Business: caption solo admite imagen, video o documento; filename solo documento.');
        }
        if ($caption !== '') {
            $result['caption'] = $caption;
        }
        if ($filename !== '') {
            if (preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename) === 1) {
                throw new InvalidArgumentException('WhatsApp Business: filename debe ser un nombre de archivo sin rutas.');
            }
            $result['filename'] = $filename;
        }
        return $result;
    }

    private function components(mixed $value): array
    {
        $value = $this->componentData($value);
        if (is_string($value)) {
            try {
                if (!str_starts_with(ltrim($value), '[')) {
                    throw new \JsonException();
                }
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('WhatsApp Business: components debe ser un array JSON válido.');
            }
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
            throw new InvalidArgumentException('WhatsApp Business: components debe ser una lista de hasta 10 componentes.');
        }
        $result = [];
        $seen = [];
        foreach ($value as $component) {
            if (!is_array($component) || array_diff(array_keys($component), ['type', 'parameters', 'sub_type', 'index']) !== []
                || !in_array($component['type'] ?? null, ['header', 'body', 'button'], true)
                || !is_array($component['parameters'] ?? null) || !array_is_list($component['parameters'])
                || $component['parameters'] === [] || count($component['parameters']) > 100) {
                throw new InvalidArgumentException('WhatsApp Business: estructura de componente no admitida.');
            }
            $type = $component['type'];
            $normalized = ['type' => $type];
            $key = $type;
            if ($type === 'button') {
                $index = $component['index'] ?? null;
                if (!in_array($component['sub_type'] ?? null, ['quick_reply', 'url'], true)
                    || (!is_int($index) && !is_string($index)) || preg_match('/^[0-9]$/D', (string)$index) !== 1
                    || count($component['parameters']) !== 1) {
                    throw new InvalidArgumentException('WhatsApp Business: botón requiere quick_reply/url, índice 0–9 y un parámetro.');
                }
                $normalized += ['sub_type' => $component['sub_type'], 'index' => (string)$index];
                $key .= ':' . $index;
            } elseif (isset($component['sub_type']) || isset($component['index']) || ($type === 'header' && count($component['parameters']) !== 1)) {
                throw new InvalidArgumentException('WhatsApp Business: header requiere un parámetro; solo button admite sub_type e index.');
            }
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('WhatsApp Business: componente o índice de botón duplicado.');
            }
            $seen[$key] = true;
            $normalized['parameters'] = [];
            foreach ($component['parameters'] as $parameter) {
                if (!is_array($parameter) || !is_string($parameter['type'] ?? null)) {
                    throw new InvalidArgumentException('WhatsApp Business: parámetro de plantilla inválido.');
                }
                $parameterType = $parameter['type'];
                $allowed = $type === 'header' ? ['text', 'image', 'video', 'document'] : ['text'];
                if ($type === 'button' && $normalized['sub_type'] === 'quick_reply') {
                    $allowed = ['payload'];
                }
                if (!in_array($parameterType, $allowed, true) || array_diff(array_keys($parameter), ['type', $parameterType]) !== []) {
                    throw new InvalidArgumentException('WhatsApp Business: tipo de parámetro no admitido en este componente.');
                }
                $item = in_array($parameterType, ['text', 'payload'], true)
                    ? $this->text($parameter[$parameterType] ?? null, $parameterType === 'payload' ? 128 : 1024)
                    : $this->media($parameterType, is_array($parameter[$parameterType] ?? null) ? $parameter[$parameterType] : []);
                $normalized['parameters'][] = ['type' => $parameterType, $parameterType => $item];
            }
            $result[] = $normalized;
        }
        return $result;
    }

    private function componentData(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32 || (is_object($value) && !$value instanceof \stdClass) || is_resource($value)) {
            throw new InvalidArgumentException('WhatsApp Business: los componentes requieren datos JSON con profundidad máxima de 32.');
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->componentData($item, $depth + 1);
            }
        }
        return $value;
    }
}
