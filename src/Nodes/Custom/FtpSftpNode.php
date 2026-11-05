<?php

namespace P8P\Nodes\Custom;

use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;

class FtpSftpNode implements NodeInterface
{
    private const MAX_BYTES = 5242880;

    public function __construct(private ?CredentialManager $credentials = null, private ?\Closure $transport = null, private ?\Closure $sftpFactory = null)
    {
    }

    public function getSchema(): array
    {
        return ['type' => 'ftp_sftp', 'name' => 'FTP / SFTP File Manager', 'category' => 'Community', 'icon' => 'Folder', 'is_start' => false,
            'description' => 'Listar, subir, descargar y eliminar archivos (máximo 5 MiB).',
            'help_text' => 'API Key JSON: protocol=ftp|ftps|sftp, host, port, username, password, base_path absoluto y server_chroot=true. FTP es explícitamente sin cifrado. FTPS usa TLS explícito y protege datos. SFTP requiere phpseclib3 y host_key_sha256 SHA256:base64 obtenido por canal confiable. Cuenta confinada en servidor obligatoria: los checks de enlaces no evitan carreras de servidor. Solo rutas relativas, sin enlaces; MLSD obligatorio FTP/FTPS. Entradas/salidas base64 sin acceso a archivos locales. No reintenta escrituras.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial de archivos'],
                'operation' => ['type' => 'select', 'label' => 'Operación', 'required' => true, 'default' => 'list', 'options' => array_map(fn($x) => ['value' => $x, 'label' => $x], ['list','upload','download','delete'])],
                'path' => ['type' => 'string', 'label' => 'Ruta relativa (vacío lista la base)', 'default' => ''],
                'content_base64' => ['type' => 'textarea', 'label' => 'Contenido base64 (upload)'],
                'limit' => ['type' => 'number', 'label' => 'Máximo de entradas listadas', 'default' => 100]
            ]];
    }

    private function path(mixed $path, bool $absolute = false): string
    {
        if (!is_string($path) || strlen($path) > 512 || preg_match('/[\\\\\x00-\x1f\x7f%?#]/', $path)
            || (!$absolute && str_starts_with($path, '/')) || ($absolute && !str_starts_with($path, '/'))) {
            throw new \InvalidArgumentException('Archivos: ruta inválida.');
        }
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '.' || $part === '..' || ($part !== '' && !preg_match('/^[\pL\pN_. -]+$/uD', $part))) {
                throw new \InvalidArgumentException('Archivos: ruta inválida.');
            }
        }
        return trim($path, '/');
    }

    public function validate(array $parameters): bool
    {
        try { $this->parameters($parameters); return true; } catch (\Throwable $e) { return false; }
    }

    private function parameters(array $p): array
    {
        $op = $p['operation'] ?? 'list';
        if (!is_string($p['credential_id'] ?? null) || trim($p['credential_id']) === '' || !in_array($op, ['list','upload','download','delete'], true)) { throw new \InvalidArgumentException('Archivos: credencial u operación inválida.'); }
        $path = $this->path($p['path'] ?? '');
        if ($op !== 'list' && $path === '') { throw new \InvalidArgumentException('Archivos: esta operación requiere un archivo.'); }
        $limit = filter_var($p['limit'] ?? 100, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) { throw new \InvalidArgumentException('Archivos: límite entre 1 y 1000.'); }
        $data = null;
        if ($op === 'upload') {
            $encoded = $p['content_base64'] ?? null;
            if (!is_string($encoded) || strlen($encoded) > 6990510 || ($data = base64_decode($encoded, true)) === false || strlen($data) > self::MAX_BYTES) { throw new \InvalidArgumentException('Archivos: base64 inválido o mayor a 5 MiB.'); }
        }
        return [$op, $path, $limit, $data];
    }

    public function execute(array $parameters, array $context): array
    {
        [$op, $path, $limit, $data] = $this->parameters($parameters);
        try { $r = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($parameters['credential_id']); }
        catch (\Throwable $e) { throw new \RuntimeException('Archivos: no se pudo recuperar la credencial.'); }
        try {
            $c = json_decode($r['data']['api_key'] ?? '', true, 32, JSON_THROW_ON_ERROR);
            if (($r['type'] ?? '') !== 'generic_bearer' || !is_array($c) || !in_array($c['protocol'] ?? '', ['ftp','ftps','sftp'], true)
                || !is_string($c['host'] ?? null) || !preg_match('/^[A-Za-z0-9.-]+$/D', $c['host']) || !is_string($c['username'] ?? null) || $c['username'] === ''
                || !is_string($c['password'] ?? null) || ($c['server_chroot'] ?? false) !== true
                || filter_var($c['port'] ?? ($c['protocol'] === 'sftp' ? 22 : 21), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) { throw new \Exception(); }
            $base = $this->path($c['base_path'] ?? null, true);
            if ($c['protocol'] === 'sftp' && (!is_string($c['host_key_sha256'] ?? null) || !preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/D', $c['host_key_sha256']))) { throw new \Exception(); }
        } catch (\Throwable $e) { throw new \InvalidArgumentException('Archivos: JSON de credencial inválido; exige protocolo, host, usuario/password, base_path, server_chroot=true y pin SFTP.'); }
        $full = '/' . trim($base . '/' . $path, '/');
        try {
            $result = $this->transport ? ($this->transport)($c, $op, $full, $data, $limit)
                : ($c['protocol'] === 'sftp' ? $this->sftp($c, $op, $full, $data, $limit) : $this->ftp($c, $op, $full, $data, $limit));
            if ($op === 'download') {
                if (!is_string($result) || strlen($result) > self::MAX_BYTES) { throw new \RuntimeException(); }
                return ['success' => true, 'operation' => $op, 'content_base64' => base64_encode($result), 'size' => strlen($result), 'filename' => basename($path)];
            }
            if ($op === 'list') {
                if (!is_array($result) || count($result) > 1000) { throw new \RuntimeException(); }
                return ['success' => true, 'operation' => $op, 'entries' => array_slice($result, 0, $limit), 'truncated' => count($result) > $limit];
            }
            if ($result !== true) { throw new \RuntimeException(); }
            return ['success' => true, 'operation' => $op, 'filename' => basename($path), 'size' => $op === 'upload' ? strlen($data) : null];
        } catch (\Throwable $e) { throw new \RuntimeException('Archivos: protocolo/dependencia no disponible, identidad, permisos, enlace, tamaño o transferencia inválida. Escritura potencialmente aplicada; no reintentar automáticamente.'); }
    }

    private function sftp(array $c, string $op, string $full, ?string $data, int $limit): mixed
    {
        if (!$this->sftpFactory && !class_exists(\phpseclib3\Net\SFTP::class)) { throw new \RuntimeException(); }
        $s = $this->sftpFactory ? ($this->sftpFactory)($c) : new \phpseclib3\Net\SFTP($c['host'], (int) ($c['port'] ?? 22), 10);
        try {
            $hostKey = $s->getServerPublicHostKey();
            $parts = is_string($hostKey) ? explode(' ', $hostKey) : [];
            $raw = isset($parts[1]) ? base64_decode($parts[1], true) : false;
            if ($raw === false || !hash_equals(rtrim($c['host_key_sha256'], '='), 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw, true)), '='))) { throw new \RuntimeException(); }
            if (!$s->login($c['username'], $c['password'])) { throw new \RuntimeException(); }
            $s->setTimeout(30);
            $current = '';
            foreach (explode('/', trim($full, '/')) as $part) {
                if ($part === '') { continue; }
                $current .= '/' . $part;
                $stat = $s->lstat($current);
                if ($stat === false && $current === $full && $op === 'upload') { continue; }
                if (!is_array($stat) || ($stat['type'] ?? 0) === 3 || (!in_array($stat['type'] ?? 0, [1,2], true))) { throw new \RuntimeException(); }
                if ($current !== $full && $stat['type'] !== 2) { throw new \RuntimeException(); }
                if ($current === $full && (($op === 'list' && $stat['type'] !== 2) || ($op !== 'list' && $stat['type'] !== 1))) { throw new \RuntimeException(); }
            }
            if ($op === 'list') {
                $entries = $s->rawlist($full, false);
                if (!is_array($entries) || count($entries) > 1002) { throw new \RuntimeException(); }
                $out = [];
                foreach ($entries as $name => $stat) {
                    if (in_array($name, ['.','..'], true)) { continue; }
                    if (!in_array($stat['type'] ?? 0, [1,2], true)) { continue; }
                    $out[] = ['name' => $name, 'type' => $stat['type'] === 2 ? 'directory' : 'file', 'size' => (int) ($stat['size'] ?? 0)];
                }
                return $out;
            }
            if ($op === 'download') {
                $size = $s->size($full);
                if ($size === false || $size > self::MAX_BYTES) { throw new \RuntimeException(); }
                $result = '';
                $ok = $s->get($full, function ($chunk) use (&$result) { if (strlen($result) + strlen($chunk) > self::MAX_BYTES) { throw new \RuntimeException(); } $result .= $chunk; }, 0, self::MAX_BYTES + 1);
                if (!$ok) { throw new \RuntimeException(); }
                return $result;
            }
            // SOURCE_STRING is 0 in phpseclib3; omit the optional argument to use its default.
            return $op === 'upload' ? $s->put($full, $data) : $s->delete($full, false);
        } finally { $s->disconnect(); }
    }

    private function ftp(array $c, string $op, string $full, ?string $data, int $limit): mixed
    {
        if (!function_exists('curl_init') || !in_array('ftp', curl_version()['protocols'], true)) { throw new \RuntimeException(); }
        // Inspect each component from the account root. MLSD types other than ordinary file/dir are refused.
        $parts = explode('/', trim($full, '/'));
        $parent = '/';
        foreach ($parts as $i => $part) {
            if ($part === '') { continue; }
            $entries = $this->ftpList($c, $parent);
            $found = null;
            foreach ($entries as $entry) { if ($entry['name'] === $part) { $found = $entry; break; } }
            $last = $i === count($parts) - 1;
            if ($found === null && $last && $op === 'upload') { break; }
            if (!$found || !in_array($found['type'], ['file','directory'], true) || (!$last && $found['type'] !== 'directory')) { throw new \RuntimeException(); }
            if ($last && (($op === 'list' && $found['type'] !== 'directory') || ($op !== 'list' && $found['type'] !== 'file'))) { throw new \RuntimeException(); }
            $parent = rtrim($parent, '/') . '/' . $part;
        }
        if ($op === 'list') { return $this->ftpList($c, $full); }
        return $this->curl($c, $op, $full, $data);
    }

    private function ftpList(array $c, string $path): array
    {
        $raw = $this->curl($c, 'list', rtrim($path, '/') . '/', null);
        $out = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if ($line === '') { continue; }
            if (!preg_match('/^((?:[^; ]+;)+) (.+)$/D', $line, $m) || !preg_match('/(?:^|;)type=([^;]+);/i', $m[1], $t)) { throw new \RuntimeException(); }
            $type = strtolower($t[1]);
            if (in_array($type, ['cdir','pdir'], true)) { continue; }
            preg_match('/(?:^|;)size=(\d+);/i', $m[1], $size);
            $out[] = ['name' => $m[2], 'type' => match ($type) { 'file' => 'file', 'dir' => 'directory', default => 'unsupported' }, 'size' => (int) ($size[1] ?? 0)];
            if (count($out) > 1000) { throw new \RuntimeException(); }
        }
        return $out;
    }

    private function curl(array $c, string $op, string $path, ?string $data): mixed
    {
        $h = curl_init();
        $stream = null;
        $result = '';
        $urlPath = $op === 'delete' ? rtrim(dirname($path), '/') . '/' : $path;
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $urlPath)));
        try {
            $options = [CURLOPT_URL => 'ftp://' . $c['host'] . ':' . ($c['port'] ?? 21) . '/' . $encodedPath,
                CURLOPT_USERNAME => $c['username'], CURLOPT_PASSWORD => $c['password'], CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_FTP, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USE_SSL => $c['protocol'] === 'ftps' ? CURLUSESSL_ALL : CURLUSESSL_NONE,
                CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$result) { if (strlen($result) + strlen($chunk) > self::MAX_BYTES) { return 0; } $result .= $chunk; return strlen($chunk); }];
            if ($op === 'list') { $options[CURLOPT_CUSTOMREQUEST] = 'MLSD'; }
            if ($op === 'delete') { $options[CURLOPT_QUOTE] = ['DELE ' . $path]; $options[CURLOPT_NOBODY] = true; }
            if ($op === 'upload') {
                $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
                fwrite($stream, $data); rewind($stream);
                $options[CURLOPT_UPLOAD] = true; $options[CURLOPT_INFILE] = $stream; $options[CURLOPT_INFILESIZE] = strlen($data);
            }
            curl_setopt_array($h, $options);
            if (curl_exec($h) === false) { throw new \RuntimeException(); }
            return in_array($op, ['upload','delete'], true) ? true : $result;
        } finally { curl_close($h); if (is_resource($stream)) { fclose($stream); } }
    }
}
