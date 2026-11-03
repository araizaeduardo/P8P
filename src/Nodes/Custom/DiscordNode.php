<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class DiscordNode implements NodeInterface
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
            'type' => 'discord', 'name' => 'Discord', 'category' => 'Community',
            'description' => 'Envía mensajes y embeds con un bot o webhook y añade reacciones.',
            'icon' => 'MessageCircle', 'is_start' => false,
            'help_text' => 'Credencial generic_bearer: API Key con JSON {"bot_token":"..."} o {"webhook_url":"..."}. Nunca guardes secretos en parámetros. Bot: VIEW_CHANNEL, SEND_MESSAGES, EMBED_LINKS; reacciones: READ_MESSAGE_HISTORY y ADD_REACTIONS. Mentions desactivadas salvo IDs explícitos; no permite everyone/here. Gateway completo requiere un proceso residente; el polling de mensajes creados disponible por separado no cubre ediciones ni borrados. Salida: data.id y data.channel_id. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial de Discord'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'send_message', 'options' => [
                    ['value' => 'send_message', 'label' => 'Enviar mensaje (bot)'],
                    ['value' => 'execute_webhook', 'label' => 'Ejecutar webhook'],
                    ['value' => 'add_reaction', 'label' => 'Añadir reacción (bot)']
                ]],
                'channel_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de canal (bot)'],
                'content' => ['type' => 'textarea', 'required' => false, 'label' => 'Contenido (máximo 2000 caracteres)'],
                'embeds' => ['type' => 'textarea', 'required' => false, 'label' => 'Embeds (array JSON, hasta 10 / 6000 caracteres)', 'placeholder' => '[{"title":"Aviso","description":"Texto","color":3447003}]'],
                'allowed_mentions' => ['type' => 'textarea', 'required' => false, 'label' => 'Mentions permitidas (JSON; IDs explícitos users/roles)', 'placeholder' => '{"users":["123456789012345678"],"roles":[]}'],
                'thread_id' => ['type' => 'string', 'required' => false, 'label' => 'ID del hilo existente (webhook; obligatorio para foro/media)'],
                'message_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de mensaje (reacción)'],
                'emoji' => ['type' => 'string', 'required' => false, 'label' => 'Emoji Unicode o nombre:id (sin codificar)']
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepare($parameters);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine ya resuelve expresiones; una segunda evaluación alteraría texto literal.
        [$operation, $payload] = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted(trim($parameters['credential_id']));
        } catch (\Throwable $e) {
            throw new RuntimeException('Discord: no se pudo recuperar o descifrar la credencial.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Discord: credencial inexistente.');
        }
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException('Discord: usa una credencial generic_bearer con configuración JSON en API Key.');
        }
        $config = $this->objectValue($credential['data']['api_key']);
        $headers = ['Accept' => 'application/json'];
        $options = ['headers' => $headers, 'timeout' => 30, 'connect_timeout' => 10,
            'verify' => true, 'allow_redirects' => false, 'http_errors' => false];
        $method = 'POST';
        if ($operation === 'execute_webhook') {
            $url = $config['webhook_url'] ?? null;
            // Solo el origen oficial y una ruta canónica: sin query, usuario, puerto ni fragmento.
            if (!is_string($url) || preg_match('~^https://discord\.com/api(?:/v10)?/webhooks/([1-9][0-9]{16,19})/[A-Za-z0-9_-]+$~D', $url) !== 1) {
                throw new InvalidArgumentException('Discord: la credencial requiere una URL HTTPS oficial de webhook válida.');
            }
            $options['query'] = ['wait' => 'true'];
            if (($parameters['thread_id'] ?? '') !== '') {
                $options['query']['thread_id'] = $parameters['thread_id'];
            }
        } else {
            $token = $config['bot_token'] ?? null;
            if (!is_string($token) || preg_match('/^[A-Za-z0-9._-]+$/D', $token) !== 1) {
                throw new InvalidArgumentException('Discord: la credencial requiere bot_token sin prefijo Bot.');
            }
            $options['headers']['Authorization'] = 'Bot ' . $token;
            $url = 'https://discord.com/api/v10/channels/' . $parameters['channel_id'] . '/messages';
            if ($operation === 'add_reaction') {
                $url .= '/' . $parameters['message_id'] . '/reactions/' . rawurlencode($parameters['emoji']) . '/@me';
                $method = 'PUT';
            }
        }
        if ($operation !== 'add_reaction') {
            $options['json'] = $payload;
        }
        try {
            $response = ($this->client ?? new Client())->request($method, $url, $options);
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
        } catch (\Throwable $e) {
            throw new RuntimeException('Discord: fallo de transporte; el resultado de la escritura es incierto. Comprueba Discord antes de reintentar.');
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'autenticación rechazada', 403 => 'permisos insuficientes en el canal o recurso',
                404 => 'recurso inexistente o inaccesible', 429 => 'cuota alcanzada; espera antes de reintentar',
                default => 'solicitud rechazada; verifica el resultado antes de reintentar'
            };
            throw new RuntimeException("Discord: {$reason} (HTTP {$status}).");
        }
        if ($operation === 'add_reaction' && $status === 204 && $raw === '') {
            return ['success' => true, 'status_code' => $status, 'data' => [
                'channel_id' => $parameters['channel_id'], 'message_id' => $parameters['message_id'], 'reacted' => true
            ]];
        }
        $body = json_decode($raw, true);
        if ($operation === 'add_reaction' || $status !== 200 || !is_array($body)
            || !$this->snowflake($body['id'] ?? null) || !$this->snowflake($body['channel_id'] ?? null)
            || array_key_exists('code', $body) || array_key_exists('errors', $body)) {
            throw new RuntimeException('Discord: respuesta inválida o error funcional; verifica el resultado antes de reintentar.');
        }
        // No propagar cuerpos arbitrarios, URLs de webhook, tokens o campos reflejados.
        return ['success' => true, 'status_code' => $status, 'data' => [
            'id' => $body['id'], 'channel_id' => $body['channel_id']
        ]];
    }

    private function prepare(array $p): array
    {
        if (!is_string($p['credential_id'] ?? null) || trim($p['credential_id']) === '') {
            throw new InvalidArgumentException('Discord: selecciona una credencial.');
        }
        $operation = $p['operation'] ?? 'send_message';
        if (!in_array($operation, ['send_message', 'execute_webhook', 'add_reaction'], true)) {
            throw new InvalidArgumentException('Discord: operación inválida.');
        }
        if ($operation !== 'execute_webhook' && !$this->snowflake($p['channel_id'] ?? null)) {
            throw new InvalidArgumentException('Discord: channel_id debe ser un snowflake textual válido.');
        }
        if ($operation === 'add_reaction') {
            if (!$this->snowflake($p['message_id'] ?? null) || !$this->text($p['emoji'] ?? null, 100, 1)
                || preg_match('/[\x00-\x20\x7f\/<>%?#]/u', $p['emoji']) === 1
                || (str_contains($p['emoji'], ':') && preg_match('/^[A-Za-z0-9_]{2,32}:[1-9][0-9]{16,19}$/D', $p['emoji']) !== 1)
                || (!str_contains($p['emoji'], ':') && preg_match('/^[\x00-\x7f]+$/D', $p['emoji']) === 1)) {
                throw new InvalidArgumentException('Discord: message_id o emoji inválido (Unicode o nombre:id sin codificar).');
            }
            return [$operation, []];
        }
        if (isset($p['thread_id']) && $p['thread_id'] !== '' && !$this->snowflake($p['thread_id'])) {
            throw new InvalidArgumentException('Discord: thread_id inválido.');
        }
        $content = $p['content'] ?? '';
        if (!$this->text($content, 2000)) {
            throw new InvalidArgumentException('Discord: content debe ser texto UTF-8 de hasta 2000 caracteres.');
        }
        $embeds = $this->jsonValue($p['embeds'] ?? []);
        if (!is_array($embeds) || !array_is_list($embeds) || count($embeds) > 10) {
            throw new InvalidArgumentException('Discord: embeds debe ser un array de hasta 10 objetos.');
        }
        $total = 0;
        foreach ($embeds as &$embed) {
            $embed = $this->objectValue($embed);
            $this->keys($embed, ['title', 'description', 'url', 'color', 'fields', 'footer', 'author', 'image', 'thumbnail']);
            foreach (['title' => 256, 'description' => 4096] as $key => $limit) {
                if (array_key_exists($key, $embed)) {
                    $total += $this->limitedText($embed[$key], $limit);
                }
            }
            if (array_key_exists('color', $embed) && (!is_int($embed['color']) || $embed['color'] < 0 || $embed['color'] > 16777215)) {
                throw new InvalidArgumentException('Discord: color debe ser entero de 0 a 16777215.');
            }
            if (isset($embed['url'])) {
                $this->url($embed['url']);
            }
            foreach (['footer', 'author', 'image', 'thumbnail'] as $part) {
                if (!array_key_exists($part, $embed)) {
                    continue;
                }
                $value = $this->objectValue($embed[$part]);
                $allowed = match ($part) {
                    'footer' => ['text', 'icon_url'], 'author' => ['name', 'url', 'icon_url'], default => ['url']
                };
                $this->keys($value, $allowed);
                $required = match ($part) { 'footer' => 'text', 'author' => 'name', default => 'url' };
                if (!array_key_exists($required, $value)) {
                    throw new InvalidArgumentException('Discord: embed incompleto.');
                }
                foreach ($value as $key => $item) {
                    if (in_array($key, ['text', 'name'], true)) {
                        $total += $this->limitedText($item, $part === 'footer' ? 2048 : 256, 1);
                    } else {
                        $this->url($item);
                    }
                }
                $embed[$part] = $value;
            }
            if (array_key_exists('fields', $embed)) {
                $fields = $this->jsonValue($embed['fields']);
                if (!is_array($fields) || !array_is_list($fields) || count($fields) > 25) {
                    throw new InvalidArgumentException('Discord: máximo 25 campos por embed.');
                }
                foreach ($fields as &$field) {
                    $field = $this->objectValue($field);
                    $this->keys($field, ['name', 'value', 'inline']);
                    $total += $this->limitedText($field['name'] ?? null, 256, 1);
                    $total += $this->limitedText($field['value'] ?? null, 1024, 1);
                    if (array_key_exists('inline', $field) && !is_bool($field['inline'])) {
                        throw new InvalidArgumentException('Discord: inline debe ser booleano.');
                    }
                }
                unset($field);
                $embed['fields'] = $fields;
            }
            if ($embed === []) {
                throw new InvalidArgumentException('Discord: embed vacío.');
            }
        }
        unset($embed);
        if ($total > 6000 || ($content === '' && $embeds === [])) {
            throw new InvalidArgumentException('Discord: indica contenido o embeds con máximo 6000 caracteres combinados.');
        }
        $mentions = $this->objectValue($p['allowed_mentions'] ?? new \stdClass());
        $this->keys($mentions, ['parse', 'users', 'roles', 'replied_user']);
        if ((array_key_exists('parse', $mentions) && $mentions['parse'] !== [])
            || (array_key_exists('replied_user', $mentions) && $mentions['replied_user'] !== false)) {
            throw new InvalidArgumentException('Discord: mentions solo admite IDs explícitos; parse debe ser [] y replied_user false.');
        }
        foreach (['users', 'roles'] as $kind) {
            if (!array_key_exists($kind, $mentions)) {
                continue;
            }
            if (!is_array($mentions[$kind]) || !array_is_list($mentions[$kind]) || count($mentions[$kind]) > 100) {
                throw new InvalidArgumentException('Discord: mentions admite hasta 100 IDs por lista.');
            }
            foreach ($mentions[$kind] as $id) {
                if (!$this->snowflake($id)) {
                    throw new InvalidArgumentException('Discord: ID de mention inválido.');
                }
            }
        }
        $mentions['parse'] = [];
        $mentions['replied_user'] = false;
        return [$operation, ['content' => $content, 'embeds' => $embeds, 'allowed_mentions' => $mentions]];
    }

    private function snowflake(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{16,19}$/D', $value) === 1;
    }

    private function text(mixed $value, int $max, int $min = 0): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') >= $min && mb_strlen($value, 'UTF-8') <= $max;
    }

    private function limitedText(mixed $value, int $max, int $min = 0): int
    {
        if (!$this->text($value, $max, $min)) {
            throw new InvalidArgumentException('Discord: texto del embed inválido o demasiado largo.');
        }
        return mb_strlen($value, 'UTF-8');
    }

    private function jsonValue(mixed $value): mixed
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                return [];
            }
            try {
                return json_decode($value, false, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Discord: JSON inválido.');
            }
        }
        return $value;
    }

    private function objectValue(mixed $value): array
    {
        $value = $this->jsonValue($value);
        if ($value instanceof \stdClass) {
            return (array) $value;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Discord: se requiere un objeto JSON.');
        }
        return $value;
    }

    private function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new InvalidArgumentException('Discord: campo JSON no admitido.');
        }
    }

    private function url(mixed $url): void
    {
        if (!$this->text($url, 2048, 1) || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) {
            throw new InvalidArgumentException('Discord: URL de embed inválida.');
        }
    }
}
