<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class GitHubNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'github', 'name' => 'GitHub', 'category' => 'Community', 'icon' => 'Github', 'is_start' => false,
            'description' => 'Crea issues, comenta pull requests y obtiene contenido de GitHub.com.',
            'help_text' => 'Credencial generic_bearer: API Key = PAT, sin Bearer. Preferir PAT fine-grained con acceso al repositorio: Issues write para crear, Pull requests write (o Issues write) para comentar y Contents read para leer. La organización puede exigir aprobación/SSO y caducidad; reemplazar el token manualmente. API 2026-03-10. El comentario es general, no una revisión de líneas. labels/assignees: arrays JSON; omitir o dejar texto vacío para no enviarlos, [] para vaciar explícitamente. Contenido: máximo 1 MiB; directorios hasta 1000 entradas, sin paginación ni recursión. Sin reintentos ni garantía contra duplicados. Expresiones {{node_id.data.id}} resueltas por Engine. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial PAT'],
                'operation' => ['type' => 'select', 'default' => 'create_issue', 'label' => 'Operación', 'options' => [
                    ['value' => 'create_issue', 'label' => 'Crear issue'], ['value' => 'comment_pull_request', 'label' => 'Comentar pull request'], ['value' => 'get_content', 'label' => 'Obtener contenido']]],
                'owner' => ['type' => 'string', 'required' => true, 'label' => 'Propietario'],
                'repo' => ['type' => 'string', 'required' => true, 'label' => 'Repositorio'],
                'title' => ['type' => 'string', 'label' => 'Título (crear issue)'],
                'body' => ['type' => 'textarea', 'label' => 'Texto Markdown (obligatorio al comentar)'],
                'labels' => ['type' => 'textarea', 'label' => 'Etiquetas JSON (opcional)', 'placeholder' => '["bug"]'],
                'assignees' => ['type' => 'textarea', 'label' => 'Usuarios asignados JSON (opcional)', 'placeholder' => '["octocat"]'],
                'pull_number' => ['type' => 'number', 'label' => 'Número de pull request'],
                'path' => ['type' => 'string', 'label' => 'Ruta relativa (vacío: raíz)'],
                'ref' => ['type' => 'string', 'label' => 'Rama, etiqueta o SHA (opcional)']
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
        // Engine aplica ExpressionEvaluator una sola vez, preservando valores resueltos.
        $p = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($p['credential_id']);
        } catch (\Throwable $e) {
            throw new RuntimeException('GitHub: no se pudo recuperar o descifrar la credencial; revisa el gestor y su almacenamiento.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('GitHub: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || preg_match('/^[A-Za-z0-9_]{16,512}$/D', $token) !== 1) {
            throw new InvalidArgumentException('GitHub: selecciona generic_bearer con un PAT válido en API Key, sin Bearer.');
        }
        $options = ['headers' => ['Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/vnd.github+json', 'User-Agent' => 'P8P-GitHub', 'X-GitHub-Api-Version' => '2026-03-10'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false];
        if ($p['method'] === 'POST') {
            $options['json'] = $p['payload'];
        } elseif ($p['ref'] !== '') {
            $options['query'] = ['ref' => $p['ref']];
        }
        $uncertain = $p['method'] === 'POST' ? ' La escritura puede haberse realizado; comprueba GitHub antes de repetirla.' : '';
        try {
            $response = ($this->client ?? new Client())->request($p['method'], 'https://api.github.com' . $p['endpoint'], $options);
            $status = $response->getStatusCode();
            // Limitar lectura además de cantidad de entradas/archivo; no descargar URLs devueltas.
            $raw = $response->getBody()->read(4 * 1024 * 1024 + 1);
        } catch (\Throwable $e) {
            throw new RuntimeException('GitHub: fallo de transporte o lectura.' . $uncertain);
        }
        if ($status < 200 || $status >= 300) {
            $reason = match (true) {
                $status === 401 => 'autenticación rechazada; token expirado, revocado o inválido',
                $status === 429 || ($status === 403 && $response->getHeaderLine('X-RateLimit-Remaining') === '0') => 'cuota o límite de solicitudes alcanzado',
                $status === 403 => 'permisos insuficientes, aprobación pendiente o límite secundario; revisa los permisos y el acceso al repositorio',
                $status === 404 => 'repositorio o recurso inexistente o inaccesible',
                $status === 422 => 'datos rechazados o restricción antispam',
                $status >= 500 => 'error del servicio',
                default => 'solicitud rechazada'
            };
            throw new RuntimeException("GitHub: {$reason} (HTTP {$status})." . $uncertain, $status);
        }
        $body = json_decode($raw, true);
        try {
            if (strlen($raw) > 4 * 1024 * 1024 || json_last_error() !== JSON_ERROR_NONE || !is_array($body) || isset($body['message'])
                || ($p['method'] === 'GET' && array_is_list($body) && !str_starts_with(ltrim($raw), '['))) {
                throw new InvalidArgumentException();
            }
            $data = $p['method'] === 'POST' ? $this->writeResult($body, $p['operation']) : $this->contentResult($body);
            if (str_contains(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $token)) {
                throw new InvalidArgumentException();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('GitHub: respuesta inválida, incompleta o fuera de los límites admitidos.' . $uncertain, $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        $required = function (string $key, int $max = 1000) use ($p): string {
            $v = $p[$key] ?? null;
            if (!is_string($v) || trim($v) === '' || strlen($v) > $max || !mb_check_encoding($v, 'UTF-8')
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|{{\s*[A-Za-z0-9_.]+\s*}}/', $v)) {
                throw new InvalidArgumentException("GitHub: campo {$key} inválido o expresión sin resolver.");
            }
            return $v;
        };
        $id = $required('credential_id');
        $owner = $required('owner', 100);
        $repo = $required('repo', 100);
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $owner)
            || !preg_match('/^[A-Za-z0-9_.-]+$/D', $repo) || in_array($repo, ['.', '..'], true)) {
            throw new InvalidArgumentException('GitHub: propietario o repositorio inválido.');
        }
        $operation = $p['operation'] ?? 'create_issue';
        $base = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
        $payload = [];
        $ref = '';
        switch ($operation) {
            case 'create_issue':
                $payload['title'] = $required('title', 256);
                if (array_key_exists('body', $p)) {
                    $payload['body'] = $this->optionalText($p['body'], 65536);
                }
                foreach (['labels', 'assignees'] as $key) {
                    if (!array_key_exists($key, $p) || $p[$key] === '') {
                        continue;
                    }
                    $list = is_string($p[$key]) ? json_decode($p[$key]) : $p[$key];
                    if (!is_array($list) || !array_is_list($list) || count($list) > 100) {
                        throw new InvalidArgumentException("GitHub: {$key} debe ser un array JSON de hasta 100 textos.");
                    }
                    foreach ($list as $item) {
                        if (!is_string($item) || trim($item) === '' || strlen($item) > 100 || !mb_check_encoding($item, 'UTF-8')
                            || preg_match('/[\x00-\x1f\x7f]|{{\s*[A-Za-z0-9_.]+\s*}}/', $item)) {
                            throw new InvalidArgumentException("GitHub: {$key} contiene un texto inválido.");
                        }
                    }
                    $payload[$key] = $list;
                }
                $endpoint = $base . '/issues';
                $method = 'POST';
                break;
            case 'comment_pull_request':
                $number = $p['pull_number'] ?? null;
                if (!(is_int($number) || is_string($number)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $number)) {
                    throw new InvalidArgumentException('GitHub: pull_number debe ser un entero positivo.');
                }
                $payload['body'] = $required('body', 65536);
                $endpoint = $base . '/issues/' . $number . '/comments';
                $method = 'POST';
                break;
            case 'get_content':
                $path = $this->optionalText($p['path'] ?? '', 2048);
                if (str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)
                    || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
                    throw new InvalidArgumentException('GitHub: indica una ruta relativa sin segmentos . o ...');
                }
                $ref = $this->optionalText($p['ref'] ?? '', 1024);
                if (preg_match('/[\x00-\x1f\x7f]/', $ref)) {
                    throw new InvalidArgumentException('GitHub: ref inválido.');
                }
                $endpoint = $base . '/contents' . ($path === '' ? '' : '/' . implode('/', array_map('rawurlencode', explode('/', $path))));
                $method = 'GET';
                break;
            default:
                throw new InvalidArgumentException('GitHub: operación no admitida.');
        }
        return ['credential_id' => $id, 'operation' => $operation, 'endpoint' => $endpoint, 'method' => $method, 'payload' => $payload, 'ref' => $ref];
    }

    private function optionalText(mixed $value, int $max): string
    {
        if (!is_string($value) || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")
            || preg_match('/{{\s*[A-Za-z0-9_.]+\s*}}/', $value)) {
            throw new InvalidArgumentException('GitHub: texto inválido, demasiado largo o expresión sin resolver.');
        }
        return $value;
    }

    private function writeResult(array $body, string $operation): array
    {
        if (!is_int($body['id'] ?? null) || $body['id'] < 1 || !is_string($body['html_url'] ?? null)
            || !preg_match('~^https://github\.com/[^\s]+$~D', $body['html_url'])) {
            throw new InvalidArgumentException();
        }
        $data = ['id' => $body['id'], 'html_url' => $body['html_url']];
        if ($operation === 'create_issue') {
            if (!is_int($body['number'] ?? null) || $body['number'] < 1) {
                throw new InvalidArgumentException();
            }
            $data['number'] = $body['number'];
        }
        return $data;
    }

    private function contentResult(array $body): array
    {
        if (array_is_list($body)) {
            if (count($body) > 1000) {
                throw new InvalidArgumentException();
            }
            $items = [];
            foreach ($body as $item) {
                $items[] = $this->contentMetadata($item);
            }
            return ['type' => 'dir', 'entries' => $items, 'pagination' => ['supported' => false, 'limit' => 1000,
                'count' => count($items), 'possibly_truncated' => count($items) === 1000]];
        }
        $data = $this->contentMetadata($body);
        if ($data['type'] === 'file') {
            if ($data['size'] > 1048576 || ($body['encoding'] ?? null) !== 'base64' || !is_string($body['content'] ?? null)) {
                throw new InvalidArgumentException();
            }
            $decoded = base64_decode(str_replace(["\n", "\r"], '', $body['content']), true);
            if ($decoded === false || strlen($decoded) !== $data['size'] || strlen($decoded) > 1048576) {
                throw new InvalidArgumentException();
            }
            $data['encoding'] = 'base64';
            $data['content'] = base64_encode($decoded);
            if (mb_check_encoding($decoded, 'UTF-8')) {
                $data['text'] = $decoded;
            }
        }
        return $data;
    }

    private function contentMetadata(mixed $item): array
    {
        if (!is_array($item) || !in_array($item['type'] ?? null, ['file', 'dir', 'symlink', 'submodule'], true)
            || !is_string($item['name'] ?? null) || !is_string($item['path'] ?? null)
            || !is_string($item['sha'] ?? null) || !preg_match('/^[a-f0-9]{40,64}$/D', $item['sha'])
            || !is_int($item['size'] ?? null) || $item['size'] < 0) {
            throw new InvalidArgumentException();
        }
        return array_intersect_key($item, array_flip(['type', 'name', 'path', 'sha', 'size']));
    }
}
