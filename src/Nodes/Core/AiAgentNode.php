<?php

namespace P8P\Nodes\Core;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use InvalidArgumentException;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class AiAgentNode implements NodeInterface
{
    public function __construct(private ?array $modelProviders = null, private ?ClientInterface $client = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'ai_agent',
            'name' => 'AI Agent',
            'category' => 'Core',
            'description' => 'Agente autónomo con capacidad de razonamiento ReAct y ejecución de herramientas.',
            'icon' => 'Bot',
            'is_start' => false,
            'help_text' => 'Define las instrucciones base del modelo en el Prompt del Sistema. Puedes pasar datos de contexto o entradas del usuario dinámicamente usando {{user_message}} o {{variable}}.',
            'parameters' => [
                'user_prompt' => [
                    'type' => 'textarea', 'required' => false, 'label' => 'Mensaje del usuario: OpenAI / Claude (opcional)',
                    'placeholder' => '{{user_message}}'
                ],
                'messages' => [
                    'type' => 'textarea', 'required' => false, 'label' => 'Historial JSON opcional (OpenAI / Claude)',
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
        $systemPrompt = $parameters['system_prompt'] ?? 'Eres un asistente de automatización en P8P.';
        $subConnections = $parameters['_sub_connections'] ?? [];

        if (empty($subConnections['model'][0])) {
            throw new InvalidArgumentException("No se encontró ningún modelo conectado al sub-puerto 'Model*' del AI Agent.");
        }

        $modelConfig = $subConnections['model'][0];

        // Determinar la instrucción del usuario desde el contexto previo
        $userPrompt = "Hola, procesa esta solicitud.";
        foreach (array_reverse($context) as $nodeOutput) {
            if (isset($nodeOutput['output']['data']['title'])) {
                $userPrompt = $nodeOutput['output']['data']['title'];
                break;
            } elseif (isset($nodeOutput['output']['value'])) {
                $userPrompt = $nodeOutput['output']['value'];
                break;
            } elseif (isset($nodeOutput['value'])) {
                $userPrompt = $nodeOutput['value'];
                break;
            }
        }

        $provider = $modelConfig['provider'] ?? 'ollama';
        $client = $this->client ?? new Client(['timeout' => 30]);

        if (in_array($provider, ['openai', 'anthropic'], true)) {
            // Existing MCP nodes provide descriptors, not an executable tool-call protocol.
            if (!empty($subConnections['tools'])) {
                throw new InvalidArgumentException('Este agente todavía no ejecuta llamadas de herramientas; desconecta el puerto Tools para usar este modelo.');
            }
            if (!is_string($systemPrompt) || strlen($systemPrompt) > 131072) {
                throw new InvalidArgumentException('Prompt del sistema inválido o demasiado grande.');
            }
            $messages = $parameters['messages'] ?? null;
            if ($messages !== null && $messages !== '') {
                try {
                    $messages = is_string($messages) ? json_decode($messages, true, 32, JSON_THROW_ON_ERROR) : $messages;
                } catch (\JsonException $e) {
                    throw new InvalidArgumentException('Historial de mensajes JSON inválido.');
                }
                if (!is_array($messages) || !array_is_list($messages)) {
                    throw new InvalidArgumentException('El historial debe ser una lista de mensajes.');
                }
            } else {
                $input = $parameters['user_prompt'] ?? $context['user_message'] ?? null;
                if ($input === '') {
                    $input = $context['user_message'] ?? null;
                }
                if ($input === null || $input === '') {
                    foreach (array_reverse($context, true) as $id => $output) {
                        if (str_starts_with((string)$id, '_') || !is_array($output)) {
                            continue;
                        }
                        $input = $output['user_message'] ?? $output['value'] ?? $output['data']['title'] ?? null;
                        if ($input !== null) {
                            break;
                        }
                    }
                }
                if ($input === null) {
                    $input = $userPrompt;
                }
                if (!is_string($input) || $input === '') {
                    throw new InvalidArgumentException('El mensaje del usuario debe ser texto no vacío.');
                }
                $messages = [['role' => 'user', 'content' => $input]];
            }
            array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
            $modelNode = $this->modelProviders[$provider] ?? ($provider === 'openai'
                ? new \P8P\Nodes\Premium\OpenAIChatModelNode()
                : new \P8P\Nodes\Premium\AnthropicClaudeModelNode());
            return $modelNode->generate($modelConfig, $messages);
        }

        // --- PROVEEDOR: GOOGLE GEMINI ---
        if ($provider === 'gemini') {
            $apiKey = trim($modelConfig['api_key'] ?? '');
            $model = trim($modelConfig['model'] ?? 'gemini-3.8-flash');

            if (empty($apiKey)) {
                throw new InvalidArgumentException("La API Key de Gemini no está configurada.");
            }

            // Normalizar el nombre del modelo eliminando prefijos si los hay
            $model = preg_replace('/^models\//', '', $model);

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            try {
                $response = $client->post($url, [
                    'json' => [
                        'system_instruction' => [
                            'parts' => [['text' => $systemPrompt]]
                        ],
                        'contents' => [
                            [
                                'role' => 'user',
                                'parts' => [['text' => $userPrompt]]
                            ]
                        ]
                    ]
                ]);

                $body = json_decode($response->getBody()->getContents(), true);
                $outputText = $body['candidates'][0]['content']['parts'][0]['text'] ?? 'Sin respuesta de Gemini.';

                return [
                    'success' => true,
                    'provider' => 'gemini',
                    'model_used' => $model,
                    'output_text' => $outputText,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            } catch (ClientException $e) {
                $responseBody = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
                $jsonError = json_decode($responseBody, true);
                $apiMessage = $jsonError['error']['message'] ?? $e->getMessage();

                throw new RuntimeException("Error Google Gemini ({$e->getCode()}): {$apiMessage}");
            }
        }

        // --- PROVEEDOR: OLLAMA ---
        if ($provider === 'ollama') {
            $endpoint = rtrim($modelConfig['endpoint'] ?? 'http://127.0.0.1:11434', '/');
            $model = $modelConfig['model'] ?? 'llama3';

            $response = $client->post("{$endpoint}/api/chat", [
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt]
                    ],
                    'stream' => false
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $outputText = $body['message']['content'] ?? 'Sin respuesta de Ollama.';

            return [
                'success' => true,
                'provider' => 'ollama',
                'model_used' => $model,
                'output_text' => $outputText,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }

        throw new RuntimeException("Proveedor de IA no soportado: '{$provider}'");
    }
}
