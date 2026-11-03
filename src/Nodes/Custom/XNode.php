<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class XNode implements NodeInterface
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
            'type' => 'x_twitter', 'name' => 'X (Twitter)', 'category' => 'Community',
            'description' => 'Publica texto, busca publicaciones recientes y consulta detalles, métricas o menciones.',
            'icon' => 'MessageCircle', 'is_start' => false,
            'help_text' => 'Credencial generic_bearer con data.api_key = access token OAuth 2.0 sin Bearer. Publicar requiere token de usuario con tweet.write, tweet.read y users.read; lecturas OAuth requieren tweet.read/users.read. Bearer de aplicación solo para lecturas permitidas. No admite firma OAuth 1.0a ni API key/secret. Métricas de propietario: token del autor y publicaciones de hasta 30 días. Acceso, cuotas, créditos y operadores dependen del producto contratado en X; un 403/402 no garantiza disponibilidad. Búsqueda reciente: últimos 7 días, query de hasta 512 caracteres y operadores oficiales como #tema, @usuario, from:, lang: y -is:retweet. Cada lectura paginada recupera solo una página (10–100 resultados), devuelve meta.next_token/newest_id y acepta page_token/since_id. Las operaciones de menciones y hashtag de este nodo son consultas de una página; la programación y el cursor de eventos se configuran por separado. Token caducado: reemplazo manual. Sin reintentos; si publicar falla por transporte, comprueba X antes de repetir. Salida data y meta, accesibles con {{node_id.data.id}}. El motor resuelve expresiones antes de ejecutar. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial X (Bearer Token / API Key)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'search_recent', 'options' => [
                    ['value' => 'publish_text', 'label' => 'Publicar texto'],
                    ['value' => 'search_recent', 'label' => 'Buscar (últimos 7 días / hashtag)'],
                    ['value' => 'get_post', 'label' => 'Detalles y métricas'],
                    ['value' => 'list_mentions', 'label' => 'Consultar menciones (una página)']
                ]],
                'text' => ['type' => 'textarea', 'required' => false, 'label' => 'Texto (publicar; longitud admitida por X)'],
                'query' => ['type' => 'textarea', 'required' => false, 'label' => 'Consulta oficial (buscar)', 'placeholder' => '#p8p lang:es -is:retweet'],
                'post_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de publicación (detalles)'],
                'user_id' => ['type' => 'string', 'required' => false, 'label' => 'ID numérico de usuario (menciones)'],
                'metrics' => ['type' => 'select', 'required' => false, 'label' => 'Métricas de lectura', 'default' => 'public', 'options' => [
                    ['value' => 'public', 'label' => 'Públicas'], ['value' => 'owner', 'label' => 'Públicas y del propietario (requiere autorización)']
                ]],
                'max_results' => ['type' => 'number', 'required' => false, 'label' => 'Resultados por página (10–100)', 'default' => 10],
                'page_token' => ['type' => 'string', 'required' => false, 'label' => 'Cursor de página (meta.next_token)'],
                'filters' => ['type' => 'textarea', 'required' => false, 'label' => 'Filtros JSON: since_id, until_id, start_time, end_time; búsqueda: sort_order', 'placeholder' => '{"since_id":"1234567890"}']
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
        // Engine ya aplica ExpressionEvaluator. No reinterpretar el texto resultante.
        $request = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($request['credential_id']);
        } catch (\Throwable $e) {
            throw new RuntimeException('X: no se pudo recuperar o descifrar la credencial; revisa el gestor y su almacenamiento.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('X: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || preg_match('/^[A-Za-z0-9._~+\/%-]+=*$/D', $token) !== 1) {
            throw new InvalidArgumentException('X: requiere generic_bearer con data.api_key = token OAuth 2.0 sin prefijo Bearer; no una API key compuesta ni OAuth 1.0a.');
        }
        $options = $request['options'] + [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false, 'stream' => true
        ];
        try {
            $response = ($this->client ?? new Client())->request($request['method'], 'https://api.x.com/2/' . $request['path'], $options);
            $status = $response->getStatusCode();
            $stream = $response->getBody();
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= 2097152) {
                $chunk = $stream->read(min(8192, 2097153 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $raw .= $chunk;
            }
            $stream->close();
            if (strlen($raw) > 2097152) {
                throw new RuntimeException();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('X: fallo de transporte; no se reintentó. '
                . ($request['method'] === 'POST' ? 'Resultado incierto: la publicación puede haberse creado; comprueba X antes de repetir.' : 'No se pudo completar la lectura.'));
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'token inválido, caducado o revocado; reemplázalo manualmente',
                402 => 'créditos o acceso contratado insuficientes; revisa facturación y producto',
                403 => 'operación no disponible para este acceso o permisos insuficientes; revisa producto, scopes, usuario propietario y restricciones de métricas',
                404 => 'recurso inexistente o inaccesible',
                429 => 'cuota o límite de solicitudes alcanzado; revisa los límites del endpoint y espera antes de ejecutar de nuevo',
                default => 'solicitud rechazada por X; revisa parámetros y acceso; no se reintentó'
            };
            throw new RuntimeException("X: {$reason} (HTTP {$status}).", $status);
        }
        // Ninguna de estas operaciones documenta 204: no confundirlo con creación confirmada.
        try {
            $shape = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('X: respuesta JSON inválida. No se reintentó; comprueba la publicación si era una escritura.', $status);
        }
        if (!$shape instanceof \stdClass || !is_array($body) || isset($body['errors']) || isset($body['error'])
            || isset($body['title']) || isset($body['detail'])) {
            throw new RuntimeException('X: la respuesta contiene errores funcionales o está incompleta; no se reintentó.', $status);
        }
        $paginated = $request['paginated'];
        $data = $body['data'] ?? ($paginated ? [] : null);
        if (!is_array($data) || ($paginated && (!array_is_list($data) || (isset($shape->data) && !is_array($shape->data))))
            || (!$paginated && !($shape->data ?? null) instanceof \stdClass)) {
            throw new RuntimeException('X: estructura de datos inválida; comprueba la publicación si era una escritura.', $status);
        }
        $posts = $paginated ? $data : [$data];
        if (count($posts) > $request['limit']) {
            throw new RuntimeException('X: respuesta supera el límite solicitado.', $status);
        }
        foreach ($posts as $post) {
            if (!is_array($post) || !is_string($post['id'] ?? null)
                || preg_match('/^[0-9]{1,19}$/D', $post['id']) !== 1 || !is_string($post['text'] ?? null)) {
                throw new RuntimeException('X: respuesta de publicación inválida o incompleta.', $status);
            }
        }
        $meta = $body['meta'] ?? [];
        if (!is_array($meta) || ($paginated && (!is_int($meta['result_count'] ?? null) || $meta['result_count'] !== count($posts)))) {
            throw new RuntimeException('X: metadatos de paginación inválidos.', $status);
        }
        $safeMeta = [];
        foreach (['next_token', 'previous_token', 'newest_id', 'oldest_id'] as $field) {
            if (array_key_exists($field, $meta)) {
                if (!is_string($meta[$field]) || preg_match('/^[A-Za-z0-9_-]{1,4096}$/D', $meta[$field]) !== 1) {
                    throw new RuntimeException('X: cursor o identificador de respuesta inválido.', $status);
                }
                $safeMeta[$field] = $meta[$field];
            }
        }
        if ($paginated) {
            $safeMeta['result_count'] = count($posts);
        }
        // Evitar que un proveedor/proxy refleje el Bearer en cualquier dato o campo desconocido.
        $serialized = json_encode([$data, $safeMeta], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (str_contains($serialized, $token) || str_contains($serialized, rawurlencode($token))
            || str_contains($serialized, urlencode($token))) {
            throw new RuntimeException('X: respuesta descartada porque contiene datos sensibles.', $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data, 'meta' => $safeMeta];
    }

    private function prepare(array $parameters): array
    {
        $credentialId = $this->text($parameters['credential_id'] ?? null, 'credential_id');
        $operation = $parameters['operation'] ?? 'search_recent';
        if (!is_string($operation) || !in_array($operation, ['publish_text', 'search_recent', 'get_post', 'list_mentions'], true)) {
            throw new InvalidArgumentException('X: operación inválida.');
        }
        if ($operation === 'publish_text') {
            $text = $this->text($parameters['text'] ?? null, 'text');
            // X aplica su conteo ponderado, URLs y permisos de textos largos; nunca truncar silenciosamente.
            if (mb_strlen($text) > 25000) {
                throw new InvalidArgumentException('X: texto supera el límite local de 25000 caracteres; X valida su longitud ponderada y el acceso contratado.');
            }
            return ['credential_id' => $credentialId, 'method' => 'POST', 'path' => 'tweets', 'options' => ['json' => ['text' => $text]], 'paginated' => false, 'limit' => 1];
        }
        $metrics = $parameters['metrics'] ?? 'public';
        if (!in_array($metrics, ['public', 'owner'], true)) {
            throw new InvalidArgumentException('X: selección de métricas inválida.');
        }
        $query = ['post.fields' => 'id,text,author_id,created_at,public_metrics'
            . ($metrics === 'owner' ? ',non_public_metrics,organic_metrics,promoted_metrics' : '')];
        if ($operation === 'get_post') {
            $path = 'tweets/' . $this->id($parameters['post_id'] ?? null, 'post_id');
            return ['credential_id' => $credentialId, 'method' => 'GET', 'path' => $path, 'options' => ['query' => $query], 'paginated' => false, 'limit' => 1];
        }
        $limit = $parameters['max_results'] ?? 10;
        if (is_string($limit) && preg_match('/^[0-9]{1,3}$/D', $limit) === 1) {
            $limit = (int)$limit;
        }
        if (!is_int($limit) || $limit < 10 || $limit > 100) {
            throw new InvalidArgumentException('X: max_results debe ser un entero entre 10 y 100.');
        }
        $query['max_results'] = $limit;
        if ($operation === 'search_recent') {
            $path = 'tweets/search/recent';
            $query['query'] = $this->text($parameters['query'] ?? null, 'query');
            if (mb_strlen($query['query']) > 512) {
                throw new InvalidArgumentException('X: query supera 512 caracteres; este conector usa el límite común de búsqueda reciente.');
            }
        } else {
            $path = 'users/' . $this->id($parameters['user_id'] ?? null, 'user_id') . '/mentions';
        }
        if (array_key_exists('page_token', $parameters) && $parameters['page_token'] !== '') {
            $cursor = $this->text($parameters['page_token'], 'page_token');
            if (preg_match('/^[A-Za-z0-9_-]{1,4096}$/D', $cursor) !== 1) {
                throw new InvalidArgumentException('X: cursor inválido; usa meta.next_token, nunca una URL.');
            }
            $query[$operation === 'search_recent' ? 'next_token' : 'pagination_token'] = $cursor;
        }
        $filters = array_key_exists('filters', $parameters) ? $parameters['filters'] : [];
        if ($filters === '') {
            $filters = [];
        } elseif (is_string($filters)) {
            try {
                $filters = json_decode($filters, false, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('X: filters debe ser un objeto JSON válido.');
            }
            if (!$filters instanceof \stdClass) {
                throw new InvalidArgumentException('X: filters debe ser un objeto JSON.');
            }
        }
        if ($filters instanceof \stdClass) {
            $filters = get_object_vars($filters);
        }
        if (!is_array($filters) || ($filters !== [] && array_is_list($filters))) {
            throw new InvalidArgumentException('X: filters debe ser un objeto o array asociativo.');
        }
        foreach ($filters as $key => $value) {
            if (!in_array($key, ['since_id', 'until_id', 'start_time', 'end_time', 'sort_order'], true)
                || ($key === 'sort_order' && $operation !== 'search_recent')) {
                throw new InvalidArgumentException('X: filtro no admitido para la operación.');
            }
            if (in_array($key, ['since_id', 'until_id'], true)) {
                $query[$key] = $this->id($value, $key);
            } elseif ($key === 'sort_order') {
                if (!in_array($value, ['recency', 'relevancy'], true)) {
                    throw new InvalidArgumentException('X: sort_order inválido.');
                }
                $query[$key] = $value;
            } else {
                $date = $this->text($value, $key);
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $date, new \DateTimeZone('UTC'));
                if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $date) {
                    throw new InvalidArgumentException('X: fechas de filtros deben usar YYYY-MM-DDTHH:MM:SSZ.');
                }
                if ($parsed->getTimestamp() > time() - 10
                    || ($operation === 'search_recent' && $parsed->getTimestamp() < time() - 604800)) {
                    throw new InvalidArgumentException('X: fecha fuera de la ventana permitida (búsqueda: últimos 7 días; al menos 10 segundos de antigüedad).');
                }
                $query[$key] = $date;
            }
        }
        if ((isset($query['start_time'], $query['since_id']) || isset($query['end_time'], $query['until_id'])) && $operation === 'search_recent') {
            throw new InvalidArgumentException('X: búsqueda no admite start_time con since_id ni end_time con until_id.');
        }
        if (isset($query['start_time'], $query['end_time']) && $query['start_time'] >= $query['end_time']) {
            throw new InvalidArgumentException('X: start_time debe ser anterior a end_time.');
        }
        if (isset($query['since_id'], $query['until_id'])
            && (strlen($query['since_id']) > strlen($query['until_id'])
                || (strlen($query['since_id']) === strlen($query['until_id']) && strcmp($query['since_id'], $query['until_id']) >= 0))) {
            throw new InvalidArgumentException('X: since_id debe ser menor que until_id.');
        }
        return ['credential_id' => $credentialId, 'method' => 'GET', 'path' => $path, 'options' => ['query' => $query], 'paginated' => true, 'limit' => $limit];
    }

    private function text(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new InvalidArgumentException("X: campo {$field} debe ser texto válido y no vacío.");
        }
        return $value;
    }

    private function id(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
            throw new InvalidArgumentException("X: {$field} debe ser un ID numérico de 1 a 19 dígitos en texto.");
        }
        return $value;
    }
}
