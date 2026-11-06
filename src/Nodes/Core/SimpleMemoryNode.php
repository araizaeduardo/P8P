<?php

namespace P8P\Nodes\Core;

use InvalidArgumentException;
use P8P\Interfaces\NodeInterface;

/** Descriptor only: reading and writing happen around a successful agent turn. */
class SimpleMemoryNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'simple_memory', 'name' => 'Simple Memory', 'category' => 'Core',
            'description' => 'Historial de conversación cifrado y persistente por workflow y sesión.',
            'icon' => 'Database', 'is_start' => false,
            'help_text' => 'Conecta la salida superior al puerto Memory del AI Agent. La sesión se obtiene automáticamente del Chat Trigger; también puedes indicar {{session_id}}. Cada workflow y nodo de memoria tiene su propio historial. Guarda solo turnos de texto completos tras una respuesta válida. Usa Gemini de texto, OpenAI, Claude u Ollama; TTS no admite esta memoria. No combina Historial JSON manual con memoria automática. Conversaciones simultáneas de la misma sesión se rechazan mientras hay una respuesta en curso. El límite es por turnos usuario/asistente (1–20), con caducidad de 60 segundos a 30 días (predeterminado 24 h). El historial cifrado permanece hasta caducar y se limpia al ejecutar cron. Una sesión nueva inicia otra conversación. Requiere la migración de Simple Memory para instalaciones existentes.',
            'parameters' => [
                'session_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de sesión (vacío: Chat Trigger)', 'placeholder' => '{{session_id}}'],
                'context_window' => ['type' => 'number', 'required' => false, 'label' => 'Turnos anteriores que recordará (1–20)', 'default' => 10],
                'ttl_seconds' => ['type' => 'number', 'required' => false, 'label' => 'Caducidad por inactividad, en segundos (60–2592000)', 'default' => 86400]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->options($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        $options = $this->options($parameters);
        $session = $parameters['session_id'] ?? '';
        if ($session === '') {
            $session = $context['session_id'] ?? $context['_variables']['session_id'] ?? '';
            if ($session === '') {
                foreach ($context as $output) {
                    if (is_array($output) && ($output['variable'] ?? null) === 'user_message'
                        && is_string($output['session_id'] ?? null)) {
                        $session = $output['session_id'];
                        break;
                    }
                }
            }
        }
        if (!is_string($session) || trim($session) === '' || strlen($session) > 255
            || !mb_check_encoding($session, 'UTF-8') || preg_match('/[\x00-\x20\x7f]|{{[^}]*}}/', $session)) {
            throw new InvalidArgumentException('Simple Memory: falta un ID de sesión válido. Conecta Chat Trigger o indica session_id.');
        }
        $scope = $context['_memory_scope'] ?? null;
        $nodeId = $context['_memory_node_id'] ?? null;
        if (!is_string($scope) || $scope === '' || !is_string($nodeId) || $nodeId === '') {
            throw new InvalidArgumentException('Simple Memory: conecta este nodo al puerto Memory de un AI Agent.');
        }
        $scopeHash = hash('sha256', $scope . "\0" . $nodeId);
        return ['type' => 'chat_memory', 'provider' => 'simple_memory', 'scope_hash' => $scopeHash,
            'session_hash' => hash('sha256', $scopeHash . "\0" . $session)] + $options;
    }

    private function options(array $parameters): array
    {
        $result = [];
        foreach (['context_window' => [10, 1, 20], 'ttl_seconds' => [86400, 60, 2592000]] as $key => [$default, $min, $max]) {
            $value = $parameters[$key] ?? $default;
            if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
                throw new InvalidArgumentException('Simple Memory: ventana o caducidad fuera del rango permitido.');
            }
            $result[$key] = (int)$value;
        }
        if (array_key_exists('session_id', $parameters) && !is_string($parameters['session_id'])) {
            throw new InvalidArgumentException('Simple Memory: session_id debe ser texto.');
        }
        return $result;
    }
}
