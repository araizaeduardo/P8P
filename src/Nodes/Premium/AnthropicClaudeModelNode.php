<?php

namespace P8P\Nodes\Premium;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class AnthropicClaudeModelNode implements NodeInterface
{
    private const API_VERSION = '2026-10-21';

    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'anthropic_model', 'name' => 'Anthropic Claude Model', 'category' => 'Premium',
            'description' => 'Proveedor Claude para el puerto Model del AI Agent mediante Messages API.',
            'icon' => 'Sparkles', 'is_start' => false,
            'help_text' => 'Conecta la salida Model al AI Agent. Credencial generic_bearer: API Key o JSON {"api_key":"...","workspace_id":"..."}. workspace_id es necesario para claves no limitadas a un workspace. Introduce un modelo habilitado. API 2026-10-21, sin temperatura, streaming ni herramientas. Salidas incompletas detienen el flujo.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial de Anthropic'],
                'model' => ['type' => 'string', 'required' => true, 'label' => 'Identificador de Claude habilitado'],
                'max_tokens' => ['type' => 'number', 'required' => false, 'default' => 4096, 'label' => 'Máximo de tokens de salida (1–16384)']
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
        return ['type' => 'chat_model', 'provider' => 'anthropic', 'credential_id' => $config['credential_id'],
            'model' => $config['model'], 'options' => ['max_tokens' => $config['max_tokens']]];
    }

    public function generate(array $configuration, array $messages): array
    {
        $config = $this->configuration($configuration);
        [$system, $conversation] = $this->messages($messages);
        $request = ['model' => $config['model'], 'messages' => $conversation, 'max_tokens' => $config['max_tokens'], 'stream' => false];
        if ($system !== []) {
            $request['system'] = $system;
        }
        $credential = $this->apiCredential($config['credential_id']);
        $body = $this->request($request, $credential);
        if (isset($body['error']) || ($body['type'] ?? '') !== 'message' || ($body['role'] ?? '') !== 'assistant'
            || !in_array($body['stop_reason'] ?? null, ['end_turn', 'stop_sequence'], true)
            || ($body['stop_details']['type'] ?? '') === 'refusal' || !is_array($body['content'] ?? null)) {
            throw new RuntimeException('Anthropic: generación fallida, rechazada, incompleta o pendiente de herramientas; sin reintento automático.');
        }
        $text = '';
        foreach ($body['content'] as $block) {
            if (!is_array($block)) {
                throw new RuntimeException('Anthropic: estructura de salida inválida.');
            }
            // Reasoning blocks and their signatures are private provider state, not assistant text.
            if (in_array($block['type'] ?? '', ['thinking', 'redacted_thinking'], true)) {
                continue;
            }
            if (($block['type'] ?? '') !== 'text' || !is_string($block['text'] ?? null)) {
                throw new RuntimeException('Anthropic: respuesta no textual o herramientas no admitidas.');
            }
            $text .= $block['text'];
        }
        if ($text === '') {
            throw new RuntimeException('Anthropic: respuesta sin texto utilizable.');
        }
        $usage = [];
        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $key) {
            if (isset($body['usage'][$key]) && is_int($body['usage'][$key]) && $body['usage'][$key] >= 0) {
                $usage[$key] = $body['usage'][$key];
            }
        }
        return ['success' => true, 'provider' => 'anthropic', 'model_used' => $config['model'],
            'output_text' => str_replace($credential['api_key'], '[redacted]', $text), 'usage' => $usage,
            'finish_reason' => $body['stop_reason']];
    }

    private function configuration(array $parameters): array
    {
        if (($parameters['_sub_connections'] ?? null) === []) {
            unset($parameters['_sub_connections']);
        }
        $options = $parameters['options'] ?? [];
        if (!is_array($options) || array_diff(array_keys($parameters), ['type', 'provider', 'credential_id', 'model', 'options', 'max_tokens'])
            || array_diff(array_keys($options), ['max_tokens'])) {
            throw new InvalidArgumentException('Anthropic: parámetros no admitidos; no se permiten claves directas, herramientas ni opciones arbitrarias.');
        }
        if (isset($parameters['provider']) && $parameters['provider'] !== 'anthropic') {
            throw new InvalidArgumentException('Anthropic: proveedor incompatible.');
        }
        $config = array_merge($parameters, $options);
        $credential = $config['credential_id'] ?? null;
        $model = $config['model'] ?? null;
        $tokens = $config['max_tokens'] ?? 4096;
        if (!is_string($credential) || trim($credential) === '' || strlen($credential) > 255
            || !is_string($model) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\-]{0,127}$/D', $model) !== 1
            || !is_scalar($tokens) || is_bool($tokens) || filter_var($tokens, FILTER_VALIDATE_INT) === false
            || (int) $tokens < 1 || (int) $tokens > 16384) {
            throw new InvalidArgumentException('Anthropic: credencial, modelo o límite de salida inválidos.');
        }
        return ['credential_id' => $credential, 'model' => $model, 'max_tokens' => (int) $tokens];
    }

    private function messages(array $messages): array
    {
        if (!array_is_list($messages) || count($messages) < 1 || count($messages) > 100) {
            throw new InvalidArgumentException('Anthropic: se requieren entre 1 y 100 mensajes de texto.');
        }
        $size = 0;
        $system = [];
        $conversation = [];
        foreach ($messages as $message) {
            if (!is_array($message) || array_diff(array_keys($message), ['role', 'content'])
                || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || !is_string($message['content'] ?? null) || $message['content'] === ''
                || !mb_check_encoding($message['content'], 'UTF-8')) {
                throw new InvalidArgumentException('Anthropic: mensaje inválido; solo roles system/user/assistant y texto UTF-8.');
            }
            if ($message['role'] === 'system') {
                if ($conversation !== []) {
                    throw new InvalidArgumentException('Anthropic: las instrucciones de sistema deben preceder a la conversación.');
                }
                $system[] = ['type' => 'text', 'text' => $message['content']];
            } else {
                $conversation[] = ['role' => $message['role'], 'content' => [['type' => 'text', 'text' => $message['content']]]];
            }
            $size += strlen($message['content']);
        }
        if ($size > 1048576 || ($conversation[0]['role'] ?? '') !== 'user' || ($conversation[count($conversation) - 1]['role'] ?? '') !== 'user') {
            throw new InvalidArgumentException('Anthropic: la conversación debe empezar y terminar en user y no superar 1 MiB. No se admite prefill.');
        }
        return [$system, $conversation];
    }

    private function apiCredential(string $id): array
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('Anthropic: no se pudo recuperar o descifrar la credencial.');
        }
        $data = $credential['data'] ?? null;
        if (($credential['type'] ?? '') !== 'generic_bearer' || !is_array($data) || !is_string($data['api_key'] ?? null)) {
            throw new InvalidArgumentException('Anthropic: se requiere una credencial generic_bearer con api_key.');
        }
        if (str_starts_with(ltrim($data['api_key']), '{')) {
            $data = json_decode($data['api_key'], true);
        }
        $key = $data['api_key'] ?? null;
        $workspace = $data['workspace_id'] ?? null;
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)
            || ($workspace !== null && (!is_string($workspace) || preg_match('/^wrkspc_[A-Za-z0-9]{1,128}$/D', $workspace) !== 1))) {
            throw new InvalidArgumentException('Anthropic: API Key o workspace_id inválidos.');
        }
        return ['api_key' => $key, 'workspace_id' => $workspace];
    }

    private function request(array $payload, array $credential): array
    {
        $headers = ['Authorization' => 'Bearer ' . $credential['api_key'], 'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json'];
        if ($credential['workspace_id'] !== null) {
            $headers['anthropic-workspace-id'] = $credential['workspace_id'];
        }
        try {
            $response = ($this->client ?? new Client())->request('POST', 'https://api.anthropic.com/v1/messages', [
                'headers' => $headers, 'json' => $payload, 'timeout' => 90, 'connect_timeout' => 10, 'read_timeout' => 90,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Anthropic: fallo de transporte; resultado incierto, sin reintento automático.');
        }
        if ($response->getStatusCode() !== 200) {
            $response->getBody()->close();
            throw new RuntimeException('Anthropic: solicitud rechazada por el servicio (HTTP ' . $response->getStatusCode() . ').');
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
            throw new RuntimeException('Anthropic: fallo al leer la respuesta, sin reintento automático.');
        } finally {
            $stream->close();
        }
        $data = strlen($raw) <= 2097152 ? json_decode($raw, true, 64) : null;
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Anthropic: respuesta JSON inválida o mayor de 2 MiB.');
        }
        return $data;
    }
}
