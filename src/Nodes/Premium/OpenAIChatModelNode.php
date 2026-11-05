<?php

namespace P8P\Nodes\Premium;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class OpenAIChatModelNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'openai_model', 'name' => 'OpenAI ChatGPT Model', 'category' => 'Premium',
            'description' => 'Proveedor de texto para el puerto Model del AI Agent mediante Responses API.',
            'icon' => 'Sparkles', 'is_start' => false,
            'help_text' => 'Conecta la salida Model al AI Agent. Credencial generic_bearer: API Key o JSON {"api_key":"..."}. Introduce un modelo habilitado en tu cuenta. Sin streaming ni herramientas. No se envía temperatura; el límite incluye tokens de razonamiento. Un fallo o una salida incompleta detiene el flujo.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial de OpenAI'],
                'model' => ['type' => 'string', 'required' => true, 'label' => 'Identificador de modelo habilitado'],
                'max_output_tokens' => ['type' => 'number', 'required' => false, 'default' => 4096, 'label' => 'Máximo de tokens de salida (1–16384)'],
                'reasoning_effort' => ['type' => 'select', 'required' => false, 'default' => 'default',
                    'label' => 'Razonamiento (opción explícita solo gpt-6.1-sol)',
                    'options' => array_map(static fn ($value) => ['value' => $value, 'label' => $value], ['default', 'low', 'medium', 'high', 'xhigh', 'max'])]
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
        return ['type' => 'chat_model', 'provider' => 'openai', 'credential_id' => $config['credential_id'],
            'model' => $config['model'], 'options' => ['max_output_tokens' => $config['max_output_tokens'],
                'reasoning_effort' => $config['reasoning_effort']]];
    }

    public function generate(array $configuration, array $messages): array
    {
        $config = $this->configuration($configuration);
        $messages = $this->messages($messages);
        $request = ['model' => $config['model'], 'input' => $messages, 'max_output_tokens' => $config['max_output_tokens'],
            'stream' => false, 'store' => false];
        if ($config['reasoning_effort'] !== 'default') {
            $request['reasoning'] = ['effort' => $config['reasoning_effort']];
        }
        $secret = $this->apiKey($config['credential_id']);
        $body = $this->request($request, $secret);
        if (isset($body['error']) || ($body['status'] ?? null) !== 'completed' || !is_array($body['output'] ?? null)) {
            throw new RuntimeException('OpenAI: generación fallida o incompleta; no se reintenta automáticamente.');
        }
        $text = '';
        foreach ($body['output'] as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('OpenAI: estructura de salida inválida.');
            }
            // Internal reasoning is not exposed or reinterpreted as assistant text.
            if (($item['type'] ?? '') === 'reasoning') {
                continue;
            }
            if (($item['type'] ?? '') !== 'message' || ($item['role'] ?? '') !== 'assistant'
                || ($item['status'] ?? '') !== 'completed' || !is_array($item['content'] ?? null)) {
                throw new RuntimeException('OpenAI: la respuesta requiere contenido o herramientas no admitidas.');
            }
            foreach ($item['content'] as $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'output_text' || !is_string($block['text'] ?? null)) {
                    throw new RuntimeException('OpenAI: respuesta rechazada o contenido no textual.');
                }
                $text .= $block['text'];
            }
        }
        if ($text === '') {
            throw new RuntimeException('OpenAI: respuesta sin texto utilizable.');
        }
        $usage = [];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
            if (isset($body['usage'][$key]) && is_int($body['usage'][$key]) && $body['usage'][$key] >= 0) {
                $usage[$key] = $body['usage'][$key];
            }
        }
        return ['success' => true, 'provider' => 'openai', 'model_used' => $config['model'],
            'output_text' => str_replace($secret, '[redacted]', $text), 'usage' => $usage, 'finish_reason' => 'completed'];
    }

    private function configuration(array $parameters): array
    {
        if (($parameters['_sub_connections'] ?? null) === []) {
            unset($parameters['_sub_connections']);
        }
        $options = $parameters['options'] ?? [];
        if (!is_array($options)) {
            throw new InvalidArgumentException('OpenAI: opciones inválidas.');
        }
        $allowed = ['type', 'provider', 'credential_id', 'model', 'options', 'max_output_tokens', 'reasoning_effort'];
        if (array_diff(array_keys($parameters), $allowed) || array_diff(array_keys($options), ['max_output_tokens', 'reasoning_effort'])) {
            throw new InvalidArgumentException('OpenAI: parámetros no admitidos; no se permiten claves directas, herramientas ni opciones arbitrarias.');
        }
        if (isset($parameters['provider']) && $parameters['provider'] !== 'openai') {
            throw new InvalidArgumentException('OpenAI: proveedor incompatible.');
        }
        $config = array_merge($parameters, $options);
        $credential = $config['credential_id'] ?? null;
        $model = $config['model'] ?? null;
        $tokens = $config['max_output_tokens'] ?? 4096;
        $effort = $config['reasoning_effort'] ?? 'default';
        if (!is_string($credential) || trim($credential) === '' || strlen($credential) > 255
            || !is_string($model) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\-]{0,127}$/D', $model) !== 1
            || !is_scalar($tokens) || is_bool($tokens) || filter_var($tokens, FILTER_VALIDATE_INT) === false
            || (int) $tokens < 1 || (int) $tokens > 16384
            || !in_array($effort, ['default', 'low', 'medium', 'high', 'xhigh', 'max'], true)
            || ($effort !== 'default' && $model !== 'gpt-6.1-sol')) {
            throw new InvalidArgumentException('OpenAI: credencial, modelo, límite o capacidad de razonamiento inválidos.');
        }
        return ['credential_id' => $credential, 'model' => $model, 'max_output_tokens' => (int) $tokens, 'reasoning_effort' => $effort];
    }

    private function messages(array $messages): array
    {
        if (!array_is_list($messages) || count($messages) < 1 || count($messages) > 100) {
            throw new InvalidArgumentException('OpenAI: se requieren entre 1 y 100 mensajes de texto.');
        }
        $size = 0;
        $hasUser = false;
        foreach ($messages as $message) {
            if (!is_array($message) || array_diff(array_keys($message), ['role', 'content'])
                || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || !is_string($message['content'] ?? null) || $message['content'] === ''
                || !mb_check_encoding($message['content'], 'UTF-8')) {
                throw new InvalidArgumentException('OpenAI: mensaje inválido; solo roles system/user/assistant y texto UTF-8.');
            }
            $hasUser = $hasUser || $message['role'] === 'user';
            $size += strlen($message['content']);
        }
        if (!$hasUser || $size > 1048576) {
            throw new InvalidArgumentException('OpenAI: falta mensaje de usuario o se supera 1 MiB de texto.');
        }
        return $messages;
    }

    private function apiKey(string $id): string
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenAI: no se pudo recuperar o descifrar la credencial.');
        }
        $key = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? '') !== 'generic_bearer' || !is_string($key)) {
            throw new InvalidArgumentException('OpenAI: se requiere una credencial generic_bearer con api_key.');
        }
        if (str_starts_with(ltrim($key), '{')) {
            $data = json_decode($key, true);
            $key = is_array($data) ? ($data['api_key'] ?? null) : null;
        }
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('OpenAI: API Key inválida.');
        }
        return $key;
    }

    private function request(array $payload, string $secret): array
    {
        try {
            $response = ($this->client ?? new Client())->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => ['Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json'],
                'json' => $payload, 'timeout' => 90, 'connect_timeout' => 10, 'read_timeout' => 90,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenAI: fallo de transporte; resultado incierto, sin reintento automático.');
        }
        if ($response->getStatusCode() !== 200) {
            $response->getBody()->close();
            throw new RuntimeException('OpenAI: solicitud rechazada por el servicio (HTTP ' . $response->getStatusCode() . ').');
        }
        $stream = $response->getBody();
        $raw = '';
        try {
            while (!$stream->eof() && strlen($raw) <= 2097152) {
                $chunk = $stream->read(min(65536, 2097153 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Lectura incompleta.');
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenAI: fallo al leer la respuesta, sin reintento automático.');
        } finally {
            $stream->close();
        }
        $data = strlen($raw) <= 2097152 ? json_decode($raw, true, 64) : null;
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('OpenAI: respuesta JSON inválida o mayor de 2 MiB.');
        }
        return $data;
    }
}
