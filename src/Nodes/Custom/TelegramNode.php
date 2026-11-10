<?php

namespace P8P\Nodes\Custom;

use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;

class TelegramNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'telegram_bot',
            'name' => 'Telegram Bot',
            'category' => 'Community',
            'description' => 'Envía mensajes a un chat o canal de Telegram mediante credenciales encriptadas',
            'icon' => 'Send',
            'is_start' => false,
            'help_text' => 'Selecciona tu credencial guardada de Telegram (que contiene el Bot Token) y configura el Chat ID y Mensaje.',
            'parameters' => [
                'credential_id' => [
                    'type' => 'credential',
                    'required' => true,
                    'label' => 'Credencial de Telegram'
                ],
                'chat_id' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Chat ID (ej. {{chat_id}})'
                ],
                'message' => [
                    'type' => 'textarea',
                    'required' => true,
                    'label' => 'Mensaje (ej. {{message}})'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return !empty($parameters['credential_id']);
    }

    public function execute(array $parameters, array $context): array
    {
        $credentialId = $parameters['credential_id'] ?? null;
        $chatId       = $parameters['chat_id'] ?? $this->findInContext('chat_id', $context) ?? '';
        $message      = $parameters['message'] ?? $this->findInContext('message', $context) ?? '';

        if (empty($credentialId)) {
            return [
                'success' => false,
                'error' => 'No se ha seleccionado ninguna credencial de Telegram.'
            ];
        }

        // Obtener la credencial desde MariaDB mediante CredentialManager
        $botToken = '';
        try {
            $cm = new CredentialManager();
            $cred = $cm->getCredentialDecrypted($credentialId);

            if ($cred && !empty($cred['data'])) {
                $data = $cred['data'];
                // Extraer el token según las posibles claves guardadas en el objeto 'data'
                $botToken = $data['bot_token']
                    ?? $data['api_key']
                    ?? $data['token']
                    ?? $data['value']
                    ?? '';
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Error al desencriptar la credencial: ' . $e->getMessage()
            ];
        }

        if (empty($botToken)) {
            return [
                'success' => false,
                'error' => 'La credencial seleccionada no contiene un Bot Token válido.'
            ];
        }

        if (empty($chatId) || empty($message)) {
            return [
                'success' => false,
                'error' => 'Faltan parámetros requeridos de Telegram (chat_id o message).'
            ];
        }

        // Sanitización de espacios en blanco en credenciales e identificadores
        $botToken = trim(preg_replace('/\s+/', '', $botToken));
        $chatId   = trim(preg_replace('/\s+/', '', $chatId));

        // Control de longitud máxima permitido por la API de Telegram (4096 caracteres)
        if (mb_strlen($message, 'UTF-8') > 4000) {
            $message = mb_substr($message, 0, 3980, 'UTF-8') . "\n\n...[Mensaje cortado por exceder el límite de caracteres]";
        }

        // Petición cURL a la API de Telegram
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        // Primer intento enviado sin parse_mode HTML estricto para evitar errores de sintaxis en respuestas largas de IA
        $postFields = [
            'chat_id' => $chatId,
            'text' => $message
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        // El token del bot viaja en la URL: la verificación TLS no puede estar desactivada.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($response, true);

        return [
            'success' => $httpCode === 200 && ($json['ok'] ?? false),
            'status_code' => $httpCode,
            'response' => $json
        ];
    }

    /**
     * Busca variables en el historial del flujo de contexto
     */
    private function findInContext(string $key, array $context): ?string
    {
        if (isset($context[$key])) {
            return (string)$context[$key];
        }

        foreach ($context as $nodeOutput) {
            if (is_array($nodeOutput) && isset($nodeOutput['variable']) && $nodeOutput['variable'] === $key) {
                return (string)$nodeOutput['value'];
            }
        }

        return null;
    }
}
