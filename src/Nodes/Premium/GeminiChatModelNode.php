<?php

namespace P8P\Nodes\Premium;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class GeminiChatModelNode implements NodeInterface
{
    private const DEFAULT_MODEL = 'gemini-3.8-flash';
    private const TTS_MODELS = ['gemini-3.8-flash-tts', 'gemini-3.8-flash-lite-tts'];

    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        $models = [
            'gemini-3.8-flash' => 'Gemini 3.8 Flash — Texto (recomendado)',
            'gemini-4-argon' => 'Gemini 4 Argon — Texto / razonamiento (API de pago)',
            'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash Lite — Texto / rápido',
            'gemini-3.7-flash' => 'Gemini 3.7 Flash — Texto',
            'gemini-3.6-flash' => 'Gemini 3.6 Flash — Texto',
            'gemini-3.5-flash' => 'Gemini 3.5 Flash — Texto',
            'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro Preview — Texto',
            'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash Lite — Texto',
            'gemini-3-flash-preview' => 'Gemini 3 Flash Preview — Texto',
            'gemini-3.8-flash-tts' => 'Gemini 3.8 Flash TTS — Audio',
            'gemini-3.8-flash-lite-tts' => 'Gemini 3.8 Flash Lite TTS — Audio',
            'custom' => 'Otro identificador de modelo de texto'
        ];
        return [
            'type' => 'gemini_model', 'name' => 'Google Gemini Chat Model', 'category' => 'Premium',
            'description' => 'Proveedor de texto o voz de Google Gemini para el puerto Model del AI Agent.',
            'icon' => 'Sparkles', 'is_start' => false,
            'help_text' => 'Selecciona este nodo Gemini en el lienzo para elegir Modelo. Conéctalo al puerto Model del AI Agent. Credencial Google Gemini API o generic_bearer con API Key; nunca guardes claves en parámetros. La disponibilidad depende de tu proyecto, región y cuota; Argon requiere API de pago. Texto usa generateContent; TTS 3.8 usa Interactions y devuelve audio_base64/mime_type (máximo 10 MiB), no una respuesta de chat ni reproducción automática. En TTS, Mensaje del usuario contiene el texto literal y Prompt del sistema indica el estilo de voz. Voz predeterminada Kore. Timeout configurable 15–300 segundos, predeterminado 90; el servidor/proxy también debe permitir esa duración. No hay reintentos automáticos ni herramientas. Modelos Live, imagen y vídeo requieren otras integraciones. Referencias: https://ai.google.dev/gemini-api/docs/models y https://ai.google.dev/gemini-api/docs/speech-generation',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial de Gemini'],
                'model' => ['type' => 'select', 'required' => true, 'label' => 'Modelo', 'default' => self::DEFAULT_MODEL,
                    'options' => array_map(static fn ($id, $name) => ['value' => $id, 'label' => $name], array_keys($models), array_values($models))],
                'custom_model' => ['type' => 'string', 'required' => false, 'label' => 'Otro modelo de texto (si elegiste Otro)'],
                'temperature' => ['type' => 'number', 'required' => false, 'label' => 'Temperatura (0.0 a 2.0; solo texto)', 'default' => 1],
                'max_output_tokens' => ['type' => 'number', 'required' => false, 'label' => 'Máximo de tokens de salida (texto, 1–65536)', 'default' => 4096],
                'timeout_seconds' => ['type' => 'number', 'required' => false, 'label' => 'Tiempo máximo de espera (15–300 segundos)', 'default' => 90],
                'voice_name' => ['type' => 'string', 'required' => false, 'label' => 'Voz de Google (solo TTS)', 'default' => 'Kore']
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->configuration($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine resolves ExpressionEvaluator before execute. Only references enter history.
        return ['type' => 'chat_model', 'provider' => 'gemini'] + $this->configuration($parameters);
    }

    public function generate(array $configuration, array $messages): array
    {
        $config = $this->configuration($configuration);
        [$system, $contents] = $this->messages($messages);
        $key = $this->apiKey($config['credential_id']);
        $audio = in_array($config['model'], self::TTS_MODELS, true);
        if ($audio) {
            if (count($contents) !== 1 || $contents[0]['role'] !== 'user') {
                throw new InvalidArgumentException('Gemini TTS: usa un único mensaje de usuario con el texto que se leerá.');
            }
            $text = ['type' => 'text', 'text' => $contents[0]['parts'][0]['text']];
            if ($system !== '') {
                $text['annotations'] = [['type' => 'speech_metadata', 'style' => $system]];
            }
            $payload = ['model' => $config['model'], 'store' => false, 'stream' => false,
                'input' => [['type' => 'user_input', 'content' => [$text]]],
                'response_format' => ['type' => 'audio', 'mime_type' => 'audio/wav', 'delivery' => 'inline'],
                'generation_config' => ['speech_config' => [['voice' => $config['voice_name']]]]];
            $path = '/interactions';
        } else {
            $payload = ['contents' => $contents, 'generationConfig' => [
                'temperature' => $config['temperature'], 'maxOutputTokens' => $config['max_output_tokens']]];
            if ($system !== '') {
                $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
            }
            $path = '/models/' . rawurlencode($config['model']) . ':generateContent';
        }
        $body = $this->request($path, $payload, $key, $config['timeout_seconds']);
        $result = ['success' => true, 'provider' => 'gemini', 'model_used' => $config['model'],
            'timestamp' => date('Y-m-d H:i:s')];
        if ($audio) {
            return $result + $this->audio($body);
        }
        $candidate = $body['candidates'][0] ?? null;
        if (isset($body['error']) || isset($body['promptFeedback']['blockReason']) || !is_array($candidate)
            || ($candidate['finishReason'] ?? null) !== 'STOP' || !is_array($candidate['content']['parts'] ?? null)) {
            throw new RuntimeException('Gemini: respuesta bloqueada, incompleta o sin contenido utilizable.');
        }
        $text = '';
        foreach ($candidate['content']['parts'] as $part) {
            if (!is_array($part)) {
                throw new RuntimeException('Gemini: estructura de respuesta inválida.');
            }
            if (($part['thought'] ?? false) === true) {
                continue;
            }
            if (!is_string($part['text'] ?? null) || isset($part['functionCall']) || isset($part['inlineData'])) {
                throw new RuntimeException('Gemini: el modelo devolvió herramientas o contenido no textual no admitido.');
            }
            $text .= $part['text'];
        }
        if ($text === '' || str_contains($text, $key)) {
            throw new RuntimeException('Gemini: respuesta vacía o descartada por contener datos de autenticación.');
        }
        $usage = [];
        foreach (['promptTokenCount', 'candidatesTokenCount', 'totalTokenCount'] as $field) {
            if (is_int($body['usageMetadata'][$field] ?? null) && $body['usageMetadata'][$field] >= 0) {
                $usage[$field] = $body['usageMetadata'][$field];
            }
        }
        return $result + ['output_text' => $text, 'finish_reason' => 'STOP', 'usage' => $usage];
    }

    private function configuration(array $p): array
    {
        $credential = $p['credential_id'] ?? null;
        if (!is_string($credential) || trim($credential) === '' || strlen($credential) > 255) {
            throw new InvalidArgumentException('Gemini: selecciona una credencial cifrada de Google Gemini API.');
        }
        if (array_key_exists('api_key', $p)) {
            throw new InvalidArgumentException('Gemini: mueve la API Key directa a una credencial cifrada y selecciónala.');
        }
        $model = $p['model'] ?? self::DEFAULT_MODEL;
        if ($model === 'custom') {
            $model = $p['custom_model'] ?? null;
        }
        if (is_string($model)) {
            $model = preg_replace('~^models/~', '', trim($model));
        }
        if (!is_string($model) || !preg_match('/^gemini-[a-zA-Z0-9._-]{1,100}$/D', $model)
            || preg_match('/(?:live|image|transcribe|omni)/i', $model)
            || (str_contains($model, 'tts') && !in_array($model, self::TTS_MODELS, true))) {
            throw new InvalidArgumentException('Gemini: selecciona un modelo de texto o uno de los TTS 3.8 compatibles.');
        }
        $temperature = $p['temperature'] ?? 1;
        if ((!is_int($temperature) && !is_float($temperature) && !is_string($temperature)) || !is_numeric($temperature)
            || !is_finite((float)$temperature) || (float)$temperature < 0 || (float)$temperature > 2) {
            throw new InvalidArgumentException('Gemini: temperatura entre 0 y 2 requerida.');
        }
        $voice = $p['voice_name'] ?? 'Kore';
        if (!is_string($voice) || !preg_match('/^[A-Za-z]{1,64}$/D', $voice)) {
            throw new InvalidArgumentException('Gemini: nombre de voz inválido.');
        }
        return ['credential_id' => $credential, 'model' => $model, 'temperature' => (float)$temperature,
            'max_output_tokens' => $this->integer($p['max_output_tokens'] ?? 4096, 1, 65536),
            'timeout_seconds' => $this->integer($p['timeout_seconds'] ?? 90, 15, 300), 'voice_name' => $voice];
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
            throw new InvalidArgumentException('Gemini: límite de tokens o tiempo de espera inválido.');
        }
        return (int)$value;
    }

