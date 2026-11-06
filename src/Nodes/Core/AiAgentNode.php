<?php

namespace P8P\Nodes\Core;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Interfaces\NodeInterface;
use P8P\Core\ConversationMemoryStore;
use RuntimeException;

class AiAgentNode implements NodeInterface
{
    public function __construct(
        private ?array $modelProviders = null,
        private ?ClientInterface $client = null,
        private ?ConversationMemoryStore $memoryStore = null
    )
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'ai_agent',
            'name' => 'AI Agent',
            'category' => 'Core',
            'description' => 'Agente conversacional con proveedor de modelo y memoria de sesión opcional.',
            'icon' => 'Bot',
            'is_start' => false,
            'help_text' => 'Conecta un modelo al puerto Model y, opcionalmente, Simple Memory al puerto Memory. El mensaje se obtiene de Chat Trigger o Mensaje del usuario. Simple Memory recuerda por sesión y guarda solo respuestas de texto válidas; no combina Historial JSON manual ni audio TTS. Sin memoria, Historial JSON permite mensajes explícitos. Las herramientas aún no se ejecutan.',
            'parameters' => [
                'user_prompt' => [
                    'type' => 'textarea', 'required' => false, 'label' => 'Mensaje del usuario (opcional)',
                    'placeholder' => '{{user_message}}'
                ],
                'messages' => [
                    'type' => 'textarea', 'required' => false, 'label' => 'Historial JSON manual (sin Simple Memory)',
                    'placeholder' => '[{"role":"user","content":"Hola"}]'
                ],
                'system_prompt' => [
                    'type' => 'textarea',
                    'required' => true,
                    'label' => 'Prompt del Sistema (System Prompt)',
                    'default' => 'Eres un asistente útil y preciso.',
                    'placeholder' => 'Ej. Eres un asistente virtual experto en ventas. Responde siempre en español y de forma concisa.'
                ],
                'temperature' => [
                    'type' => 'number',
                    'required' => false,
                    'label' => 'Creatividad / Temperatura (0.0 a 1.0)',
                    'default' => 0.7,
                    'placeholder' => '0.7'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return true;
    }

    public function execute(array $parameters, array $context): array
    {
        $connections = $parameters['_sub_connections'] ?? [];
        if (!is_array($connections) || !is_array($connections['model'] ?? null) || count($connections['model']) !== 1
            || !is_array($connections['model'][0] ?? null)) {
            throw new InvalidArgumentException('Conecta exactamente un modelo al puerto Model del AI Agent.');
        }
        if (!empty($connections['tools'])) {
            throw new InvalidArgumentException('Este agente todavía no ejecuta llamadas de herramientas; desconecta el puerto Tools.');
        }
        $model = $connections['model'][0];
        $provider = $model['provider'] ?? null;
        if (!in_array($provider, ['openai', 'anthropic', 'gemini', 'ollama'], true)) {
            throw new InvalidArgumentException('Proveedor de IA no soportado.');
        }
        $system = $parameters['system_prompt'] ?? 'Eres un asistente de automatización en P8P.';
        if (!is_string($system) || strlen($system) > 131072 || !mb_check_encoding($system, 'UTF-8')) {
            throw new InvalidArgumentException('Prompt del sistema inválido o demasiado grande.');
        }
        $messages = $this->messages($parameters, $context);
        $memories = $connections['memory'] ?? [];
        if (!is_array($memories) || count($memories) > 1) {
            throw new InvalidArgumentException('Conecta como máximo un nodo Simple Memory al AI Agent.');
        }
        $turn = null;
        $store = null;
        if ($memories !== []) {
            $memory = $memories[0] ?? null;
            if (!is_array($memory) || ($memory['type'] ?? null) !== 'chat_memory'
                || ($memory['provider'] ?? null) !== 'simple_memory') {
                throw new InvalidArgumentException('El puerto Memory requiere un nodo Simple Memory válido.');
            }
            if (isset($parameters['messages']) && $parameters['messages'] !== '' && $parameters['messages'] !== null) {
                throw new InvalidArgumentException('Usa Simple Memory o Historial JSON manual, sin combinarlos.');
            }
            if ($provider === 'gemini' && str_contains($model['model'] ?? '', 'tts')) {
                throw new InvalidArgumentException('Simple Memory guarda conversaciones de texto; desconecta Memory para generar audio TTS.');
            }
            $store = $this->memoryStore ?? ConversationMemoryStore::connect();
            $turn = $store->begin($memory);
            $messages = [...$turn['messages'], ...$messages];
        }
        try {
            $user = $messages[array_key_last($messages)]['content'];
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
            if ($provider === 'ollama') {
                $result = $this->ollama($model, $messages);
            } else {
                $modelNode = $this->modelProviders[$provider] ?? match ($provider) {
                    'openai' => new \P8P\Nodes\Premium\OpenAIChatModelNode(),
                    'anthropic' => new \P8P\Nodes\Premium\AnthropicClaudeModelNode(),
                    'gemini' => new \P8P\Nodes\Premium\GeminiChatModelNode($this->client)
                };
                $result = $modelNode->generate($model, $messages);
            }
            if ($turn !== null) {
                if (($result['success'] ?? false) !== true || !is_string($result['output_text'] ?? null)
                    || trim($result['output_text']) === '' || ($result['output_type'] ?? 'text') !== 'text') {
                    throw new RuntimeException('Simple Memory: el modelo no devolvió una respuesta de texto válida; el turno no se guardó.');
                }
                $store->complete($turn, $user, $result['output_text']);
                $result['memory'] = ['provider' => 'simple_memory', 'previous_turns' => intdiv(count($turn['messages']), 2)];
                $turn = null;
            }
            return $result;
        } finally {
            if ($turn !== null) {
                $store->release($turn);
            }
        }
    }

    private function messages(array $parameters, array $context): array
    {
        $messages = $parameters['messages'] ?? null;
        if ($messages !== null && $messages !== '') {
            try {
                $messages = is_string($messages) ? json_decode($messages, true, 32, JSON_THROW_ON_ERROR) : $messages;
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Historial de mensajes JSON inválido.');
            }
            if (!is_array($messages) || !array_is_list($messages) || $messages === [] || count($messages) > 100) {
                throw new InvalidArgumentException('El historial debe ser una lista de 1 a 100 mensajes.');
            }
        } else {
            $input = $parameters['user_prompt'] ?? $context['user_message'] ?? null;
            if ($input === '' || $input === null) {
                $input = $context['user_message'] ?? null;
            }
            if ($input === null || $input === '') {
                foreach (array_reverse($context, true) as $id => $output) {
                    if (str_starts_with((string)$id, '_') || !is_array($output)) {
                        continue;
                    }
                    $input = $output['user_message'] ?? $output['value'] ?? $output['data']['title']
                        ?? $output['output']['value'] ?? $output['output']['data']['title'] ?? null;
                    if ($input !== null && $input !== '') {
                        break;
                    }
                }
            }
            $messages = [['role' => 'user', 'content' => $input ?? 'Hola, procesa esta solicitud.']];
        }
        $size = 0;
        foreach ($messages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['user', 'assistant', 'system'], true)
                || !is_string($message['content'] ?? null) || trim($message['content']) === ''
                || !mb_check_encoding($message['content'], 'UTF-8')) {
                throw new InvalidArgumentException('Cada mensaje requiere un rol válido y contenido de texto no vacío.');
            }
            $size += strlen($message['content']);
            if ($size > 131072) {
                throw new InvalidArgumentException('El historial supera 128 KiB.');
            }
        }
        return $messages;
    }

    private function ollama(array $config, array $messages): array
    {
        $endpoint = rtrim($config['endpoint'] ?? 'http://127.0.0.1:11434', '/');
        $model = $config['model'] ?? 'llama3';
        $client = $this->client ?? new Client();
        try {
            $response = $client->request('POST', $endpoint . '/api/chat', [
                'json' => ['model' => $model, 'messages' => $messages, 'stream' => false],
                'timeout' => 90, 'connect_timeout' => 10, 'read_timeout' => 90,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false
            ]);
            $raw = $response->getBody()->read(2097153);
            $response->getBody()->close();
        } catch (\Throwable $e) {
            throw new RuntimeException('Ollama: fallo de transporte; sin reintento automático.');
        }
        $body = strlen($raw) <= 2097152 ? json_decode($raw, true) : null;
        if ($response->getStatusCode() !== 200 || !is_array($body) || isset($body['error'])
            || ($body['done'] ?? null) !== true || !is_string($body['message']['content'] ?? null)
            || trim($body['message']['content']) === '' || !empty($body['message']['tool_calls'])) {
            throw new RuntimeException('Ollama: generación fallida, incompleta o sin respuesta textual válida.');
        }
        return ['success' => true, 'provider' => 'ollama', 'model_used' => $model,
            'output_text' => $body['message']['content'], 'timestamp' => date('Y-m-d H:i:s')];
    }
}
