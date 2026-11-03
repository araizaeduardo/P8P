<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class YouTubeNode implements NodeInterface
{
    private const MAX_VIDEO_BYTES = 20971520;
    private const CHUNK_BYTES = 1048576;
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
            'type' => 'youtube', 'name' => 'YouTube', 'category' => 'Community',
            'description' => 'Sube videos pequeños y consulta canales, hilos y respuestas de comentarios.',
            'icon' => 'Video', 'is_start' => false,
            'help_text' => 'Credencial generic_bearer: data.api_key contiene access token OAuth 2.0 sin Bearer; no API key ni cuenta de servicio. Habilita YouTube Data API v3 en el proyecto. Subir requiere youtube.upload; consultas OAuth usan youtube.readonly (o youtube.force-ssl/youtube). Renueva manualmente el token al caducar. Video en Base64 canónico sin data URI: máximo local 20 MiB decodificados, formatos MP4/WebM/MOV/AVI/MPEG/Ogg; no rutas ni URLs. Base64 aumenta el tamaño ~33%; el hosting puede imponer límites menores de memoria/POST/tiempo y el motor registra parámetros. No incluyas contenido confidencial. Carga por sesión reanudable oficial y bloques de 1 MiB; sesión interna sin persistencia ni reintentos. Si falla, comprueba el canal antes de repetir. Privacidad private por defecto: public/unlisted requieren selección explícita; notificaciones a suscriptores desactivadas. La API valida el video y la categoría disponible para el canal. Consultas devuelven una sola página: usa data.nextPageToken como page_token. Hilos incluyen solo algunas respuestas; para todas pagina list_comments con parent_id del comentario principal, no del hilo. El polling de comentarios principales disponible por separado no recibe respuestas ni todos los cambios; push de YouTube solo notifica cambios de videos. Cuotas y acceso dependen del proyecto, scopes, canal y políticas de YouTube; consulta la consola del proyecto. Salidas data.id o data.items y pagination. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial OAuth (Bearer Token / API Key)'],
                'operation' => [
                    'type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'get_channel',
                    'options' => [
                        ['value' => 'get_channel', 'label' => 'Consultar canal'],
                        ['value' => 'list_comment_threads', 'label' => 'Listar hilos de un video'],
                        ['value' => 'list_comments', 'label' => 'Listar respuestas de un comentario'],
                        ['value' => 'upload_video', 'label' => 'Subir video (máximo 20 MiB)']
                    ]
                ],
                'channel_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de canal (vacío: canal propio)'],
                'video_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de video (listar hilos)'],
                'parent_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de comentario principal (listar respuestas)'],
                'max_results' => ['type' => 'number', 'required' => false, 'label' => 'Elementos por página (canales 1–50; comentarios 1–100)', 'default' => 20],
                'page_token' => ['type' => 'string', 'required' => false, 'label' => 'Cursor de página (nextPageToken)'],
                'order' => ['type' => 'select', 'required' => false, 'label' => 'Orden de hilos', 'default' => 'time', 'options' => [
                    ['value' => 'time', 'label' => 'Más recientes'], ['value' => 'relevance', 'label' => 'Relevancia']
                ]],
                'search_terms' => ['type' => 'string', 'required' => false, 'label' => 'Buscar texto en hilos (opcional)'],
                'video_base64' => ['type' => 'textarea', 'required' => false, 'label' => 'Video Base64 canónico (subir; máximo 20 MiB decodificados)'],
                'mime_type' => ['type' => 'select', 'required' => false, 'label' => 'Formato del video', 'default' => 'video/mp4', 'options' => [
                    ['value' => 'video/mp4', 'label' => 'MP4'], ['value' => 'video/webm', 'label' => 'WebM'],
                    ['value' => 'video/quicktime', 'label' => 'MOV'], ['value' => 'video/x-msvideo', 'label' => 'AVI'],
                    ['value' => 'video/mpeg', 'label' => 'MPEG'], ['value' => 'video/ogg', 'label' => 'Ogg']
                ]],
                'title' => ['type' => 'string', 'required' => false, 'label' => 'Título (subir; máximo 100 caracteres)'],
                'description' => ['type' => 'textarea', 'required' => false, 'label' => 'Descripción (subir; máximo 5000 bytes)'],
                'category_id' => ['type' => 'string', 'required' => false, 'label' => 'Categoría de video (ID válido)', 'default' => '22'],
                'tags' => ['type' => 'textarea', 'required' => false, 'label' => 'Etiquetas (array JSON o expresión que resuelva un array)', 'placeholder' => '["tutorial","p8p"]'],
                'privacy_status' => ['type' => 'select', 'required' => false, 'label' => 'Privacidad (subir)', 'default' => 'private', 'options' => [
                    ['value' => 'private', 'label' => 'Privado'], ['value' => 'unlisted', 'label' => 'No listado (explícito)'],
                    ['value' => 'public', 'label' => 'Público (explícito)']
                ]],
                'made_for_kids' => ['type' => 'select', 'required' => false, 'label' => 'Audiencia infantil declarada (subir)', 'default' => 'unspecified', 'options' => [
                    ['value' => 'unspecified', 'label' => 'No especificar; revisar configuración del canal'],
                    ['value' => 'true', 'label' => 'Sí, creado para niños'], ['value' => 'false', 'label' => 'No, no creado para niños']
                ]]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepareParameters($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine ya aplica ExpressionEvaluator; conservar el contenido resultante literalmente.
        $prepared = $this->prepareParameters($parameters);
        $token = $this->getToken($prepared['credential_id']);
        $client = $this->client ?? new Client();
        if ($prepared['operation'] === 'upload_video') {
            return $this->upload($client, $prepared, $token);
        }
        $response = $this->request($client, 'GET', 'https://www.googleapis.com/youtube/v3/' . $prepared['resource'],
            ['query' => $prepared['query']], $token, false);
        $data = $this->readJson($response, false);
        if ($response->getStatusCode() !== 200 || !is_array($data['items'] ?? null) || !array_is_list($data['items'])
            || count($data['items']) > $prepared['query']['maxResults']) {
            throw new RuntimeException('YouTube: respuesta de listado inválida o superior al límite solicitado.');
        }
        foreach (['nextPageToken', 'prevPageToken'] as $key) {
            if (isset($data[$key]) && (!is_string($data[$key]) || !$this->validCursor($data[$key]))) {
                throw new RuntimeException('YouTube: cursor de respuesta inválido.');
            }
        }
        $data = $this->sanitize($data, [$token]);
        return [
            'success' => true, 'status_code' => $response->getStatusCode(), 'data' => $data,
            'pagination' => ['next_page_token' => $data['nextPageToken'] ?? null,
                'has_more' => isset($data['nextPageToken']) && $data['nextPageToken'] !== '',
                'max_results' => $prepared['query']['maxResults'], 'returned_count' => count($data['items'])]
        ];
    }

    private function upload(ClientInterface $client, array $prepared, string $token): array
    {
        // Memoria acotada; no se leen ni escriben archivos del servidor.
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('YouTube: no se pudo reservar el flujo de video en memoria.');
        }
        try {
            $encoded = $prepared['video_base64'];
            for ($offset = 0, $length = strlen($encoded); $offset < $length; $offset += 1048576) {
                $chunk = base64_decode(substr($encoded, $offset, 1048576), true);
                if ($chunk === false || fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('YouTube: no se pudo preparar el flujo de video.');
                }
            }
            rewind($stream);
            $init = $this->request($client, 'POST', 'https://www.googleapis.com/upload/youtube/v3/videos', [
                'query' => ['uploadType' => 'resumable', 'part' => 'snippet,status', 'notifySubscribers' => 'false'],
                'headers' => ['X-Upload-Content-Length' => (string) $prepared['video_size'],
                    'X-Upload-Content-Type' => $prepared['mime_type']],
                'json' => $prepared['metadata']
            ], $token, true);
            // La respuesta de inicio legítima no lleva cuerpo. Comprobar errores JSON si lo incluye.
            $this->readJson($init, true, true);
            if (!in_array($init->getStatusCode(), [200, 201], true)) {
                throw new RuntimeException('YouTube: no confirmó el inicio de la carga.');
            }
            $session = $init->getHeaderLine('Location');
            $this->validateSession($session);
            $start = 0;
            while ($start < $prepared['video_size']) {
                $chunk = fread($stream, min(self::CHUNK_BYTES, $prepared['video_size'] - $start));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('YouTube: lectura de video incompleta; comprueba el canal antes de repetir.');
                }
                $end = $start + strlen($chunk) - 1;
                $response = $this->request($client, 'PUT', $session, [
                    'headers' => ['Content-Type' => $prepared['mime_type'], 'Content-Length' => (string) strlen($chunk),
                        'Content-Range' => 'bytes ' . $start . '-' . $end . '/' . $prepared['video_size']],
                    'body' => $chunk
                ], $token, true, true);
                if ($end + 1 < $prepared['video_size']) {
                    if ($response->getStatusCode() !== 308
                        || $response->getHeaderLine('Range') !== 'bytes=0-' . $end) {
                        throw new RuntimeException('YouTube: carga incompleta o confirmación de bloque incompatible; no se reintentó. Comprueba el canal antes de repetir.');
                    }
                    $start = $end + 1;
                    continue;
                }
                $data = $this->readJson($response, true);
                if (!in_array($response->getStatusCode(), [200, 201], true)
                    || !is_string($data['id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{11}$/D', $data['id']) !== 1) {
                    throw new RuntimeException('YouTube: no confirmó el video completo; comprueba el canal antes de repetir.');
                }
                return ['success' => true, 'status_code' => $response->getStatusCode(),
                    'data' => $this->sanitize($data, [$token, $session, $this->sessionId($session)])];
            }
            throw new RuntimeException('YouTube: no confirmó la carga completa.');
        } finally {
            fclose($stream);
        }
    }

    private function getToken(string $id): string
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('YouTube: fallo del almacenamiento o descifrado de la credencial; revisa el gestor.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('YouTube: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token) || strlen($token) > 8192
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1) {
            throw new InvalidArgumentException('YouTube: usa generic_bearer con un access token OAuth en data.api_key, sin Bearer.');
        }
        return $token;
    }

    private function request(ClientInterface $client, string $method, string $url, array $options,
        string $token, bool $upload, bool $allowIncomplete = false): ResponseInterface
    {
        $options['headers'] = array_merge($options['headers'] ?? [], ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);
        $options += ['verify' => true, 'timeout' => $upload ? 90 : 30, 'connect_timeout' => 10,
            'allow_redirects' => false, 'http_errors' => false, 'stream' => true];
        try {
            $response = $client->request($method, $url, $options);
        } catch (\Throwable $e) {
            throw new RuntimeException($upload
                ? 'YouTube: fallo de transporte; carga de resultado incierto. No se reintentó; comprueba el canal antes de repetir.'
                : 'YouTube: fallo de transporte al consultar el recurso.');
        }
        $status = $response->getStatusCode();
        if (($status >= 200 && $status < 300) || ($allowIncomplete && $status === 308)) {
            return $response;
        }
        $reason = match ($status) {
            401 => 'token expirado, revocado o inválido; reemplázalo manualmente',
            403 => 'acceso denegado; revisa scopes, canal, API habilitada, cuota del proyecto y si los comentarios están desactivados',
            404 => 'canal, video, comentario o sesión inexistente o inaccesible',
            400, 422 => 'parámetros o contenido rechazados; revisa IDs, cursor, formato y metadatos',
            429 => 'cuota o límite de solicitudes alcanzado',
            default => 'servicio rechazó la solicitud o devolvió un estado inesperado'
        };
        throw new RuntimeException("YouTube: {$reason} (HTTP {$status})."
            . ($upload ? ' No se reintentó; comprueba el canal antes de repetir.' : ''), $status);
    }

    private function readJson(ResponseInterface $response, bool $upload, bool $allowEmpty = false): array
    {
        try {
            $body = '';
            $stream = $response->getBody();
            while (!$stream->eof() && strlen($body) <= 4194304) {
                $chunk = $stream->read(min(8192, 4194305 - strlen($body)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $body .= $chunk;
            }
            if (strlen($body) > 4194304) {
                throw new RuntimeException();
            }
            if (trim($body) === '' && $allowEmpty) {
                return [];
            }
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !str_starts_with(ltrim($body), '{')) {
                throw new RuntimeException();
            }
            if (array_key_exists('items', $data)) {
                $object = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
                if (!is_array($object->items)) {
                    throw new RuntimeException();
                }
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('YouTube: respuesta JSON inválida o superior a 4 MiB.'
                . ($upload ? ' La carga puede existir; comprueba el canal antes de repetir.' : ''));
        }
        if (array_key_exists('error', $data)) {
            throw new RuntimeException('YouTube: la respuesta contiene un error funcional; revisa permisos y cuotas.'
                . ($upload ? ' Comprueba el canal antes de repetir la carga.' : ''));
        }
        return $data;
    }

    private function validateSession(string $session): void
    {
        $parts = parse_url($session);
        if ($parts === false || strlen($session) > 8192 || preg_match('/[\x00-\x20\x7f]/', $session)
            || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'www.googleapis.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ($parts['path'] ?? null) !== '/upload/youtube/v3/videos' || $this->sessionId($session) === '') {
            throw new RuntimeException('YouTube: origen o ruta de sesión de carga no permitido; no se envió el video.');
        }
    }

    private function sessionId(string $session): string
    {
        parse_str((string) parse_url($session, PHP_URL_QUERY), $query);
        return is_string($query['upload_id'] ?? null) ? $query['upload_id'] : '';
    }

    private function prepareParameters(array $parameters): array
    {
        $id = $parameters['credential_id'] ?? null;
        if (!is_string($id) || trim($id) === '' || strlen($id) > 200) {
            throw new InvalidArgumentException('YouTube: credential_id es obligatorio y debe ser texto.');
        }
        $operation = $parameters['operation'] ?? 'get_channel';
        if (!is_string($operation) || !in_array($operation,
            ['get_channel', 'list_comment_threads', 'list_comments', 'upload_video'], true)) {
            throw new InvalidArgumentException('YouTube: operación inválida.');
        }
        $prepared = ['credential_id' => $id, 'operation' => $operation];
        if ($operation === 'upload_video') {
            return $prepared + $this->prepareUpload($parameters);
        }
        $max = $parameters['max_results'] ?? 20;
        if (is_string($max) && preg_match('/^[1-9][0-9]{0,2}$/D', $max)) {
            $max = (int) $max;
        }
        if (!is_int($max) || $max < 1 || $max > ($operation === 'get_channel' ? 50 : 100)) {
            throw new InvalidArgumentException('YouTube: max_results debe ser entero entre 1 y 50 (canales) o 100 (comentarios).');
        }
        $cursor = $parameters['page_token'] ?? '';
        if (!is_string($cursor) || !$this->validCursor($cursor)) {
            throw new InvalidArgumentException('YouTube: page_token debe ser un cursor de página válido, no una URL.');
        }
        $query = ['maxResults' => $max];
        if ($cursor !== '') {
            $query['pageToken'] = $cursor;
        }
        if ($operation === 'get_channel') {
            $channelId = $parameters['channel_id'] ?? '';
            if (!is_string($channelId) || ($channelId !== '' && preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channelId) !== 1)) {
                throw new InvalidArgumentException('YouTube: channel_id debe ser un ID de canal válido o texto vacío para el canal propio.');
            }
            $query += ['part' => 'snippet,contentDetails,statistics'];
            $query += $channelId === '' ? ['mine' => 'true'] : ['id' => $channelId];
            $resource = 'channels';
        } elseif ($operation === 'list_comment_threads') {
            $videoId = $parameters['video_id'] ?? null;
            if (!is_string($videoId) || preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId) !== 1) {
                throw new InvalidArgumentException('YouTube: video_id debe ser un ID de video válido.');
            }
            $order = $parameters['order'] ?? 'time';
            if (!is_string($order) || !in_array($order, ['time', 'relevance'], true)) {
                throw new InvalidArgumentException('YouTube: order debe ser time o relevance.');
            }
            $query += ['part' => 'snippet,replies', 'videoId' => $videoId, 'order' => $order, 'textFormat' => 'plainText'];
            $search = $parameters['search_terms'] ?? '';
            if (!is_string($search) || strlen($search) > 500 || !mb_check_encoding($search, 'UTF-8') || str_contains($search, "\0")) {
                throw new InvalidArgumentException('YouTube: search_terms debe ser texto UTF-8 de hasta 500 bytes.');
            }
            if ($search !== '') {
                $query['searchTerms'] = $search;
            }
            $resource = 'commentThreads';
        } else {
            $parent = $parameters['parent_id'] ?? null;
            if (!is_string($parent) || preg_match('/^[A-Za-z0-9_.-]{1,256}$/D', $parent) !== 1) {
                throw new InvalidArgumentException('YouTube: parent_id debe ser el ID del comentario principal.');
            }
            $query += ['part' => 'snippet', 'parentId' => $parent, 'textFormat' => 'plainText'];
            $resource = 'comments';
        }
        return $prepared + ['resource' => $resource, 'query' => $query];
    }

    private function prepareUpload(array $parameters): array
    {
        $encoded = $parameters['video_base64'] ?? null;
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 4 * (int) ceil(self::MAX_VIDEO_BYTES / 3)
            || strlen($encoded) % 4 !== 0 || preg_match('/[^A-Za-z0-9+\/=]/', $encoded)
            || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D', $encoded) !== 1) {
            throw new InvalidArgumentException('YouTube: video_base64 debe ser Base64 canónico sin espacios ni data URI, máximo 20 MiB decodificados.');
        }
        $last = substr($encoded, -4);
        $decodedLast = base64_decode($last, true);
        $size = intdiv(strlen($encoded), 4) * 3 - (str_ends_with($encoded, '==') ? 2 : (str_ends_with($encoded, '=') ? 1 : 0));
        if ($decodedLast === false || base64_encode($decodedLast) !== $last || $size < 1 || $size > self::MAX_VIDEO_BYTES) {
            throw new InvalidArgumentException('YouTube: Base64 no canónico o video superior a 20 MiB.');
        }
        $mime = $parameters['mime_type'] ?? 'video/mp4';
        if (!is_string($mime) || !in_array($mime,
            ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/mpeg', 'video/ogg'], true)) {
            throw new InvalidArgumentException('YouTube: formato de video no admitido por este nodo.');
        }
        $title = $parameters['title'] ?? null;
        if (!is_string($title) || trim($title) === '' || !mb_check_encoding($title, 'UTF-8')
            || mb_strlen($title, 'UTF-8') > 100 || strpbrk($title, "<>\0") !== false) {
            throw new InvalidArgumentException('YouTube: title requiere texto UTF-8 no vacío de hasta 100 caracteres, sin < ni >.');
        }
        $description = $parameters['description'] ?? '';
        if (!is_string($description) || !mb_check_encoding($description, 'UTF-8') || strlen($description) > 5000
            || strpbrk($description, "<>\0") !== false) {
            throw new InvalidArgumentException('YouTube: description debe ser texto UTF-8 de hasta 5000 bytes, sin < ni >.');
        }
        $category = $parameters['category_id'] ?? '22';
        if (is_int($category)) {
            $category = (string) $category;
        }
        if (!is_string($category) || preg_match('/^[1-9][0-9]{0,5}$/D', $category) !== 1) {
            throw new InvalidArgumentException('YouTube: category_id debe ser un ID de categoría numérico positivo.');
        }
        $privacy = $parameters['privacy_status'] ?? 'private';
        if (!is_string($privacy) || !in_array($privacy, ['private', 'public', 'unlisted'], true)) {
            throw new InvalidArgumentException('YouTube: privacy_status debe ser private, public o unlisted explícito.');
        }
        $snippet = ['title' => $title, 'description' => $description, 'categoryId' => $category];
        if (array_key_exists('tags', $parameters) && $parameters['tags'] !== '') {
            $tags = $parameters['tags'];
            if (is_string($tags)) {
                try {
                    if (!str_starts_with(ltrim($tags), '[')) {
                        throw new InvalidArgumentException();
                    }
                    $tags = json_decode($tags, true, 16, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    throw new InvalidArgumentException('YouTube: tags debe ser una lista JSON válida.');
                }
            }
            if (!is_array($tags) || !array_is_list($tags) || count($tags) > 100) {
                throw new InvalidArgumentException('YouTube: tags debe ser una lista de hasta 100 textos.');
            }
            $tagLength = max(0, count($tags) - 1);
            foreach ($tags as $tag) {
                if (!is_string($tag) || trim($tag) === '' || !mb_check_encoding($tag, 'UTF-8') || strpbrk($tag, "<>\0") !== false) {
                    throw new InvalidArgumentException('YouTube: cada etiqueta debe ser texto UTF-8 no vacío, sin < ni >.');
                }
                $tagLength += mb_strlen($tag, 'UTF-8') + (str_contains($tag, ' ') ? 2 : 0);
            }
            if ($tagLength > 500) {
                throw new InvalidArgumentException('YouTube: las etiquetas superan 500 caracteres incluyendo separadores y comillas de etiquetas con espacios.');
            }
            if ($tags !== []) {
                $snippet['tags'] = $tags;
            }
        }
        $status = ['privacyStatus' => $privacy];
        $kids = $parameters['made_for_kids'] ?? 'unspecified';
        if (!in_array($kids, ['unspecified', 'true', 'false', true, false], true)) {
            throw new InvalidArgumentException('YouTube: made_for_kids debe ser unspecified, true o false.');
        }
        if ($kids !== 'unspecified') {
            $status['selfDeclaredMadeForKids'] = $kids === true || $kids === 'true';
        }
        return ['video_base64' => $encoded, 'video_size' => $size, 'mime_type' => $mime,
            'metadata' => ['snippet' => $snippet, 'status' => $status]];
    }

    private function validCursor(string $cursor): bool
    {
        return strlen($cursor) <= 2048 && ($cursor === '' || preg_match('/^[A-Za-z0-9_+\/=.-]+$/D', $cursor) === 1);
    }

    private function sanitize(mixed $value, array $secrets): mixed
    {
        if (is_string($value)) {
            if (preg_match('~https?://[^\s]*[?&]upload_id=~i', $value)) {
                return '[REDACTED]';
            }
            foreach ($secrets as $secret) {
                if ($secret !== '') {
                    $value = str_replace([$secret, rawurlencode($secret)], '[REDACTED]', $value);
                }
            }
            return $value;
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key) ? $this->sanitize($key, $secrets) : $key;
                $safe[$safeKey] = is_string($key) && preg_match('/^(?:authorization|access_token|refresh_token|api_key|client_secret|upload_id|upload_url|session_url)$/iD', $key)
                    ? '[REDACTED]' : $this->sanitize($item, $secrets);
            }
            return $safe;
        }
        return $value;
    }
}
