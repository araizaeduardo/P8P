<?php

namespace P8P\Nodes\Premium;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

/**
 * Proveedor de texto vía OpenRouter (API compatible con Chat Completions) para el puerto Model del AI Agent.
 * Un único endpoint fijo; el modelo se elige por identificador "proveedor/modelo".
 */
class OpenRouterChatModelNode implements NodeInterface
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
    private const DEFAULT_MODEL = 'openrouter/auto';
    private const MAX_RESPONSE_BYTES = 2097152;

    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'openrouter_model', 'name' => 'OpenRouter Chat Model', 'category' => 'Premium',
            'description' => 'Proveedor de texto para el puerto Model del AI Agent mediante OpenRouter (cientos de modelos con una sola clave).',
            'icon' => 'Sparkles', 'is_start' => false,
            'help_text' => 'Conecta la salida Model al AI Agent. Credencial generic_bearer: API Key de OpenRouter o JSON {"api_key":"..."}. El modelo se escribe como proveedor/modelo (p. ej. anthropic/claude-sonnet-4.5, openai/gpt-4o, meta-llama/llama-3.3-70b-instruct:free) u openrouter/auto para que OpenRouter elija. Consulta https://openrouter.ai/models. Sin streaming ni herramientas. Un fallo, una salida truncada (finish_reason length) o un 402/429 detienen el flujo; no hay reintentos automáticos.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial de OpenRouter'],
                'model' => ['type' => 'string', 'required' => true, 'default' => self::DEFAULT_MODEL,
                    'label' => 'Modelo (proveedor/modelo)', 'placeholder' => 'anthropic/claude-sonnet-4.5'],
                'temperature' => ['type' => 'number', 'required' => false, 'label' => 'Temperatura (0.0 a 2.0; vacío = predeterminada del modelo)'],
                'max_output_tokens' => ['type' => 'number', 'required' => false, 'default' => 4096, 'label' => 'Máximo de tokens de salida (1–16384)']
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
        $config = $this->configuration($parameters);
        return ['type' => 'chat_model', 'provider' => 'openrouter', 'credential_id' => $config['credential_id'],
            'model' => $config['model'], 'options' => array_filter(
                ['max_output_tokens' => $config['max_output_tokens'], 'temperature' => $config['temperature']],
                static fn ($v) => $v !== null
            )];
    }

    public function generate(array $configuration, array $messages): array
    {
        $config = $this->configuration($configuration);
        $messages = $this->messages($messages);
        $request = ['model' => $config['model'], 'messages' => $messages, 'max_tokens' => $config['max_output_tokens'],
            'stream' => false];
        if ($config['temperature'] !== null) {
            $request['temperature'] = $config['temperature'];
        }
        $secret = $this->apiKey($config['credential_id']);
        $body = $this->request($request, $secret);

        if (isset($body['error'])) {
            throw new RuntimeException('OpenRouter: el servicio devolvió un error; no se reintenta automáticamente.');
        }
        $choice = $body['choices'][0] ?? null;
        if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
            throw new RuntimeException('OpenRouter: estructura de salida inválida.');
        }
        $finish = $choice['finish_reason'] ?? null;
        if (!empty($choice['message']['tool_calls'])) {
            throw new RuntimeException('OpenRouter: la respuesta requiere herramientas no admitidas.');
        }
        if ($finish !== 'stop') {
            throw new RuntimeException('OpenRouter: generación incompleta o interrumpida (' . (is_string($finish) ? preg_replace('/[^a-z_]/', '', $finish) : 'sin motivo') . ').');
        }
        $text = $choice['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('OpenRouter: respuesta sin texto utilizable.');
        }
        $usage = [];
        foreach (['prompt_tokens' => 'input_tokens', 'completion_tokens' => 'output_tokens', 'total_tokens' => 'total_tokens'] as $from => $to) {
            if (isset($body['usage'][$from]) && is_int($body['usage'][$from]) && $body['usage'][$from] >= 0) {
                $usage[$to] = $body['usage'][$from];
            }
        }
        // El modelo que realmente respondió puede diferir del solicitado (openrouter/auto, fallbacks).
        $used = is_string($body['model'] ?? null) && preg_match(self::MODEL_PATTERN, $body['model']) === 1
            ? $body['model'] : $config['model'];
        return ['success' => true, 'provider' => 'openrouter', 'model_used' => $used,
            'output_text' => str_replace($secret, '[redacted]', $text), 'usage' => $usage, 'finish_reason' => 'stop'];
    }

    private const MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:\-\/~]{0,127}$/D';

    private function configuration(array $parameters): array
    {
        if (($parameters['_sub_connections'] ?? null) === []) {
            unset($parameters['_sub_connections']);
        }
        $options = $parameters['options'] ?? [];
        if (!is_array($options)) {
            throw new InvalidArgumentException('OpenRouter: opciones inválidas.');
        }
        $allowed = ['type', 'provider', 'credential_id', 'model', 'options', 'max_output_tokens', 'temperature'];
        if (array_diff(array_keys($parameters), $allowed) || array_diff(array_keys($options), ['max_output_tokens', 'temperature'])) {
            throw new InvalidArgumentException('OpenRouter: parámetros no admitidos; no se permiten claves directas, herramientas ni opciones arbitrarias.');
        }
        if (isset($parameters['provider']) && $parameters['provider'] !== 'openrouter') {
            throw new InvalidArgumentException('OpenRouter: proveedor incompatible.');
        }
        $config = array_merge($parameters, $options);
        $credential = $config['credential_id'] ?? null;
        $model = $config['model'] ?? self::DEFAULT_MODEL;
        $tokens = $config['max_output_tokens'] ?? 4096;
        $temperature = $config['temperature'] ?? null;
        if ($temperature === '') {
            $temperature = null;
        }
        if (!is_string($credential) || trim($credential) === '' || strlen($credential) > 255
            || !is_string($model) || preg_match(self::MODEL_PATTERN, $model) !== 1
            || !is_scalar($tokens) || is_bool($tokens) || filter_var($tokens, FILTER_VALIDATE_INT) === false
            || (int) $tokens < 1 || (int) $tokens > 16384
            || ($temperature !== null && (!is_scalar($temperature) || is_bool($temperature)
                || !is_numeric($temperature) || (float) $temperature < 0 || (float) $temperature > 2))) {
            throw new InvalidArgumentException('OpenRouter: credencial, modelo, temperatura o límite de tokens inválidos.');
        }
        return ['credential_id' => $credential, 'model' => $model, 'max_output_tokens' => (int) $tokens,
            'temperature' => $temperature === null ? null : (float) $temperature];
    }

    private function messages(array $messages): array
    {
        if (!array_is_list($messages) || count($messages) < 1 || count($messages) > 100) {
            throw new InvalidArgumentException('OpenRouter: se requieren entre 1 y 100 mensajes de texto.');
        }
        $size = 0;
        $hasUser = false;
        foreach ($messages as $message) {
            if (!is_array($message) || array_diff(array_keys($message), ['role', 'content'])
                || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || !is_string($message['content'] ?? null) || $message['content'] === ''
                || !mb_check_encoding($message['content'], 'UTF-8')) {
                throw new InvalidArgumentException('OpenRouter: mensaje inválido; solo roles system/user/assistant y texto UTF-8.');
            }
            $hasUser = $hasUser || $message['role'] === 'user';
            $size += strlen($message['content']);
        }
        if (!$hasUser || $size > 1048576) {
            throw new InvalidArgumentException('OpenRouter: falta mensaje de usuario o se supera 1 MiB de texto.');
        }
        return $messages;
    }

    private function apiKey(string $id): string
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRouter: no se pudo recuperar o descifrar la credencial.');
        }
        $key = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? '') !== 'generic_bearer' || !is_string($key)) {
            throw new InvalidArgumentException('OpenRouter: se requiere una credencial generic_bearer con api_key.');
        }
        if (str_starts_with(ltrim($key), '{')) {
            $data = json_decode($key, true);
            $key = is_array($data) ? ($data['api_key'] ?? null) : null;
        }
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('OpenRouter: API Key inválida.');
        }
        return $key;
    }

    private function request(array $payload, string $secret): array
    {
        try {
            $response = ($this->client ?? new Client())->request('POST', self::ENDPOINT, [
                'headers' => ['Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json',
                    'X-Title' => 'P8P'],
                'json' => $payload, 'timeout' => 120, 'connect_timeout' => 10, 'read_timeout' => 120,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRouter: fallo de transporte; resultado incierto, sin reintento automático.');
        }
        $status = $response->getStatusCode();
        if ($status !== 200) {
            $response->getBody()->close();
            $hint = match ($status) {
                401 => ' (API Key inválida o revocada)',
                402 => ' (créditos insuficientes en OpenRouter)',
                429 => ' (límite de peticiones alcanzado)',
                default => ''
            };
            throw new RuntimeException('OpenRouter: solicitud rechazada por el servicio (HTTP ' . $status . ')' . $hint . '.');
        }
        $stream = $response->getBody();
        $raw = '';
        try {
            while (!$stream->eof() && strlen($raw) <= self::MAX_RESPONSE_BYTES) {
                $chunk = $stream->read(min(65536, self::MAX_RESPONSE_BYTES + 1 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Lectura incompleta.');
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRouter: fallo al leer la respuesta, sin reintento automático.');
        } finally {
            $stream->close();
        }
        $data = strlen($raw) <= self::MAX_RESPONSE_BYTES ? json_decode($raw, true, 64) : null;
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('OpenRouter: respuesta JSON inválida o mayor de 2 MiB.');
        }
        return $data;
    }
}
