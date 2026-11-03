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

class TwilioNode implements NodeInterface
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
            'type' => 'twilio', 'name' => 'Twilio', 'category' => 'Community',
            'description' => 'Envía SMS, WhatsApp o inicia una llamada con instrucciones de voz.',
            'icon' => 'Phone', 'is_start' => false,
            'help_text' => 'Credencial generic_bearer: JSON en API Key con account_sid y auth_token, o account_sid, api_key_sid y api_key_secret. El remitente debe pertenecer a la cuenta o estar autorizado. SMS acepta E.164, short code o remitente alfanumérico; voz solo E.164. WhatsApp exige whatsapp:+número y remitente aprobado: texto libre solo durante las 24 horas posteriores al mensaje del cliente; fuera usa ContentSid de plantilla aprobada. Voz genera TwiML Say seguro a partir de voice_text (no XML ni URL). No programa llamadas ni mensajes. La aceptación por Twilio no garantiza entrega. Sin reintentos automáticos. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Twilio (JSON en API Key)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'send_sms', 'options' => [
                    ['value' => 'send_sms', 'label' => 'Enviar SMS'],
                    ['value' => 'send_whatsapp', 'label' => 'Enviar WhatsApp'],
                    ['value' => 'create_call', 'label' => 'Crear llamada saliente']
                ]],
                'to' => ['type' => 'string', 'required' => true, 'label' => 'Destino E.164 (WhatsApp: whatsapp:+…)'],
                'from' => ['type' => 'string', 'required' => true, 'label' => 'Remitente autorizado (WhatsApp: whatsapp:+…)'],
                'body' => ['type' => 'textarea', 'required' => false, 'label' => 'Texto SMS / WhatsApp libre (máximo 1600 caracteres)'],
                'whatsapp_mode' => ['type' => 'select', 'required' => false, 'label' => 'Modo WhatsApp', 'default' => 'template', 'options' => [
                    ['value' => 'template', 'label' => 'Plantilla aprobada'],
                    ['value' => 'free', 'label' => 'Texto libre (ventana de atención de 24 horas)']
                ]],
                'content_sid' => ['type' => 'string', 'required' => false, 'label' => 'Content SID HX… (plantilla WhatsApp)'],
                'content_variables' => ['type' => 'textarea', 'required' => false, 'label' => 'Variables de plantilla: objeto JSON de textos', 'placeholder' => '{"1":"Ana"}'],
                'voice_text' => ['type' => 'textarea', 'required' => false, 'label' => 'Texto para Say (llamada; TwiML final máximo 4000 caracteres)']
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
        // Engine ya resuelve ExpressionEvaluator: no interpretar de nuevo el contenido resultante.
        [$credentialId, $resource, $form] = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($credentialId);
        } catch (Throwable $e) {
            throw new RuntimeException('Twilio: no se pudo recuperar o descifrar la credencial en p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Twilio: la credencial no existe.');
        }
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException('Twilio: se requiere una credencial generic_bearer con JSON en API Key.');
        }
        try {
            $config = json_decode($credential['data']['api_key'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Twilio: JSON de credencial inválido.');
        }
        if (!is_array($config) || !$this->sid($config['account_sid'] ?? null, 'AC')) {
            throw new InvalidArgumentException('Twilio: account_sid inválido en la credencial.');
        }
        $hasToken = array_key_exists('auth_token', $config);
        $hasKey = array_key_exists('api_key_sid', $config) || array_key_exists('api_key_secret', $config);
        if ($hasToken === $hasKey) {
            throw new InvalidArgumentException('Twilio: configura exclusivamente auth_token o el par api_key_sid/api_key_secret.');
        }
        if ($hasToken) {
            if (!is_string($config['auth_token']) || !preg_match('/^[a-fA-F0-9]{32}$/D', $config['auth_token'])) {
                throw new InvalidArgumentException('Twilio: auth_token inválido en la credencial.');
            }
            $auth = [$config['account_sid'], $config['auth_token']];
        } else {
            if (!$this->sid($config['api_key_sid'] ?? null, 'SK') || !is_string($config['api_key_secret'] ?? null)
                || !preg_match('/^[A-Za-z0-9_-]{16,256}$/D', $config['api_key_secret'])) {
                throw new InvalidArgumentException('Twilio: par de API key inválido en la credencial.');
            }
            $auth = [$config['api_key_sid'], $config['api_key_secret']];
        }
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $config['account_sid'] . '/' . $resource . '.json';
        try {
            $response = ($this->client ?? new Client())->request('POST', $url, [
                'auth' => $auth, 'form_params' => $form, 'headers' => ['Accept' => 'application/json'],
                'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
                'allow_redirects' => false, 'http_errors' => false, 'stream' => true
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Twilio: fallo de transporte; resultado incierto. Comprueba Twilio antes de repetir para evitar duplicados.');
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $category = match ($status) {
                401 => 'autenticación rechazada', 403 => 'permisos o remitente no autorizado',
                404 => 'recurso inexistente', 429 => 'cuota o límite de solicitudes',
                default => 'solicitud rechazada por el proveedor'
            };
            throw new RuntimeException('Twilio: ' . $category . ' (HTTP ' . $status . ').');
        }
        // Estas operaciones crean recursos y requieren una representación JSON, incluso si HTTP es 204.
        try {
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
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('Twilio: respuesta inválida; comprueba el recurso antes de repetir la operación.');
        }
        if (!is_array($data) || !$this->sid($data['sid'] ?? null, $resource === 'Calls' ? 'CA' : 'SM')
            || !is_string($data['status'] ?? null) || $data['status'] === ''
            || ($data['error_code'] ?? null) !== null || ($data['code'] ?? null) !== null
            || in_array($data['status'], ['failed', 'undelivered', 'canceled'], true)) {
            throw new RuntimeException('Twilio: respuesta sin recurso válido o con error funcional; comprueba Twilio antes de repetir.');
        }
        // Evitar eco de cuerpos, URLs, tokens o mensajes de error remotos en el historial.
        $result = array_intersect_key($data, array_flip(['sid', 'status', 'to', 'from', 'direction', 'date_created', 'date_updated', 'num_segments', 'price', 'price_unit']));
        $secrets = [$auth[1], $credential['data']['api_key'], base64_encode($auth[0] . ':' . $auth[1])];
        $redactions = array_unique(array_merge($secrets, array_map('rawurlencode', $secrets), array_map('urlencode', $secrets)));
        foreach ($result as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                unset($result[$key]);
            } elseif (is_string($value)) {
                $result[$key] = str_replace($redactions, '[REDACTED]', $value);
            }
        }
        return ['success' => true, 'status_code' => $status, 'data' => $result];
    }

    private function prepare(array $p): array
    {
        $id = $this->text($p['credential_id'] ?? null, 'credential_id', 255);
        $operation = $p['operation'] ?? 'send_sms';
        if (!in_array($operation, ['send_sms', 'send_whatsapp', 'create_call'], true)) {
            throw new InvalidArgumentException('Twilio: operación inválida.');
        }
        // No aceptar parámetros que sugieran una programación o URL de instrucciones soportada.
        foreach (['send_at', 'schedule_type', 'scheduled_at', 'call_at', 'url', 'twiml', 'status_callback'] as $unsupported) {
            if (array_key_exists($unsupported, $p)) {
                throw new InvalidArgumentException('Twilio: programación, callbacks y TwiML/URL personalizados no están soportados por este nodo.');
            }
        }
        $to = $this->text($p['to'] ?? null, 'to', 32);
        $from = $this->text($p['from'] ?? null, 'from', 32);
        if ($operation === 'send_whatsapp') {
            if (!preg_match('/^whatsapp:\+[1-9][0-9]{1,14}$/D', $to) || !preg_match('/^whatsapp:\+[1-9][0-9]{1,14}$/D', $from)) {
                throw new InvalidArgumentException('Twilio: WhatsApp requiere direcciones whatsapp:+número E.164.');
            }
        } else {
            $senderValid = preg_match('/^\+[1-9][0-9]{1,14}$/D', $from) === 1;
            if ($operation === 'send_sms') {
                $senderValid = $senderValid || preg_match('/^[0-9]{3,7}$/D', $from) === 1
                    || (preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11}$/D', $from) === 1 && trim($from) === $from);
            }
            if (!preg_match('/^\+[1-9][0-9]{1,14}$/D', $to) || !$senderValid) {
                throw new InvalidArgumentException('Twilio: destino E.164 o remitente inválido para esta operación.');
            }
        }
        $form = ['To' => $to, 'From' => $from];
        if ($operation === 'create_call') {
            $text = $this->text($p['voice_text'] ?? null, 'voice_text', 4000);
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', $text)) {
                throw new InvalidArgumentException('Twilio: voice_text contiene caracteres no válidos en XML.');
            }
            $xml = '<Response><Say>' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Say></Response>';
            if (mb_strlen($xml, 'UTF-8') > 4000) {
                throw new InvalidArgumentException('Twilio: TwiML generado excede 4000 caracteres; reduce voice_text.');
            }
            $form['Twiml'] = $xml;
            return [$id, 'Calls', $form];
        }
        if ($operation === 'send_sms' || ($p['whatsapp_mode'] ?? 'template') === 'free') {
            if ($operation === 'send_whatsapp' && (($p['content_sid'] ?? '') !== '' || ($p['content_variables'] ?? '') !== '')) {
                throw new InvalidArgumentException('Twilio: texto libre no admite parámetros de plantilla.');
            }
            $form['Body'] = $this->text($p['body'] ?? null, 'body', 1600);
        } elseif (($p['whatsapp_mode'] ?? 'template') === 'template') {
            if (!$this->sid($p['content_sid'] ?? null, 'HX') || ($p['body'] ?? '') !== '') {
                throw new InvalidArgumentException('Twilio: plantilla requiere Content SID HX válido y body omitido o vacío.');
            }
            $form['ContentSid'] = $p['content_sid'];
            if (array_key_exists('content_variables', $p) && $p['content_variables'] !== '') {
                $variables = $p['content_variables'];
                if (is_string($variables)) {
                    try {
                        $variables = json_decode($variables, false, 16, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        throw new InvalidArgumentException('Twilio: content_variables contiene JSON inválido.');
                    }
                }
                if ($variables instanceof stdClass) {
                    $variables = get_object_vars($variables);
                } elseif (!is_array($variables) || ($variables !== [] && array_is_list($variables))) {
                    throw new InvalidArgumentException('Twilio: content_variables debe ser un objeto de textos.');
                }
                if (count($variables) > 100) {
                    throw new InvalidArgumentException('Twilio: demasiadas variables de plantilla (máximo 100).');
                }
                foreach ($variables as $key => $value) {
                    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string) $key) || !is_string($value)
                        || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 1600) {
                        throw new InvalidArgumentException('Twilio: nombres o valores de plantilla inválidos; se requieren textos UTF-8.');
                    }
                }
                $encoded = json_encode((object) $variables, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (strlen($encoded) > 32000) {
                    throw new InvalidArgumentException('Twilio: variables de plantilla exceden el límite local de 32000 bytes.');
                }
                $form['ContentVariables'] = $encoded;
            }
        } else {
            throw new InvalidArgumentException('Twilio: modo WhatsApp inválido.');
        }
        return [$id, 'Messages', $form];
    }

    private function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max) {
            throw new InvalidArgumentException('Twilio: campo ' . $field . ' requerido, debe ser texto UTF-8 dentro del límite.');
        }
        return $value;
    }

    private function sid(mixed $value, string $prefix): bool
    {
        return is_string($value) && preg_match('/^' . $prefix . '[a-fA-F0-9]{32}$/D', $value) === 1;
    }
}
