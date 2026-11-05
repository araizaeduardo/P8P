<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class AwsS3Node implements NodeInterface
{
    private const MAX_BYTES = 5242880;

    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentialManager = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'aws_s3', 'name' => 'AWS S3', 'category' => 'Community',
            'description' => 'Sube, descarga, elimina y lista objetos privados de S3 con Signature V4.',
            'icon' => 'Cloud', 'is_start' => false,
            'help_text' => 'Credencial generic_bearer: API Key contiene JSON con access_key_id, secret_access_key y session_token opcional. Región y bucket separados. Objetos hasta 5 MiB; subida texto UTF-8 o base64, descarga base64. Una página por ejecución. No cambia ACL ni políticas del bucket. Sin renovación de credenciales. Solo buckets generales AWS; sin S3 Express, endpoints personalizados ni multipart.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial AWS (JSON en API Key)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'list_objects', 'options' => [
                    ['value' => 'upload_object', 'label' => 'Subir objeto'],
                    ['value' => 'download_object', 'label' => 'Descargar objeto'],
                    ['value' => 'delete_object', 'label' => 'Eliminar objeto'],
                    ['value' => 'list_objects', 'label' => 'Listar objetos (una página)']
                ]],
                'bucket' => ['type' => 'string', 'required' => true, 'label' => 'Bucket'],
                'region' => ['type' => 'string', 'required' => true, 'label' => 'Región AWS', 'default' => 'us-east-1'],
                'object_key' => ['type' => 'string', 'required' => false, 'label' => 'Clave exacta del objeto (subir/descargar/eliminar)'],
                'content' => ['type' => 'textarea', 'required' => false, 'label' => 'Contenido para subir (vacío crea un archivo vacío)'],
                'content_encoding' => ['type' => 'select', 'required' => false, 'label' => 'Formato del contenido', 'default' => 'text', 'options' => [
                    ['value' => 'text', 'label' => 'Texto UTF-8'], ['value' => 'base64', 'label' => 'Base64 estricto']
                ]],
                'content_type' => ['type' => 'string', 'required' => false, 'label' => 'Tipo MIME al subir', 'default' => 'application/octet-stream'],
                'overwrite' => ['type' => 'select', 'required' => false, 'label' => 'Reemplazar objeto existente', 'default' => 'no', 'options' => [
                    ['value' => 'no', 'label' => 'No (If-None-Match: *)'], ['value' => 'yes', 'label' => 'Sí']
                ]],
                'prefix' => ['type' => 'string', 'required' => false, 'label' => 'Prefijo (listado)'],
                'delimiter' => ['type' => 'string', 'required' => false, 'label' => 'Delimitador (listado)'],
                'continuation_token' => ['type' => 'string', 'required' => false, 'label' => 'Cursor de siguiente página (listado)'],
                'max_keys' => ['type' => 'number', 'required' => false, 'label' => 'Máximo de objetos por página (1–1000)', 'default' => 100]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->normalize($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine has already resolved expressions, including whole-value typed expressions.
        $p = $this->normalize($parameters);
        $credentials = $this->credentials($p['credential_id']);
        $op = $p['operation'];
        if ($op === 'list_objects' && !function_exists('simplexml_load_string')) {
            throw new RuntimeException('AWS S3: el listado requiere la extensión PHP SimpleXML.');
        }
        $host = 's3.' . $p['region'] . '.amazonaws.com' . (str_starts_with($p['region'], 'cn-') ? '.cn' : '');
        // Path-style supports dotted bucket names without weakening certificate verification.
        $path = '/' . $p['bucket'] . '/';
        if ($op !== 'list_objects') {
            $path .= implode('/', array_map('rawurlencode', explode('/', $p['object_key'])));
        }
        $query = [];
        if ($op === 'list_objects') {
            $query = ['list-type' => '2', 'encoding-type' => 'url', 'max-keys' => (string) $p['max_keys']];
            foreach (['prefix', 'delimiter', 'continuation_token'] as $name) {
                if (($p[$name] ?? '') !== '') {
                    $query[str_replace('_', '-', $name)] = $p[$name];
                }
            }
        }
        $queryString = $this->canonicalQuery($query);
        $method = match ($op) { 'upload_object' => 'PUT', 'delete_object' => 'DELETE', default => 'GET' };
        $body = $p['body'] ?? '';
        $headers = ['host' => $host, 'x-amz-date' => gmdate('Ymd\THis\Z'), 'x-amz-content-sha256' => hash('sha256', $body)];
        if (isset($credentials['session_token'])) {
            $headers['x-amz-security-token'] = $credentials['session_token'];
        }
        if ($op === 'upload_object') {
            $headers['content-type'] = $p['content_type'];
            if ($p['overwrite'] === 'no') {
                $headers['if-none-match'] = '*';
            }
        }
        $headers['authorization'] = $this->authorization($method, $path, $queryString, $headers, hash('sha256', $body), $p['region'], $credentials);
        $options = [
            'headers' => $headers, 'body' => $body, 'timeout' => 30, 'connect_timeout' => 10,
            'verify' => true, 'http_errors' => false, 'allow_redirects' => false,
            'stream' => true, 'decode_content' => false,
            'on_headers' => static function ($response): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && (!ctype_digit($length) || (float) $length > self::MAX_BYTES)) {
                    throw new RuntimeException('AWS S3: respuesta excede el límite de 5 MiB.');
                }
            },
            'progress' => static function ($total, $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new RuntimeException('AWS S3: respuesta excede el límite de 5 MiB.');
                }
            }
        ];
        // libcurl otherwise removes dot segments, which would sign a different S3 key.
        if (!extension_loaded('curl')) {
            throw new RuntimeException('AWS S3: se requiere la extensión PHP cURL.');
        }
        if (defined('CURLOPT_PATH_AS_IS')) {
            $options['curl'] = [CURLOPT_PATH_AS_IS => true];
        } elseif (preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $p['object_key'] ?? '')) {
            throw new RuntimeException('AWS S3: claves con segmentos punto requieren cURL con PATH_AS_IS.');
        }
        $write = in_array($method, ['PUT', 'DELETE'], true);
        $uncertain = $write ? ' Resultado de escritura incierto; comprueba S3 antes de repetir.' : '';
        try {
            $response = ($this->client ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]))->request($method, 'https://' . $host . $path . ($queryString === '' ? '' : '?' . $queryString), $options);
        } catch (\Throwable $e) {
            throw new RuntimeException('AWS S3: fallo de transporte o límite de respuesta; sin reintentos.' . $uncertain);
        }
        $status = $response->getStatusCode();
        if ($status !== ($op === 'delete_object' ? 204 : 200)) {
            $response->getBody()->close();
            $reason = match ($status) {
                400 => 'solicitud o configuración inválida', 401 => 'autenticación rechazada',
                403 => 'autenticación o permisos rechazados (también puede ocultar un objeto inexistente)',
                404 => 'bucket u objeto inexistente', 409, 412 => 'conflicto o precondición rechazada',
                429, 503 => 'cuota o servicio temporalmente no disponible',
                301, 302, 307, 308 => 'región o endpoint incorrecto; redirección bloqueada',
                default => 'respuesta remota inesperada'
            };
            throw new RuntimeException('AWS S3: HTTP ' . $status . ', ' . $reason . '.' . ($write && $status >= 500 ? $uncertain : ''));
        }
        try {
            $content = '';
            $stream = $response->getBody();
            while (!$stream->eof()) {
                $chunk = $stream->read(min(65536, self::MAX_BYTES + 1 - strlen($content)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Lectura incompleta.');
                }
                $content .= $chunk;
                if (strlen($content) > self::MAX_BYTES) {
                    throw new RuntimeException('Límite excedido.');
                }
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('AWS S3: lectura incompleta o respuesta superior a 5 MiB.' . $uncertain);
        } finally {
            $response->getBody()->close();
        }
        $data = ['bucket' => $p['bucket']];
        if ($op === 'list_objects') {
            $data += $this->parseList($content);
        } else {
            $data['object_key'] = $p['object_key'];
            $data['etag'] = $response->getHeaderLine('ETag');
            $data['version_id'] = $response->getHeaderLine('x-amz-version-id');
            if ($op === 'download_object') {
                $data += ['content_base64' => base64_encode($content), 'size_bytes' => strlen($content), 'content_type' => $response->getHeaderLine('Content-Type')];
            } elseif ($content !== '') {
                throw new RuntimeException('AWS S3: respuesta de escritura inesperada.' . $uncertain);
            }
            if ($op === 'delete_object') {
                $data['delete_marker'] = $response->getHeaderLine('x-amz-delete-marker') === 'true';
            }
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function normalize(array $p): array
    {
        $p['region'] ??= 'us-east-1';
        $bad = static function (): never { throw new InvalidArgumentException('AWS S3: parámetros inválidos; revisa operación, bucket, región, clave, contenido y límites.'); };
        foreach (['credential_id', 'bucket', 'region'] as $key) {
            if (!isset($p[$key]) || !is_string($p[$key]) || trim($p[$key]) === '') {
                $bad();
            }
        }
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $p['bucket']) || str_contains($p['bucket'], '..')
            || filter_var($p['bucket'], FILTER_VALIDATE_IP) || str_starts_with($p['bucket'], 'xn--')
            || preg_match('/(?:-s3alias|--ol-s3|\.mrap|--x-s3|--table-s3)$/D', $p['bucket'])
            || !preg_match('/^[a-z]{2}(?:-[a-z]+)+-\d+$/D', $p['region'])) {
            $bad();
        }
        $p['operation'] ??= 'list_objects';
        if (!in_array($p['operation'], ['upload_object', 'download_object', 'delete_object', 'list_objects'], true)) {
            $bad();
        }
        if ($p['operation'] !== 'list_objects') {
            if (!isset($p['object_key']) || !is_string($p['object_key']) || $p['object_key'] === '' || strlen($p['object_key']) > 1024 || preg_match('//u', $p['object_key']) !== 1) {
                $bad();
            }
        }
        if ($p['operation'] === 'upload_object') {
            $p['content_encoding'] ??= 'text';
            $p['content_type'] ??= 'application/octet-stream';
            $p['overwrite'] ??= 'no';
            if (!isset($p['content']) || !is_string($p['content']) || !in_array($p['content_encoding'], ['text', 'base64'], true)
                || !in_array($p['overwrite'], ['no', 'yes'], true) || !is_string($p['content_type'])
                || strlen($p['content_type']) > 255 || !preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~D', $p['content_type'])
                || strlen($p['content']) > 4 * (int) ceil(self::MAX_BYTES / 3)) {
                $bad();
            }
            $p['body'] = $p['content_encoding'] === 'base64' ? base64_decode($p['content'], true) : $p['content'];
            if ($p['body'] === false || strlen($p['body']) > self::MAX_BYTES
                || ($p['content_encoding'] === 'text' && preg_match('//u', $p['body']) !== 1)
                || ($p['content_encoding'] === 'base64' && base64_encode($p['body']) !== $p['content'])) {
                $bad();
            }
        }
        if ($p['operation'] === 'list_objects') {
            $limit = $p['max_keys'] ?? 100;
            if ((!is_int($limit) && !(is_string($limit) && ctype_digit($limit))) || (int) $limit < 1 || (int) $limit > 1000) {
                $bad();
            }
            $p['max_keys'] = (int) $limit;
            foreach (['prefix', 'delimiter', 'continuation_token'] as $key) {
                if (array_key_exists($key, $p) && (!is_string($p[$key]) || strlen($p[$key]) > ($key === 'continuation_token' ? 16384 : 1024) || preg_match('//u', $p[$key]) !== 1)) {
                    $bad();
                }
            }
        }
        return $p;
    }

    private function credentials(string $id): array
    {
        try {
            $credential = ($this->credentialManager ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('AWS S3: fallo del gestor de credenciales (almacenamiento o descifrado), anterior a contactar AWS.');
        }
        if ($credential === null) {
            throw new RuntimeException('AWS S3: credencial inexistente.');
        }
        if (($credential['type'] ?? null) !== 'generic_bearer') {
            throw new RuntimeException('AWS S3: se requiere credencial generic_bearer.');
        }
        $raw = $credential['data']['api_key'] ?? null;
        $config = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($config) || array_is_list($config)) {
            throw new RuntimeException('AWS S3: API Key debe contener un objeto JSON de credenciales AWS.');
        }
        foreach (['access_key_id', 'secret_access_key', 'session_token'] as $key) {
            if ($key === 'session_token' && !array_key_exists($key, $config)) {
                continue;
            }
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '' || strlen($config[$key]) > 16384 || preg_match('/[\x00-\x20\x7f]/', $config[$key])) {
                throw new RuntimeException('AWS S3: configuración AWS incompleta o inválida en la credencial.');
            }
        }
        if (!preg_match('/^[A-Z0-9]{16,128}$/D', $config['access_key_id'])) {
            throw new RuntimeException('AWS S3: identificador de acceso inválido en la credencial.');
        }
        return $config;
    }

    private function canonicalQuery(array $query): string
    {
        $encoded = [];
        foreach ($query as $key => $value) {
            $encoded[rawurlencode($key)] = rawurlencode($value);
        }
        ksort($encoded, SORT_STRING);
        $pairs = [];
        foreach ($encoded as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        return implode('&', $pairs);
    }

    // AWS official single-chunk SigV4 algorithm; URI is already encoded once, never normalized.
    private function authorization(string $method, string $path, string $query, array $headers, string $payloadHash, string $region, array $credentials): string
    {
        return \P8P\Core\AwsSignatureV4::authorization($method, $path, $query, $headers, $payloadHash, $region, $credentials);
    }

    private function parseList(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
                throw new RuntimeException('AWS S3: respuesta XML no permitida.');
            }
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
            if ($xml === false || $xml->getName() !== 'ListBucketResult' || !in_array((string) $xml->IsTruncated, ['true', 'false'], true)) {
                throw new RuntimeException('AWS S3: listado XML inválido.');
            }
            $truncated = (string) $xml->IsTruncated === 'true';
            if ($truncated && (string) $xml->NextContinuationToken === '') {
                throw new RuntimeException('AWS S3: falta cursor en listado truncado.');
            }
            $objects = [];
            foreach ($xml->Contents as $object) {
                if (!ctype_digit((string) $object->Size) || !isset($object->Key)) {
                    throw new RuntimeException('AWS S3: objeto inválido en listado.');
                }
                $objects[] = ['key' => rawurldecode((string) $object->Key), 'size_bytes' => (int) $object->Size, 'etag' => (string) $object->ETag, 'last_modified' => (string) $object->LastModified, 'storage_class' => (string) $object->StorageClass];
            }
            $prefixes = [];
            foreach ($xml->CommonPrefixes as $prefix) {
                $prefixes[] = rawurldecode((string) $prefix->Prefix);
            }
            return ['objects' => $objects, 'common_prefixes' => $prefixes, 'is_truncated' => $truncated, 'next_continuation_token' => $truncated ? (string) $xml->NextContinuationToken : null];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
