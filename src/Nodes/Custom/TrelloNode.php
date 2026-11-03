<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class TrelloNode implements NodeInterface
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
            'type' => 'trello', 'name' => 'Trello', 'category' => 'Community', 'icon' => 'Columns3', 'is_start' => false,
            'description' => 'Crea tarjetas, mueve tarjetas entre listas y añade comentarios.',
            'help_text' => 'Credencial generic_bearer: guarda JSON {"api_key":"0123456789abcdef0123456789abcdef","token":"token_usuario_ficticio"} en API Key. Usa una key de aplicación Trello y token de usuario autorizado con scope read,write y acceso al tablero. La caducidad depende de expiration al autorizar (1hour/1day/30days/never); revocación/caducidad exige reemplazo manual. No es token OAuth2 Bearer; se usa el encabezado OAuth documentado sin secretos en URL. create_card exige list_id (24 hex) y name; desc opcional permite vacío, label_ids lista JSON/array de IDs opcional (máximo 100), sin fechas/adjuntos. move_card exige card_id y list_id; solo cambia idList, sin borrar nombre/descripción; movimiento entre listas del mismo tablero únicamente. add_comment exige card_id y text (hasta 16384 caracteres). Expresiones {{node_id.data.id}} evaluadas por Engine. Salida success/status_code/data: id/name/idList/idBoard/url para tarjeta; id/type/date/text para comentario. No hay listados ni paginación. Sin reintentos ni idempotencia oficial para estas acciones: un resultado incierto requiere verificar Trello antes de repetir. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Trello JSON en API Key'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'create_card', 'options' => [['value' => 'create_card', 'label' => 'Crear tarjeta'], ['value' => 'move_card', 'label' => 'Mover tarjeta'], ['value' => 'add_comment', 'label' => 'Añadir comentario']]],
                'list_id' => ['type' => 'string', 'required' => false, 'label' => 'ID lista destino (crear/mover)'],
                'card_id' => ['type' => 'string', 'required' => false, 'label' => 'ID tarjeta (mover/comentar)'],
                'name' => ['type' => 'string', 'required' => false, 'label' => 'Nombre de tarjeta (crear)'],
                'desc' => ['type' => 'textarea', 'required' => false, 'label' => 'Descripción opcional (crear)'],
                'label_ids' => ['type' => 'textarea', 'required' => false, 'label' => 'IDs de etiquetas JSON opcional (crear)', 'placeholder' => '["5abbe4b7ddc1b351ef961414"]'],
                'text' => ['type' => 'textarea', 'required' => false, 'label' => 'Comentario (comentar)']
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
        // Engine aplica ExpressionEvaluator antes de invocar el conector.
        $p = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($p['credential_id']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Trello: no se pudo recuperar o descifrar la credencial; revisa almacenamiento y gestor.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Trello: la credencial seleccionada no existe.');
        }
        try {
            if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
                throw new InvalidArgumentException();
            }
            $config = json_decode($credential['data']['api_key'], false, 16, JSON_THROW_ON_ERROR);
            if (!$config instanceof \stdClass || !is_string($config->api_key ?? null) || preg_match('/^[a-fA-F0-9]{32}$/D', $config->api_key) !== 1
                || !is_string($config->token ?? null) || preg_match('/^[A-Za-z0-9_-]{8,1024}$/D', $config->token) !== 1) {
                throw new InvalidArgumentException();
            }
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Trello: selecciona generic_bearer con JSON api_key (32 hex) y token de usuario en API Key.');
        }
        try {
            $response = ($this->client ?? new Client())->request($p['method'], 'https://api.trello.com/1' . $p['path'], [
                'headers' => ['Authorization' => 'OAuth oauth_consumer_key="' . $config->api_key . '", oauth_token="' . $config->token . '"', 'Accept' => 'application/json'],
                'json' => $p['payload'], 'timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false
            ]);
            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            throw new RuntimeException('Trello: fallo de transporte o lectura. La acción puede haber ocurrido; verifica Trello antes de repetir. No se reintentó.');
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'autenticación rechazada; key/token inválido, caducado o revocado',
                403 => 'permisos insuficientes; revisa scope write y acceso al tablero',
                404 => 'tarjeta o lista inexistente o inaccesible',
                429 => 'límite de solicitudes alcanzado',
                400 => 'datos rechazados; revisa IDs, lista, etiquetas y contenido',
                default => $status >= 500 ? 'error del servicio remoto' : 'solicitud rechazada'
            };
            throw new RuntimeException("Trello: {$reason} (HTTP {$status}). No se reintentó; verifica la acción antes de repetir.", $status);
        }
        try {
            if (!is_array($body) || isset($body['error']) || isset($body['message'])) {
                throw new InvalidArgumentException();
            }
            $data = ['id' => $this->id($body['id'] ?? null)];
            if ($p['operation'] === 'add_comment') {
                if (($body['type'] ?? null) !== 'commentCard' || !is_array($body['data'] ?? null)) {
                    throw new InvalidArgumentException();
                }
                $data['type'] = 'commentCard';
                $data['text'] = $this->text($body['data'], 'text', true, 16384);
                if (array_key_exists('date', $body)) {
                    $data['date'] = $this->text($body, 'date', true, 64);
                }
            } else {
                $data['name'] = $this->text($body, 'name', true, 16384);
                $data['idList'] = $this->id($body['idList'] ?? null);
                if (isset($body['idBoard'])) {
                    $data['idBoard'] = $this->id($body['idBoard']);
                }
                if (isset($body['url'])) {
                    $url = parse_url($body['url']);
                    if (!$url || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== 'trello.com' || isset($url['user']) || isset($url['pass'])) {
                        throw new InvalidArgumentException();
                    }
                    $data['url'] = $body['url'];
                }
            }
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (str_contains($encoded, $config->api_key) || str_contains($encoded, $config->token)) {
                throw new InvalidArgumentException();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Trello: respuesta inválida o incompleta. La acción puede haber ocurrido; verifica Trello antes de repetir.', $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        $credentialId = $this->text($p, 'credential_id', true, 255);
        $operation = $p['operation'] ?? 'create_card';
        if (!in_array($operation, ['create_card', 'move_card', 'add_comment'], true)) {
            throw new InvalidArgumentException('Trello: operación inválida.');
        }
        $method = 'POST';
        $path = '/cards';
        if ($operation === 'create_card') {
            $payload = ['idList' => $this->id($p['list_id'] ?? null), 'name' => $this->text($p, 'name', true, 16384)];
            if (array_key_exists('desc', $p)) {
                $payload['desc'] = $this->text($p, 'desc', false, 16384);
            }
            if (array_key_exists('label_ids', $p)) {
                $ids = $p['label_ids'];
                if (is_string($ids)) {
                    try {
                        $ids = json_decode($ids, false, 32, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {
                        throw new InvalidArgumentException('Trello: label_ids debe ser lista JSON de IDs.');
                    }
                }
                if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) {
                    throw new InvalidArgumentException('Trello: label_ids debe ser lista de máximo 100 IDs.');
                }
                $payload['idLabels'] = array_map(fn ($id) => $this->id($id), $ids);
            }
        } else {
            $path .= '/' . $this->id($p['card_id'] ?? null);
            if ($operation === 'move_card') {
                $method = 'PUT';
                $payload = ['idList' => $this->id($p['list_id'] ?? null)];
            } else {
                $path .= '/actions/comments';
                $payload = ['text' => $this->text($p, 'text', true, 16384)];
            }
        }
        return ['credential_id' => $credentialId, 'operation' => $operation, 'method' => $method, 'path' => $path, 'payload' => $payload];
    }

    private function text(array $p, string $key, bool $required, int $max): string
    {
        $value = $p[$key] ?? null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max || str_contains($value, "\0")
            || ($required && trim($value) === '') || preg_match('/{{\s*[A-Za-z0-9_.]+\s*}}/', $value)) {
            throw new InvalidArgumentException("Trello: campo {$key} inválido, ausente o expresión sin resolver.");
        }
        return $value;
    }

    private function id(mixed $id): string
    {
        if (!is_string($id) || preg_match('/^[a-fA-F0-9]{24}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Trello: usa IDs completos de 24 caracteres hexadecimales.');
        }
        return $id;
    }
}
