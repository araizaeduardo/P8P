<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class GoogleDriveNode implements NodeInterface
{
    private const FILE_FIELDS = 'id,name,mimeType,webViewLink,parents,size,createdTime,modifiedTime';
    private const PERMISSION_FIELDS = 'id,type,role,emailAddress,domain,allowFileDiscovery';
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
            'type' => 'google_drive', 'name' => 'Google Drive', 'category' => 'Community',
            'description' => 'Sube archivos, crea carpetas, busca y gestiona permisos con Drive API v3.',
            'icon' => 'Folder', 'is_start' => false,
            'help_text' => 'Credencial generic_bearer: API Key contiene un access token OAuth sin Bearer. Drive API habilitada; scope drive.file para archivos creados/abiertos por la aplicación y acceso al recurso. Buscar solo devuelve archivos autorizados; un token de lectura no puede escribir. El token caduca según expires_in; reemplazo manual, sin OAuth ni renovación automática. upload_file acepta SOLO content_base64 canónico de hasta 5.000.000 bytes decodificados (incluso cero), name y mime_type; no lee rutas ni descarga URLs. create_folder usa name y parent_id opcional. metadata admite JSON/objeto con description (vacío permitido). search_files usa q de Drive (predeterminado trashed = false), page_size 1..100 y page_token; una página por ejecución. create_permission exige file_id, permission_type y role; user/group requieren email_address, domain requiere domain. anyone solo reader y allow_public=true explícito; no cambia permisos implícitamente. get_shared_link solo consulta webViewLink: poseer enlace no concede acceso. Sin transferir propiedad ni reintentos. Los errores detienen el flujo; ante resultado incierto verifica Drive antes de repetir. Salida success/status_code/data (archivo, permission o listas files/permissions + pagination.next_page_token). Expresiones {{node_id.data.id}} resueltas por Engine. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'label' => 'Credencial OAuth Drive', 'required' => true],
                'operation' => ['type' => 'select', 'label' => 'Operación', 'required' => true, 'default' => 'search_files', 'options' => array_map(fn ($v) => ['value' => $v, 'label' => $v], ['upload_file', 'create_folder', 'search_files', 'create_permission', 'list_permissions', 'get_shared_link'])],
                'name' => ['type' => 'string', 'label' => 'Nombre (archivo/carpeta)', 'required' => false],
                'mime_type' => ['type' => 'string', 'label' => 'MIME del archivo', 'required' => false, 'placeholder' => 'text/plain'],
                'content_base64' => ['type' => 'textarea', 'label' => 'Contenido base64 (subir; máximo 5 MB)', 'required' => false],
                'parent_id' => ['type' => 'string', 'label' => 'ID carpeta padre (opcional)', 'required' => false],
                'metadata' => ['type' => 'textarea', 'label' => 'Metadatos JSON opcionales: description', 'required' => false, 'placeholder' => '{"description":"Documento"}'],
                'file_id' => ['type' => 'string', 'label' => 'ID archivo (permisos/enlace)', 'required' => false],
                'q' => ['type' => 'textarea', 'label' => 'Consulta Drive (buscar)', 'required' => false, 'placeholder' => "trashed = false and name contains 'informe'"],
                'page_size' => ['type' => 'number', 'label' => 'Tamaño página (1..100)', 'required' => false, 'default' => 100],
                'page_token' => ['type' => 'string', 'label' => 'Token siguiente página', 'required' => false],
                'permission_type' => ['type' => 'select', 'label' => 'Tipo de permiso', 'required' => false, 'default' => 'user', 'options' => array_map(fn ($v) => ['value' => $v, 'label' => $v], ['user', 'group', 'domain', 'anyone'])],
                'role' => ['type' => 'select', 'label' => 'Rol', 'required' => false, 'default' => 'reader', 'options' => [['value' => 'reader', 'label' => 'Lector'], ['value' => 'writer', 'label' => 'Editor']]],
                'email_address' => ['type' => 'string', 'label' => 'Email (user/group)', 'required' => false],
                'domain' => ['type' => 'string', 'label' => 'Dominio (domain)', 'required' => false],
                'allow_public' => ['type' => 'select', 'label' => 'Autorizar enlace público explícitamente', 'required' => false, 'default' => 'false', 'options' => [['value' => 'false', 'label' => 'No'], ['value' => 'true', 'label' => 'Sí, lector público por enlace']]]
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
        // Engine ya evalúa ExpressionEvaluator; no reinterpretar el contenido resuelto.
        $p = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($p['credential_id']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Drive: no se pudo recuperar o descifrar la credencial; revisa almacenamiento y gestor.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Google Drive: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', trim($token)) !== 1) {
            throw new InvalidArgumentException('Google Drive: usa generic_bearer con access token OAuth en API Key, sin prefijo Bearer.');
        }
        $token = trim($token);
        $options = $p['options'] + ['timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false];
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        $uncertain = $p['method'] !== 'GET' ? ' La escritura puede haber ocurrido; comprueba Drive antes de repetirla.' : '';
        try {
            $response = ($this->client ?? new Client())->request($p['method'], 'https://www.googleapis.com' . $p['path'], $options);
            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Drive: fallo de transporte o lectura; sin reintento automático.' . $uncertain);
        }
        if ($status < 200 || $status >= 300) {
            $reasons = [];
            foreach (is_array($body['error']['errors'] ?? null) ? $body['error']['errors'] : [] as $error) {
                if (is_array($error) && is_string($error['reason'] ?? null)) {
                    $reasons[] = $error['reason'];
                }
            }
            $reason = match (true) {
                $status === 401 => 'autenticación rechazada: token inválido, revocado o caducado',
                $status === 429 || ($status === 403 && array_intersect($reasons, ['rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded', 'quotaExceeded', 'storageQuotaExceeded']) !== []) => 'cuota o límite alcanzado',
                $status === 403 => 'permisos insuficientes, política de dominio o Drive API no habilitada',
                $status === 404 => 'recurso inexistente o inaccesible',
                $status === 400 => 'solicitud rechazada; revisa campos, consulta y token de página',
                $status >= 500 => 'error del servicio remoto',
                default => 'solicitud rechazada'
            };
            throw new RuntimeException("Google Drive: {$reason} (HTTP {$status})." . $uncertain, $status);
        }
        try {
            if (!is_array($body) || isset($body['error'])) {
                throw new InvalidArgumentException();
            }
            if ($p['operation'] === 'search_files' || $p['operation'] === 'list_permissions') {
                $key = $p['operation'] === 'search_files' ? 'files' : 'permissions';
                if (!isset($body[$key]) || !is_array($body[$key]) || !array_is_list($body[$key]) || count($body[$key]) > $p['page_size']) {
                    throw new InvalidArgumentException();
                }
                $items = [];
                foreach ($body[$key] as $item) {
                    $items[] = $this->safeResource($item, $key === 'permissions');
                }
                $next = $body['nextPageToken'] ?? null;
                if ($next !== null && (!is_string($next) || strlen($next) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $next))) {
                    throw new InvalidArgumentException();
                }
                $data = [$key => $items, 'pagination' => ['next_page_token' => $next, 'page_size' => $p['page_size']]];
                if ($key === 'files' && isset($body['incompleteSearch'])) {
                    if (!is_bool($body['incompleteSearch'])) {
                        throw new InvalidArgumentException();
                    }
                    $data['incomplete_search'] = $body['incompleteSearch'];
                }
            } else {
                $data = $this->safeResource($body, $p['operation'] === 'create_permission');
            }
            if (str_contains(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $token)) {
                throw new InvalidArgumentException();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Drive: respuesta inválida o incompleta.' . $uncertain, $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        $credentialId = $this->text($p, 'credential_id', true, 255);
        $operation = $p['operation'] ?? 'search_files';
        if (!is_string($operation) || !in_array($operation, ['upload_file', 'create_folder', 'search_files', 'create_permission', 'list_permissions', 'get_shared_link'], true)) {
            throw new InvalidArgumentException('Google Drive: operación inválida.');
        }
        $result = ['credential_id' => $credentialId, 'operation' => $operation, 'method' => 'GET', 'path' => '/drive/v3/files', 'options' => ['query' => ['fields' => self::FILE_FIELDS]]];
        if (in_array($operation, ['upload_file', 'create_folder'], true)) {
            $payload = ['name' => $this->text($p, 'name', true, 255)];
            $parent = $this->text($p, 'parent_id', false, 255);
            if ($parent !== '') {
                $payload['parents'] = [$this->id($parent)];
            }
            if (array_key_exists('metadata', $p) && $p['metadata'] !== '') {
                $metadata = $p['metadata'];
                if (is_string($metadata)) {
                    try {
                        $metadata = json_decode($metadata, false, 32, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {
                        throw new InvalidArgumentException('Google Drive: metadata requiere un objeto JSON válido.');
                    }
                }
                if ($metadata instanceof \stdClass) {
                    $metadata = get_object_vars($metadata);
                }
                if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata)) || array_diff(array_keys($metadata), ['description']) !== []) {
                    throw new InvalidArgumentException('Google Drive: metadata solo admite un objeto con description.');
                }
                if (array_key_exists('description', $metadata)) {
                    $payload['description'] = $this->text($metadata, 'description', false, 65536);
                }
            }
            $result['method'] = 'POST';
            if ($operation === 'create_folder') {
                $payload['mimeType'] = 'application/vnd.google-apps.folder';
                $result['options']['json'] = $payload;
            } else {
                $mime = $this->text($p, 'mime_type', true, 127);
                if (preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/D', $mime) !== 1 || str_starts_with($mime, 'application/vnd.google-apps.')) {
                    throw new InvalidArgumentException('Google Drive: MIME concreto inválido; no se admite conversión Workspace.');
                }
                $encoded = $p['content_base64'] ?? null;
                if (!is_string($encoded) || strlen($encoded) > 6666668 || ($bytes = base64_decode($encoded, true)) === false || base64_encode($bytes) !== $encoded || strlen($bytes) > 5000000) {
                    throw new InvalidArgumentException('Google Drive: content_base64 debe ser base64 canónico de máximo 5.000.000 bytes.');
                }
                $boundary = 'p8p_' . bin2hex(random_bytes(16));
                $payload['mimeType'] = $mime;
                $result['path'] = '/upload/drive/v3/files';
                $result['options']['query']['uploadType'] = 'multipart';
                $result['options']['headers'] = ['Content-Type' => 'multipart/related; boundary=' . $boundary];
                $result['options']['body'] = '--' . $boundary . "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . json_encode($payload, JSON_THROW_ON_ERROR) . "\r\n--" . $boundary . "\r\nContent-Type: " . $mime . "\r\n\r\n" . $bytes . "\r\n--" . $boundary . "--\r\n";
            }
        } elseif ($operation === 'search_files' || $operation === 'list_permissions') {
            $size = $p['page_size'] ?? 100;
            if (!(is_int($size) || (is_string($size) && preg_match('/^[0-9]{1,3}$/D', $size))) || (int) $size < 1 || (int) $size > 100) {
                throw new InvalidArgumentException('Google Drive: page_size debe ser entero de 1 a 100.');
            }
            $result['page_size'] = (int) $size;
            $query = ['pageSize' => (int) $size];
            $page = $this->text($p, 'page_token', false, 8192);
            if ($page !== '') {
                $query['pageToken'] = $page;
            }
            if ($operation === 'search_files') {
                $q = $this->text($p, 'q', false, 4096);
                $query += ['q' => $q === '' ? 'trashed = false' : $q, 'spaces' => 'drive', 'fields' => 'nextPageToken,incompleteSearch,files(' . self::FILE_FIELDS . ')'];
            } else {
                $result['path'] .= '/' . $this->id($this->text($p, 'file_id', true, 255)) . '/permissions';
                $query['fields'] = 'nextPageToken,permissions(' . self::PERMISSION_FIELDS . ')';
            }
            $result['options']['query'] = $query;
        } else {
            $result['path'] .= '/' . $this->id($this->text($p, 'file_id', true, 255));
            if ($operation === 'create_permission') {
                $type = $p['permission_type'] ?? 'user';
                $role = $p['role'] ?? 'reader';
                $public = $p['allow_public'] ?? false;
                if (!in_array($type, ['user', 'group', 'domain', 'anyone'], true) || !in_array($role, ['reader', 'writer'], true) || !in_array($public, [false, true, 'false', 'true'], true)) {
                    throw new InvalidArgumentException('Google Drive: tipo, rol o allow_public inválido.');
                }
                if ($type === 'anyone' && (!in_array($public, [true, 'true'], true) || $role !== 'reader')) {
                    throw new InvalidArgumentException('Google Drive: anyone exige reader y allow_public=true explícito.');
                }
                $payload = ['type' => $type, 'role' => $role];
                $query = ['fields' => self::PERMISSION_FIELDS];
                if (in_array($type, ['user', 'group'], true)) {
                    $email = $this->text($p, 'email_address', true, 254);
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new InvalidArgumentException('Google Drive: email_address inválido.');
                    }
                    $payload['emailAddress'] = $email;
                    $query['sendNotificationEmail'] = 'false';
                } elseif ($type === 'domain') {
                    $domain = $this->text($p, 'domain', true, 253);
                    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($domain, '.')) {
                        throw new InvalidArgumentException('Google Drive: dominio inválido.');
                    }
                    $payload['domain'] = $domain;
                    $payload['allowFileDiscovery'] = false;
                } else {
                    $payload['allowFileDiscovery'] = false;
                }
                $result['path'] .= '/permissions';
                $result['method'] = 'POST';
                $result['options'] = ['query' => $query, 'json' => $payload];
            }
        }
        return $result;
    }

    private function text(array $p, string $key, bool $required, int $max): string
    {
        $value = array_key_exists($key, $p) ? $p[$key] : '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) || ($required && trim($value) === '') || preg_match('/{{\s*[a-zA-Z0-9_.]+\s*}}/', $value)) {
            throw new InvalidArgumentException("Google Drive: campo {$key} inválido, ausente o expresión sin resolver.");
        }
        return $value;
    }

    private function id(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Google Drive: ID de recurso inválido.');
        }
        return $id;
    }

    private function safeResource(mixed $resource, bool $permission): array
    {
        if (!is_array($resource) || !is_string($resource['id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $resource['id']) !== 1) {
            throw new InvalidArgumentException();
        }
        $safe = ['id' => $resource['id']];
        $fields = explode(',', $permission ? self::PERMISSION_FIELDS : self::FILE_FIELDS);
        foreach ($fields as $key) {
            if (!array_key_exists($key, $resource) || $key === 'id') {
                continue;
            }
            $value = $resource[$key];
            if ($key === 'parents') {
                if (!is_array($value) || !array_is_list($value) || count($value) > 1) {
                    throw new InvalidArgumentException();
                }
                foreach ($value as $id) {
                    if (!is_string($id)) {
                        throw new InvalidArgumentException();
                    }
                    $this->id($id);
                }
            } elseif ($key === 'allowFileDiscovery') {
                if (!is_bool($value)) {
                    throw new InvalidArgumentException();
                }
            } elseif (!is_string($value) || strlen($value) > 8192 || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException();
            }
            if ($key === 'webViewLink') {
                $url = parse_url($value);
                if (!$url || ($url['scheme'] ?? null) !== 'https' || !in_array($url['host'] ?? '', ['drive.google.com', 'docs.google.com'], true) || isset($url['user']) || isset($url['pass'])) {
                    throw new InvalidArgumentException();
                }
            }
            $safe[$key] = $value;
        }
        return $safe;
    }
}
