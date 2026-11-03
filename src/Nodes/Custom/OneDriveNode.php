<?php

namespace P8P\Nodes\Custom;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class OneDriveNode implements NodeInterface
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const MAX_FILE = 20971520;
    private const MAX_JSON = 2097152;
    private const CHUNK_UNIT = 327680;
    private const FIELDS = 'id,name,size,eTag,cTag,createdDateTime,lastModifiedDateTime,file,folder,parentReference';
    private ?ClientInterface $client;
    private ?CredentialManager $credentials;
    private ?ClientInterface $anonymousClient;

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null, ?ClientInterface $anonymousClient = null)
    {
        // Sin conexiones ni recuperación de secretos durante el descubrimiento.
        $this->client = $client;
        $this->credentials = $credentials;
        $this->anonymousClient = $anonymousClient;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'onedrive', 'name' => 'Microsoft OneDrive', 'category' => 'Community',
            'description' => 'Sube y descarga archivos de hasta 20 MiB y crea carpetas mediante Microsoft Graph.',
            'icon' => 'Folder', 'is_start' => false,
            'help_text' => 'Credencial generic_bearer, API Key = JSON {"access_token":"TOKEN","auth_mode":"delegated"} o {"access_token":"TOKEN","auth_mode":"application"}. Delegated sin drive_id usa /me/drive; application exige drive_id explícito y consentimiento administrativo. Lectura: Files.Read delegado / Files.Read.All aplicación; escritura simple y carpetas: Files.ReadWrite / Files.ReadWrite.All. La referencia de createUploadSession exige Sites.ReadWrite.All para application. Acceso al recurso y políticas de tenant también aplican. Solo nube comercial graph.microsoft.com; sin OAuth interactivo ni renovación automática: reemplaza el token caducado manualmente. upload_file acepta Base64 canónico, nunca rutas locales ni URLs, límite local 20 MiB. Session (predeterminado): conflicto fail, fragmentos secuenciales múltiplos de 320 KiB, sin persistir sesión ni reintentar. Simple: exige seleccionar conflict_behavior=replace explícitamente porque PUT puede reemplazar por nombre; admite archivos vacíos. Una sesión no admite archivo vacío. download_file devuelve data.content_base64, size y mime_type (máximo 20 MiB), sin URLs preautorizadas. No compartas contenidos sensibles en workflows/historial. Descargar usa enlace temporal validado y cliente anónimo sin Bearer ni cookies. Los errores detienen el flujo. Escritura incierta: comprueba OneDrive antes de repetir. Expresiones resueltas una vez por Engine. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Graph (JSON en API Key)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'upload_file', 'options' => [
                    ['value' => 'upload_file', 'label' => 'Subir archivo'], ['value' => 'download_file', 'label' => 'Descargar archivo en Base64'],
                    ['value' => 'create_folder', 'label' => 'Crear carpeta']
                ]],
                'drive_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de unidad (obligatorio con application; vacío = usuario actual)'],
                'parent_id' => ['type' => 'string', 'required' => false, 'label' => 'ID carpeta padre (subir/crear)', 'default' => 'root'],
                'name' => ['type' => 'string', 'required' => false, 'label' => 'Nombre de archivo/carpeta (sin ruta)'],
                'content_base64' => ['type' => 'textarea', 'required' => false, 'label' => 'Archivo Base64 canónico (hasta 20 MiB decodificados)'],
                'mime_type' => ['type' => 'string', 'required' => false, 'label' => 'MIME de subida simple', 'default' => 'application/octet-stream'],
                'upload_mode' => ['type' => 'select', 'required' => false, 'label' => 'Método de subida', 'default' => 'session', 'options' => [
                    ['value' => 'session', 'label' => 'Sesión por fragmentos'], ['value' => 'simple', 'label' => 'Simple (puede reemplazar por nombre)']
                ]],
                'conflict_behavior' => ['type' => 'select', 'required' => false, 'label' => 'Conflicto por nombre (simple exige replace explícito)', 'default' => 'fail', 'options' => [
                    ['value' => 'fail', 'label' => 'Fallar si existe'], ['value' => 'rename', 'label' => 'Renombrar nuevo'],
                    ['value' => 'replace', 'label' => 'Reemplazar existente (autorización explícita)']
                ]],
                'chunk_size' => ['type' => 'number', 'required' => false, 'label' => 'Bytes por fragmento (320 KiB–10 MiB, múltiplo de 320 KiB)', 'default' => 5242880],
                'item_id' => ['type' => 'string', 'required' => false, 'label' => 'ID del archivo (descargar)']
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
        // Engine ya evalúa ExpressionEvaluator. Validar los tipos resueltos sin interpolar de nuevo.
        $p = $this->prepare($parameters);
        $config = $this->credential($p['credential_id']);
        if ($config['auth_mode'] === 'application' && $p['drive_id'] === '') {
            throw new InvalidArgumentException('OneDrive: application requiere drive_id explícito; /me solo admite token delegado.');
        }
        $root = $p['drive_id'] === '' ? '/me/drive' : '/drives/' . rawurlencode($p['drive_id']);
        $token = $config['access_token'];
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        if ($p['operation'] === 'download_file') {
            return $this->download($root, $p['item_id'], $headers, $token);
        }
        $parent = $p['parent_id'] === 'root' ? '/root' : '/items/' . rawurlencode($p['parent_id']);
        if ($p['operation'] === 'create_folder') {
            $response = $this->http(false, 'POST', self::GRAPH . $root . $parent . '/children', $headers, [
                'json' => ['name' => $p['name'], 'folder' => new \stdClass(), '@microsoft.graph.conflictBehavior' => $p['conflict_behavior']]
            ], true);
            $this->status($response, [201], true);
            $body = $this->json($response, true);
            $data = $this->resource($body, true);
        } elseif ($p['upload_mode'] === 'simple') {
            $response = $this->http(false, 'PUT', self::GRAPH . $root . $parent . ':/' . rawurlencode($p['name']) . ':/content',
                $headers + ['Content-Type' => $p['mime_type'], 'Content-Length' => (string)strlen($p['bytes'])], ['body' => $p['bytes']], true);
            $this->status($response, [200, 201], true);
            $body = $this->json($response, true);
            $data = $this->resource($body, false, strlen($p['bytes']));
        } else {
            [$response, $data] = $this->uploadSession($root . $parent, $p, $headers, $token);
        }
        $this->checkSecrets($data, [$token]);
        return ['success' => true, 'status_code' => $response->getStatusCode(), 'data' => $data];
    }

    private function uploadSession(string $parentPath, array $p, array $headers, string $token): array
    {
        $response = $this->http(false, 'POST', self::GRAPH . $parentPath . ':/' . rawurlencode($p['name']) . ':/createUploadSession', $headers, [
            'json' => ['item' => ['name' => $p['name'], '@microsoft.graph.conflictBehavior' => $p['conflict_behavior']]]
        ], true);
        $this->status($response, [200], true);
        $session = $this->json($response, true);
        $uploadUrl = $session['uploadUrl'] ?? null;
        $this->temporaryUrl($uploadUrl, true);
        $this->checkSecrets(['url' => $uploadUrl], [$token]);
        $this->expiry($session['expirationDateTime'] ?? null);
        $total = strlen($p['bytes']);
        if (array_key_exists('nextExpectedRanges', $session)) {
            $this->ranges($session['nextExpectedRanges'], 0, $total);
        }
        $offset = 0;
        // Máximo 64 fragmentos por el límite de 20 MiB y el mínimo de 320 KiB.
        while ($offset < $total) {
            $length = min($p['chunk_size'], $total - $offset);
            $end = $offset + $length - 1;
            $response = $this->http(true, 'PUT', $uploadUrl, [
                'Content-Type' => 'application/octet-stream', 'Content-Length' => (string)$length,
                'Content-Range' => "bytes {$offset}-{$end}/{$total}"
            ], ['body' => substr($p['bytes'], $offset, $length)], true);
            $this->status($response, [200, 201, 202], true);
            $body = $this->json($response, true);
            $this->checkSecrets($body, [$token]);
            $offset += $length;
            if ($response->getStatusCode() === 202) {
                if ($offset >= $total) {
                    throw new RuntimeException('OneDrive: sesión no confirmó el archivo tras el último fragmento. Resultado incierto; comprueba la unidad antes de repetir.');
                }
                $this->ranges($body['nextExpectedRanges'] ?? null, $offset, $total);
                $this->expiry($body['expirationDateTime'] ?? null);
            } else {
                if ($offset !== $total) {
                    throw new RuntimeException('OneDrive: la sesión confirmó prematuramente un archivo incompleto. Resultado incierto; comprueba la unidad.');
                }
                $data = $this->resource($body, false, $total);
                $this->checkSecrets($data, [$token, $uploadUrl]);
                return [$response, $data];
            }
        }
        throw new RuntimeException('OneDrive: sesión incompleta; no se reintentó.');
    }

    private function download(string $root, string $itemId, array $headers, string $token): array
    {
        $path = self::GRAPH . $root . '/items/' . rawurlencode($itemId);
        $metadata = $this->http(false, 'GET', $path, $headers, ['query' => ['$select' => self::FIELDS]], false);
        $this->status($metadata, [200], false);
        $item = $this->resource($this->json($metadata, false), false);
        if ($item['size'] > self::MAX_FILE) {
            throw new RuntimeException('OneDrive: el archivo supera el límite local de descarga de 20 MiB.');
        }
        $this->checkSecrets($item, [$token]);
        $response = $this->http(false, 'GET', $path . '/content', $headers, [], false);
        $downloadUrl = null;
        if ($response->getStatusCode() === 302) {
            if (count($response->getHeader('Location')) !== 1) {
                throw new RuntimeException('OneDrive: respuesta de descarga sin una ubicación temporal válida.');
            }
            $downloadUrl = $response->getHeaderLine('Location');
            $this->temporaryUrl($downloadUrl, false);
            $this->checkSecrets(['url' => $downloadUrl], [$token]);
            $this->close($response, false);
            $response = $this->http(true, 'GET', $downloadUrl, ['Accept' => 'application/octet-stream'], [], false);
        }
        $this->status($response, [200], false);
        $bytes = $this->read($response, self::MAX_FILE, false);
        if (strlen($bytes) !== $item['size']) {
            throw new RuntimeException('OneDrive: tamaño descargado incompatible con los metadatos; archivo omitido.');
        }
        if (str_contains($bytes, $token) || str_contains($bytes, rawurlencode($token)) || str_contains($bytes, urlencode($token))
            || ($downloadUrl !== null && (str_contains($bytes, $downloadUrl) || str_contains($bytes, rawurlencode($downloadUrl))))) {
            throw new RuntimeException('OneDrive: contenido descartado por contener datos de autenticación o acceso temporal.');
        }
        $data = $item + ['content_base64' => base64_encode($bytes), 'mime_type' => $item['file']['mimeType']];
        $this->checkSecrets($item, array_filter([$token, $downloadUrl]));
        return ['success' => true, 'status_code' => $response->getStatusCode(), 'data' => $data];
    }

    private function http(bool $anonymous, string $method, string $url, array $headers, array $options, bool $write): ResponseInterface
    {
        try {
            // Clientes separados; PSR Request + headers=null elimina TODOS los headers por defecto.
            // auth=null también desactiva Basic/Digest configurado en un cliente inyectado.
            $client = $anonymous ? ($this->anonymousClient ?? new Client()) : ($this->client ?? new Client());
            return $client->send(new Request($method, $url, $headers), $options + [
                'headers' => null, 'auth' => null, 'cookies' => false, 'query' => null,
                'timeout' => 60, 'connect_timeout' => 10, 'verify' => true,
                'allow_redirects' => false, 'http_errors' => false, 'stream' => true
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('OneDrive: fallo de transporte; no se reintentó.' . ($write
                ? ' Resultado incierto: la escritura puede haberse realizado; comprueba la unidad antes de repetir.' : ' No se completó la descarga.'));
        }
    }

    private function status(ResponseInterface $response, array $accepted, bool $write): void
    {
        $status = $response->getStatusCode();
        if (in_array($status, $accepted, true)) {
            return;
        }
        $this->close($response, $write);
        $reason = match ($status) {
            401 => 'token o URL temporal inválido, caducado o revocado; revisa la credencial manualmente',
            403 => 'permisos insuficientes; revisa modo delegado/aplicación, consentimiento, scopes y acceso a la unidad',
            404 => 'archivo, carpeta, unidad o sesión inexistente o inaccesible',
            409 => 'conflicto por nombre o recurso; no se reintentó',
            412 => 'precondición rechazada; el recurso cambió',
            416 => 'rango de fragmento rechazado; sesión sin reintentos ni reanudación persistente',
            429 => 'límite de solicitudes o cuota; espera antes de ejecutar de nuevo',
            507 => 'espacio disponible insuficiente',
            default => 'respuesta HTTP inesperada o solicitud rechazada'
        };
        throw new RuntimeException("OneDrive: {$reason} (HTTP {$status})." . ($write
            ? ' Comprueba la unidad antes de repetir; la escritura puede haberse realizado.' : ''), $status);
    }

    private function read(ResponseInterface $response, int $limit, bool $write): string
    {
        try {
            $stream = $response->getBody();
            $raw = '';
            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && (preg_match('/^[0-9]{1,12}$/D', $length) !== 1 || (int)$length > $limit)) {
                throw new RuntimeException();
            }
            while (!$stream->eof() && strlen($raw) <= $limit) {
                $chunk = $stream->read(min(8192, $limit + 1 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $raw .= $chunk;
            }
            if (strlen($raw) > $limit) {
                throw new RuntimeException();
            }
            return $raw;
        } catch (\Throwable $e) {
            throw new RuntimeException('OneDrive: lectura incompleta, inválida o superior al límite local.' . ($write
                ? ' Resultado incierto; comprueba la unidad antes de repetir.' : ''));
        } finally {
            $this->close($response, $write);
        }
    }

    private function close(ResponseInterface $response, bool $write): void
    {
        try {
            $response->getBody()->close();
        } catch (\Throwable $e) {
            throw new RuntimeException('OneDrive: no se pudo cerrar la lectura de forma segura.' . ($write
                ? ' Resultado incierto; comprueba la unidad antes de repetir.' : ''));
        }
    }

    private function json(ResponseInterface $response, bool $write): array
    {
        $raw = $this->read($response, self::MAX_JSON, $write);
        try {
            $shape = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!$shape instanceof \stdClass || !is_array($body) || array_key_exists('error', $body)
                || array_key_exists('errors', $body)) {
                throw new RuntimeException();
            }
            return $body;
        } catch (\Throwable $e) {
            throw new RuntimeException('OneDrive: respuesta JSON inválida, vacía o con error funcional.' . ($write
                ? ' Resultado incierto; comprueba la unidad antes de repetir.' : ''), $response->getStatusCode());
        }
    }

    private function resource(array $body, bool $folder, ?int $expectedSize = null): array
    {
        $invalid = 'OneDrive: metadatos de recurso inválidos; comprueba la unidad antes de repetir una escritura.';
        try {
            $id = $this->id($body['id'] ?? null, 'id de respuesta');
            $name = $this->name($body['name'] ?? null);
            if (!is_int($body['size'] ?? null) || $body['size'] < 0
                || ($expectedSize !== null && $body['size'] !== $expectedSize)) {
                throw new RuntimeException();
            }
            $data = ['id' => $id, 'name' => $name, 'size' => $body['size']];
            if ($folder) {
                if (!is_array($body['folder'] ?? null) || isset($body['file'])) {
                    throw new RuntimeException();
                }
                $data['folder'] = [];
                if (array_key_exists('childCount', $body['folder'])) {
                    if (!is_int($body['folder']['childCount']) || $body['folder']['childCount'] < 0) {
                        throw new RuntimeException();
                    }
                    $data['folder']['childCount'] = $body['folder']['childCount'];
                }
            } else {
                if (!is_array($body['file'] ?? null) || isset($body['folder'])) {
                    throw new RuntimeException();
                }
                $mime = $this->mime($body['file']['mimeType'] ?? null);
                $data['file'] = ['mimeType' => $mime];
            }
            foreach (['eTag', 'cTag', 'createdDateTime', 'lastModifiedDateTime'] as $key) {
                if (array_key_exists($key, $body)) {
                    $data[$key] = $this->text($body[$key], $key, 512);
                }
            }
            if (isset($body['parentReference'])) {
                if (!is_array($body['parentReference'])) {
                    throw new RuntimeException();
                }
                $data['parentReference'] = [];
                foreach (['driveId', 'id'] as $key) {
                    if (array_key_exists($key, $body['parentReference'])) {
                        $data['parentReference'][$key] = $this->id($body['parentReference'][$key], 'parentReference');
                    }
                }
            }
            return $data;
        } catch (\Throwable $e) {
            throw new RuntimeException($invalid);
        }
    }

    private function temporaryUrl(mixed $url, bool $upload): void
    {
        $invalid = 'OneDrive: origen o formato de URL temporal no permitido; no se siguió ni se registró el enlace.';
        if (!is_string($url) || strlen($url) > 16000 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new RuntimeException($invalid);
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host) !== 1
            || !str_starts_with($parts['path'] ?? '', '/')) {
            throw new RuntimeException($invalid);
        }
        // Solo endpoints Microsoft comerciales documentados; no aceptar *.microsoft.com ni hosts arbitrarios.
        $suffixes = $upload ? ['.up.1drv.com', '.sharepoint.com']
            : ['.files.1drv.com', '.storage.live.com', '.livefilestore.com', '.sharepoint.com'];
        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                return;
            }
        }
        if (!$upload && $host === 'storage.live.com') {
            return;
        }
        throw new RuntimeException($invalid);
    }

    private function expiry(mixed $value): void
    {
        try {
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
                || (new DateTimeImmutable($value))->getTimestamp() <= time()
                || DateTimeImmutable::getLastErrors() !== false) {
                throw new RuntimeException();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('OneDrive: sesión sin fecha de caducidad válida o ya caducada. No se reintentó.');
        }
    }

    private function ranges(mixed $ranges, int $expected, int $total): void
    {
        if (!is_array($ranges) || count($ranges) !== 1 || !is_string($ranges[0] ?? null)
            || preg_match('/^([0-9]{1,9})-([0-9]{1,9})?$/D', $ranges[0], $match) !== 1
            || (int)$match[1] !== $expected || (isset($match[2]) && (int)$match[2] !== $total - 1)) {
            throw new RuntimeException('OneDrive: rangos esperados incompatibles con la carga secuencial. Resultado incierto; comprueba la unidad.');
        }
    }

    private function credential(string $id): array
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('OneDrive: fallo del gestor al recuperar o descifrar la credencial; revisa almacenamiento y configuración.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('OneDrive: credencial inexistente.');
        }
        $raw = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($raw) || strlen($raw) > 32768) {
            throw new InvalidArgumentException('OneDrive: requiere generic_bearer con configuración JSON en API Key.');
        }
        $config = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)
            || !is_string($config['access_token'] ?? null) || strlen($config['access_token']) < 8
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $config['access_token']) !== 1
            || !in_array($config['auth_mode'] ?? null, ['delegated', 'application'], true)) {
            throw new InvalidArgumentException('OneDrive: JSON de credencial inválido; requiere access_token OAuth y auth_mode delegated/application.');
        }
        // No usar user_id de Outlook: application siempre requiere unidad explícita, nunca /me.
        return $config;
    }

    private function prepare(array $p): array
    {
        $result = ['credential_id' => $this->text($p['credential_id'] ?? null, 'credential_id', 255)];
        $op = array_key_exists('operation', $p) ? $p['operation'] : 'upload_file';
        if (!is_string($op) || !in_array($op, ['upload_file', 'download_file', 'create_folder'], true)) {
            throw new InvalidArgumentException('OneDrive: operación inválida.');
        }
        $result['operation'] = $op;
        $drive = array_key_exists('drive_id', $p) ? $p['drive_id'] : '';
        $result['drive_id'] = $drive === '' ? '' : $this->id($drive, 'drive_id');
        if ($op === 'download_file') {
            $result['item_id'] = $this->id($p['item_id'] ?? null, 'item_id');
            return $result;
        }
        $parent = array_key_exists('parent_id', $p) ? $p['parent_id'] : 'root';
        $result['parent_id'] = $this->id($parent, 'parent_id');
        $result['name'] = $this->name($p['name'] ?? null);
        $conflict = array_key_exists('conflict_behavior', $p) ? $p['conflict_behavior'] : 'fail';
        if (!is_string($conflict) || !in_array($conflict, ['fail', 'rename', 'replace'], true) || ($op === 'create_folder' && $conflict === 'replace')) {
            throw new InvalidArgumentException('OneDrive: conflicto inválido; carpetas admiten fail o rename, nunca reemplazo implícito.');
        }
        $result['conflict_behavior'] = $conflict;
        if ($op === 'create_folder') {
            return $result;
        }
        $mode = array_key_exists('upload_mode', $p) ? $p['upload_mode'] : 'session';
        if (!is_string($mode) || !in_array($mode, ['simple', 'session'], true)) {
            throw new InvalidArgumentException('OneDrive: upload_mode debe ser simple o session.');
        }
        if ($mode === 'simple' && (!array_key_exists('conflict_behavior', $p) || $conflict !== 'replace')) {
            throw new InvalidArgumentException('OneDrive: subida simple puede reemplazar por nombre; selecciona conflict_behavior=replace explícitamente o usa sesión con fail/rename.');
        }
        $result['upload_mode'] = $mode;
        $encoded = $p['content_base64'] ?? null;
        if (!is_string($encoded) || strlen($encoded) > 27962028 || ($bytes = base64_decode($encoded, true)) === false
            || base64_encode($bytes) !== $encoded || strlen($bytes) > self::MAX_FILE) {
            throw new InvalidArgumentException('OneDrive: content_base64 debe ser Base64 canónico de hasta 20 MiB decodificados, sin URL ni ruta local.');
        }
        if ($mode === 'session' && $bytes === '') {
            throw new InvalidArgumentException('OneDrive: sesión requiere archivo no vacío; usa simple con replace explícito para cero bytes.');
        }
        $result['bytes'] = $bytes;
        $result['mime_type'] = $this->mime(array_key_exists('mime_type', $p) ? $p['mime_type'] : 'application/octet-stream');
        $chunk = array_key_exists('chunk_size', $p) ? $p['chunk_size'] : 5242880;
        if (is_string($chunk) && preg_match('/^[0-9]{1,8}$/D', $chunk)) {
            $chunk = (int)$chunk;
        }
        if (!is_int($chunk) || $chunk < self::CHUNK_UNIT || $chunk > 10485760 || $chunk % self::CHUNK_UNIT !== 0) {
            throw new InvalidArgumentException('OneDrive: chunk_size debe ser múltiplo de 327680 bytes entre 320 KiB y 10 MiB.');
        }
        $result['chunk_size'] = $chunk;
        return $result;
    }

    private function name(mixed $value): string
    {
        $name = $this->text($value, 'name', 1020);
        if (mb_strlen($name) > 255 || preg_match('/["*:<>?\/\\\\|]/u', $name) || preg_match('/[. ]$/u', $name)
            || str_starts_with($name, '~$') || in_array($name, ['.', '..'], true)) {
            throw new InvalidArgumentException('OneDrive: nombre inválido; indica un nombre único sin ruta ni caracteres reservados.');
        }
        return $name;
    }

    private function id(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9!._-]{1,512}$/D', $value) !== 1 || in_array($value, ['.', '..'], true)) {
            throw new InvalidArgumentException("OneDrive: {$field} debe ser un ID opaco válido, sin URL ni ruta.");
        }
        return $value;
    }

    private function mime(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 127 || preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('OneDrive: MIME inválido; usa tipo/subtipo sin parámetros.');
        }
        return $value;
    }

    private function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
            throw new InvalidArgumentException("OneDrive: campo {$field} inválido.");
        }
        return $value;
    }

    private function checkSecrets(array $data, array $secrets): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($secrets as $secret) {
            if (str_contains($json, $secret) || str_contains($json, rawurlencode($secret)) || str_contains($json, urlencode($secret))) {
                throw new RuntimeException('OneDrive: respuesta descartada porque contiene datos de autenticación o acceso temporal.');
            }
        }
    }
}