    private function messages(array $messages): array
    {
        if (!array_is_list($messages) || count($messages) < 1 || count($messages) > 100) {
            throw new InvalidArgumentException('Gemini: historial de mensajes inválido (1–100).');
        }
        $system = [];
        $contents = [];
        $size = 0;
        foreach ($messages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || !is_string($message['content'] ?? null)
                || ($message['role'] !== 'system' && trim($message['content']) === '')
                || !mb_check_encoding($message['content'], 'UTF-8')) {
                throw new InvalidArgumentException('Gemini: cada mensaje requiere rol válido y contenido textual no vacío.');
            }
            $size += strlen($message['content']);
            if ($size > 131072) {
                throw new InvalidArgumentException('Gemini: los mensajes superan 128 KiB.');
            }
            if ($message['role'] === 'system') {
                $system[] = $message['content'];
            } else {
                $contents[] = ['role' => $message['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $message['content']]]];
            }
        }
        if ($contents === []) {
            throw new InvalidArgumentException('Gemini: falta un mensaje de usuario.');
        }
        return [implode("\n\n", $system), $contents];
    }

    private function apiKey(string $id): string
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('Gemini: no se pudo recuperar o descifrar la credencial en P8P.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Gemini: la credencial seleccionada no existe.');
        }
        $key = $credential['data']['api_key'] ?? null;
        if (!in_array($credential['type'] ?? null, ['gemini_api', 'generic_bearer'], true) || !is_string($key)) {
            throw new InvalidArgumentException('Gemini: credencial incompatible; usa Google Gemini API o Bearer Token / API Key.');
        }
        if (str_starts_with(ltrim($key), '{')) {
            $decoded = json_decode($key, true);
            $key = is_array($decoded) ? ($decoded['api_key'] ?? null) : null;
        }
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('Gemini: API Key ausente o con formato inválido.');
        }
        return $key;
    }

    private function request(string $path, array $payload, string $key, int $timeout): array
    {
        try {
            $response = ($this->client ?? new Client())->request('POST', 'https://generativelanguage.googleapis.com/v1beta' . $path, [
                'headers' => ['x-goog-api-key' => $key, 'Accept' => 'application/json'], 'json' => $payload,
                'timeout' => $timeout, 'read_timeout' => $timeout, 'connect_timeout' => 10,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true
            ]);
        } catch (\Throwable $e) {
            $timedOut = $e instanceof ConnectException && ($e->getHandlerContext()['errno'] ?? null) === 28;
            throw new RuntimeException($timedOut
                ? 'Gemini: se agotó el tiempo de espera. Revisa la conexión del servidor con Google o aumenta Tiempo máximo de espera; no se reintenta automáticamente.'
                : 'Gemini: fallo de conexión con Google; resultado incierto, sin reintento automático.');
        }
        $status = $response->getStatusCode();
        if ($status !== 200) {
            $response->getBody()->close();
            $reason = match ($status) {
                400 => 'configuración o solicitud rechazada por Google',
                401 => 'autenticación rechazada; revisa la API Key',
                403 => 'acceso denegado; revisa restricciones de clave, proyecto, región y facturación',
                404 => 'modelo inexistente o no disponible para este proyecto/API',
                408, 504 => 'tiempo de espera agotado en el servicio',
                429 => 'cuota agotada o límite de solicitudes; revisa tu proyecto',
                default => 'servicio no disponible o solicitud rechazada'
            };
            throw new RuntimeException('Gemini: ' . $reason . ' (HTTP ' . $status . '). Sin reintento automático.', $status);
        }
        $stream = $response->getBody();
        $raw = '';
        try {
            while (!$stream->eof() && strlen($raw) <= 16777216) {
                $chunk = $stream->read(min(65536, 16777217 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Gemini: respuesta incompleta o tiempo de lectura agotado; sin reintento automático.');
        } finally {
            $stream->close();
        }
        $body = strlen($raw) <= 16777216 ? json_decode($raw, true, 64) : null;
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE || isset($body['error'])) {
            throw new RuntimeException('Gemini: respuesta JSON inválida, demasiado grande o error del proveedor.');
        }
        return $body;
    }

    private function audio(array $body): array
    {
        if (($body['status'] ?? null) !== 'completed' || !is_array($body['steps'] ?? null)) {
            throw new RuntimeException('Gemini TTS: generación fallida o incompleta.');
        }
        $audio = [];
        foreach ($body['steps'] as $step) {
            if (!is_array($step) || ($step['type'] ?? null) !== 'model_output' || !is_array($step['content'] ?? null)) {
                throw new RuntimeException('Gemini TTS: respuesta con pasos no admitidos.');
            }
            foreach ($step['content'] as $part) {
                if (!is_array($part) || ($part['type'] ?? null) !== 'audio' || ($part['mime_type'] ?? null) !== 'audio/wav'
                    || !is_string($part['data'] ?? null) || isset($part['uri'])) {
                    throw new RuntimeException('Gemini TTS: se esperaba audio WAV inline.');
                }
                $bytes = base64_decode($part['data'], true);
                if ($bytes === false || strlen($bytes) < 12 || strlen($bytes) > 10485760
                    || base64_encode($bytes) !== $part['data'] || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
                    throw new RuntimeException('Gemini TTS: audio inválido o superior a 10 MiB.');
                }
                $audio[] = ['audio_base64' => $part['data'], 'mime_type' => 'audio/wav', 'size_bytes' => strlen($bytes)];
            }
        }
        if (count($audio) !== 1) {
            throw new RuntimeException('Gemini TTS: se esperaba exactamente un audio completo.');
        }
        return ['output_text' => '', 'output_type' => 'audio'] + $audio[0];
    }
}
