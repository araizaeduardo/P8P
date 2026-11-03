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

class TransactionalEmailNode implements NodeInterface
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
            'type' => 'transactional_email', 'name' => 'Transactional Email', 'category' => 'Community', 'icon' => 'Mail', 'is_start' => false,
            'description' => 'Envía correo mediante Resend o SendGrid con contenido propio o plantilla del proveedor.',
            'help_text' => 'Credencial Bearer Token / API Key: clave Resend re_... con permiso Sending access y dominio permitido, o SendGrid SG... con Mail Send y remitente verificado. Sin OAuth ni renovación. from es email simple; to admite email simple o lista JSON/expresión de emails simples, máximo 50 (visibles entre sí). Contenido propio requiere subject y html o text; text vacío explícito se conserva en Resend. Plantillas: Resend usa ID/alias publicado y variables escalares texto/número; SendGrid admite únicamente plantilla dinámica d- seguida de 32 hex y variables JSON. Una plantilla no admite html/text; en SendGrid el asunto lo define la plantilla. Adjuntos solo Base64 canónico, lista {filename,content_base64,content_type?}; máximo 10, 5 MiB decodificados entre todos y 1 MiB de contenido del correo. No lee archivos ni descarga URLs. Resend admite request_id estable opcional (hasta 256 caracteres, retención 24 h); SendGrid rechaza ese campo porque Mail Send no ofrece ese contrato. Sin reintentos; ante transporte incierto comprueba el proveedor antes de reenviar. Salida data.provider/id/accepted; aceptado no significa entregado. Sin paginación. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type'=>'credential','label'=>'Credencial del proveedor','required'=>true],
                'provider' => ['type'=>'select','label'=>'Proveedor','default'=>'resend','required'=>true,'options'=>[['value'=>'resend','label'=>'Resend'],['value'=>'sendgrid','label'=>'SendGrid']]],
                'content_mode' => ['type'=>'select','label'=>'Contenido','default'=>'custom','required'=>true,'options'=>[['value'=>'custom','label'=>'HTML / texto propio'],['value'=>'template','label'=>'Plantilla administrada']]],
                'from' => ['type'=>'string','label'=>'Remitente verificado (email)','required'=>true],
                'to' => ['type'=>'textarea','label'=>'Destinatario o lista JSON de emails (1–50)','required'=>true],
                'subject' => ['type'=>'string','label'=>'Asunto (obligatorio contenido propio)','required'=>false],
                'html' => ['type'=>'textarea','label'=>'HTML propio','required'=>false],
                'text' => ['type'=>'textarea','label'=>'Texto alternativo propio','required'=>false],
                'template_id' => ['type'=>'string','label'=>'ID de plantilla (Resend publicado / SendGrid d-...)','required'=>false],
                'template_variables' => ['type'=>'textarea','label'=>'Variables de plantilla (objeto JSON o expresión)','required'=>false],
                'attachments' => ['type'=>'textarea','label'=>'Adjuntos Base64 (lista JSON o expresión)','required'=>false],
                'request_id' => ['type'=>'string','label'=>'Idempotencia estable opcional (solo Resend)','required'=>false]
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
        // El motor ya resuelve ExpressionEvaluator: no reinterpretar contenido del correo.
        $config = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($config['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('Transactional Email: no se pudo recuperar o descifrar la credencial en el gestor de p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Transactional Email: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        $pattern = $config['provider'] === 'resend' ? '/^re_[A-Za-z0-9_-]{8,512}$/D' : '/^SG\.[A-Za-z0-9_-]{8,128}\.[A-Za-z0-9_-]{8,256}$/D';
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token) || preg_match($pattern, $token) !== 1) {
            throw new InvalidArgumentException('Transactional Email: credencial incompatible con ' . $config['provider'] . '; selecciona su API Key en una credencial Bearer Token / API Key.');
        }
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        if ($config['request_id'] !== '') {
            $headers['Idempotency-Key'] = $config['request_id'];
        }
        try {
            $response = ($this->client ?? new Client())->request('POST', $config['url'], [
                'headers' => $headers, 'json' => $config['payload'], 'timeout' => 30, 'connect_timeout' => 10,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getBody()->read(65537);
        } catch (Throwable $e) {
            throw new RuntimeException('Transactional Email (' . $config['provider'] . '): fallo de transporte o lectura; el envío puede haberse aceptado. Comprueba el proveedor antes de repetir; no hubo reintento automático.');
        }
        if ($status < 200 || $status >= 300) {
            $reason = match (true) {
                $status === 401 => 'autenticación rechazada; API Key inválida o revocada',
                $status === 403 && $config['provider'] === 'resend' => 'permiso de envío, dominio o destinatario no autorizado por Resend',
                $status === 403 => 'permiso Mail Send, remitente verificado o cuenta SendGrid no autorizados',
                $status === 404 => 'recurso o plantilla inexistente o inaccesible',
                $status === 429 => 'cuota o límite de solicitudes alcanzado',
                $status >= 500 => 'fallo del proveedor; resultado de envío potencialmente incierto',
                $status >= 300 && $status < 400 => 'redirección rechazada',
                $status === 409 => 'conflicto de idempotencia; conserva la clave y el contenido de la operación original',
                default => 'solicitud rechazada; revisa destinatarios, contenido, plantilla y límites'
            };
            throw new RuntimeException('Transactional Email (' . $config['provider'] . "): {$reason} (HTTP {$status}). Sin reintento automático.", $status);
        }
        try {
            if ($config['provider'] === 'resend') {
                $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                if ($status !== 200 || strlen($raw) > 65536 || !is_array($body) || isset($body['error']) || isset($body['name'])
                    || !is_string($body['id'] ?? null) || preg_match('/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/D', $body['id']) !== 1) {
                    throw new InvalidArgumentException();
                }
                $id = $body['id'];
            } else {
                if ($status !== 202 || trim($raw) !== '') {
                    throw new InvalidArgumentException();
                }
                $id = $response->getHeaderLine('X-Message-Id');
                if ($id !== '' && preg_match('/^[A-Za-z0-9._-]{1,256}$/D', $id) !== 1) {
                    throw new InvalidArgumentException();
                }
                $id = $id === '' ? null : $id;
            }
            if (is_string($id) && str_contains($id, $token)) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Transactional Email (' . $config['provider'] . '): respuesta inválida; el envío puede haberse aceptado. Comprueba el proveedor antes de repetir.', $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => ['provider' => $config['provider'], 'id' => $id, 'accepted' => true]];
    }

    private function prepare(array $p): array
    {
        $credentialId = $this->text($p['credential_id'] ?? null, 256, false);
        $provider = $p['provider'] ?? 'resend';
        $mode = $p['content_mode'] ?? 'custom';
        if (!in_array($provider, ['resend', 'sendgrid'], true) || !in_array($mode, ['custom', 'template'], true)) {
            throw new InvalidArgumentException('Transactional Email: proveedor o modo de contenido inválido.');
        }
        $from = $this->email($p['from'] ?? null);
        $to = $p['to'] ?? null;
        if (is_string($to) && str_starts_with(trim($to), '[')) {
            $to = $this->json($to);
        } elseif (is_string($to)) {
            $to = [$to];
        }
        if (!is_array($to) || !array_is_list($to) || count($to) < 1 || count($to) > 50) {
            throw new InvalidArgumentException('Transactional Email: to debe contener de 1 a 50 emails simples.');
        }
        $to = array_map(fn($email) => $this->email($email), $to);
        if (count(array_unique(array_map('strtolower', $to))) !== count($to)) {
            throw new InvalidArgumentException('Transactional Email: no repitas destinatarios.');
        }
        $payload = $provider === 'resend' ? ['from'=>$from,'to'=>$to] : ['from'=>['email'=>$from], 'personalizations'=>[['to'=>array_map(static fn($email)=>['email'=>$email], $to)]]];
        $bodySize = 0;
        if ($mode === 'custom') {
            if (($p['template_id'] ?? '') !== '' || ($p['template_variables'] ?? '') !== '') {
                throw new InvalidArgumentException('Transactional Email: contenido propio no admite parámetros de plantilla.');
            }
            $payload['subject'] = $this->text($p['subject'] ?? null, 998, false);
            $hasContent = false; $content = [];
            foreach (['text', 'html'] as $field) {
                if (array_key_exists($field, $p)) {
                    $value = $this->text($p[$field], 1048576, true, true);
                    $bodySize += strlen($value);
                    $hasContent = $hasContent || trim($value) !== '';
                    if ($provider === 'resend') {
                        $payload[$field] = $value;
                    } elseif ($value !== '') {
                        $content[] = ['type'=>$field === 'text' ? 'text/plain' : 'text/html','value'=>$value];
                    }
                }
            }
            if (!$hasContent) {
                throw new InvalidArgumentException('Transactional Email: indica HTML o texto no vacío.');
            }
            if ($provider === 'sendgrid') {
                $payload['content'] = $content;
            }
        } else {
            if (($p['html'] ?? '') !== '' || ($p['text'] ?? '') !== '') {
                throw new InvalidArgumentException('Transactional Email: una plantilla no admite HTML ni texto propio.');
            }
            $templateId = $this->text($p['template_id'] ?? null, 256, false);
            $variables = $this->object($p['template_variables'] ?? new stdClass());
            if ($provider === 'resend') {
                if (preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $templateId) !== 1) {
                    throw new InvalidArgumentException('Transactional Email: ID o alias de plantilla Resend inválido.');
                }
                foreach ($variables as $key => $value) {
                    if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,50}$/D', $key) !== 1
                        || in_array($key, ['FIRST_NAME','LAST_NAME','EMAIL','UNSUBSCRIBE_URL'], true)
                        || (!is_string($value) && !is_int($value) && !is_float($value))
                        || (is_string($value) && (mb_strlen($value) > 2000 || !mb_check_encoding($value)))
                        || (is_numeric($value) && !is_string($value) && (!is_finite((float)$value) || abs($value) > 9007199254740991))) {
                        throw new InvalidArgumentException('Transactional Email: variables Resend requieren claves permitidas y valores texto (hasta 2000 caracteres) o números seguros.');
                    }
                }
                $payload['template'] = ['id'=>$templateId,'variables'=>(object)$variables];
                if (array_key_exists('subject', $p) && $p['subject'] !== '') {
                    $payload['subject'] = $this->text($p['subject'], 998, false);
                }
            } else {
                if (preg_match('/^d-[a-fA-F0-9]{32}$/D', $templateId) !== 1 || ($p['subject'] ?? '') !== '') {
                    throw new InvalidArgumentException('Transactional Email: SendGrid exige plantilla dinámica d- seguida de 32 hex; el asunto se define en la plantilla.');
                }
                $payload['template_id'] = $templateId;
                $payload['personalizations'][0]['dynamic_template_data'] = (object)$variables;
            }
            $bodySize = strlen($this->encode($variables));
        }
        if ($bodySize > 1048576) {
            throw new InvalidArgumentException('Transactional Email: contenido o variables exceden 1 MiB.');
        }
        if (array_key_exists('attachments', $p) && $p['attachments'] !== '') {
            $attachments = is_string($p['attachments']) ? $this->json($p['attachments']) : $p['attachments'];
            if (!is_array($attachments) || !array_is_list($attachments) || count($attachments) > 10) {
                throw new InvalidArgumentException('Transactional Email: adjuntos debe ser lista de hasta 10 elementos.');
            }
            $total = 0; $prepared = [];
            foreach ($attachments as $attachment) {
                $attachment = $this->object($attachment);
                if (array_diff(array_keys($attachment), ['filename','content_base64','content_type']) !== []) {
                    throw new InvalidArgumentException('Transactional Email: adjunto admite solo filename, content_base64 y content_type; no rutas ni URLs.');
                }
                $filename = $this->text($attachment['filename'] ?? null, 255, false);
                $encoded = $attachment['content_base64'] ?? null;
                if (preg_match('/[\\\\\/]/', $filename) === 1 || !is_string($encoded) || strlen($encoded) > 6990508
                    || ($decoded = base64_decode($encoded, true)) === false || $decoded === '' || base64_encode($decoded) !== $encoded) {
                    throw new InvalidArgumentException('Transactional Email: nombre o Base64 canónico del adjunto inválido.');
                }
                $total += strlen($decoded);
                if ($total > 5242880) {
                    throw new InvalidArgumentException('Transactional Email: adjuntos exceden 5 MiB decodificados en total.');
                }
                $item = ['filename'=>$filename,'content'=>$encoded];
                if (array_key_exists('content_type', $attachment)) {
                    $type = $this->text($attachment['content_type'], 127, false);
                    if (preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/D', $type) !== 1) {
                        throw new InvalidArgumentException('Transactional Email: MIME del adjunto inválido.');
                    }
                    if ($provider === 'sendgrid') {
                        $item['type'] = $type;
                    } else {
                        throw new InvalidArgumentException('Transactional Email: Resend infiere el MIME del nombre; omite content_type.');
                    }
                }
                $prepared[] = $item;
            }
            if ($prepared !== []) {
                $payload['attachments'] = $prepared;
            }
        }
        $requestId = $p['request_id'] ?? '';
        if (!is_string($requestId) || ($requestId !== '' && ($provider !== 'resend' || preg_match('/^[A-Za-z0-9._:-]{1,256}$/D', $requestId) !== 1))) {
            throw new InvalidArgumentException('Transactional Email: request_id estable solo está disponible en Resend (1–256 caracteres ASCII simples).');
        }
        return ['credential_id'=>$credentialId,'provider'=>$provider,'payload'=>$payload,'request_id'=>$requestId,
            'url'=>$provider === 'resend' ? 'https://api.resend.com/emails' : 'https://api.sendgrid.com/v3/mail/send'];
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR, 32);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Transactional Email: variables no serializables o excesivamente anidadas.');
        }
    }

    private function json(string $value): mixed
    {
        try {
            return json_decode($value, false, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Transactional Email: JSON inválido.');
        }
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
            throw new InvalidArgumentException('Transactional Email: se requiere un objeto JSON.');
        }
        return $value;
    }

    private function email(mixed $value): string
    {
        $value = $this->text($value, 254, false);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Transactional Email: email simple inválido; no uses nombres ni cabeceras.');
        }
        return $value;
    }

    private function text(mixed $value, int $max, bool $empty, bool $multiline = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > $max || (!$empty && trim($value) === '')
            || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('Transactional Email: campo vacío, de tipo incorrecto o fuera de límites.');
        }
        return $value;
    }
}
