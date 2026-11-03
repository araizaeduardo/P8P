<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use stdClass;

class GoogleDocsNode implements NodeInterface
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
            'type' => 'google_docs', 'name' => 'Google Docs', 'category' => 'Community',
            'icon' => 'FileText', 'is_start' => false,
            'description' => 'Crea, lee, reemplaza texto o copia una plantilla sin modificar el original.',
            'help_text' => 'Usa generic_bearer con access token OAuth en API Key sin prefijo Bearer. Docs API habilitada; scope documents para crear/escribir, documents.readonly para leer, o drive.file para archivos autorizados a la aplicación. Copiar plantilla requiere Drive API y scope drive.file (acceso a plantilla y carpeta) o drive. copy_template solo crea una copia: usa data.document_id en otro nodo replace_text para rellenarla. replace_text modifica explícitamente document_id existente; reemplazos literales secuenciales, admite replace vacío para borrar. Sin tab_ids aplica a TODAS las pestañas. get_document devuelve tabs con includeTabsContent=true. required_revision_id opcional protege contra ediciones concurrentes. Tokens caducan, renovación manual. Sin redirecciones ni reintentos automáticos; verifica Drive antes de repetir escrituras inciertas. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial OAuth Google'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'create_document', 'options' => [
                    ['value' => 'create_document', 'label' => 'Crear documento vacío'], ['value' => 'get_document', 'label' => 'Leer documento y pestañas'],
                    ['value' => 'replace_text', 'label' => 'Reemplazar texto en documento existente'], ['value' => 'copy_template', 'label' => 'Crear copia de plantilla (Drive API)']
                ]],
                'title' => ['type' => 'string', 'label' => 'Crear/copiar: título'],
                'document_id' => ['type' => 'string', 'label' => 'Leer/reemplazar: ID del documento existente'],
                'template_id' => ['type' => 'string', 'label' => 'Copiar: ID de plantilla original'],
                'parent_folder_id' => ['type' => 'string', 'label' => 'Copiar: carpeta destino (opcional; hereda permisos)'],
                'replacements' => ['type' => 'textarea', 'label' => 'Reemplazar: lista JSON de {find,replace,match_case?}', 'placeholder' => '[{"find":"[NOMBRE]","replace":"Ana","match_case":true}]'],
                'tab_ids' => ['type' => 'textarea', 'label' => 'Reemplazar: lista JSON de pestañas; omitir para todas'],
                'required_revision_id' => ['type' => 'string', 'label' => 'Reemplazar: revisión esperada (opcional)']
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
        // Engine aplica ExpressionEvaluator; conservar tipos resueltos sin doble interpolación.
        $p = $this->prepare($parameters);
        $token = $this->token($p['credential_id']);
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false
        ];
        if (isset($p['payload'])) {
            $options['json'] = $p['payload'];
        }
        if (isset($p['query'])) {
            $options['query'] = $p['query'];
        }
        if ($p['operation'] === 'copy_template') {
            $this->checkTemplate($p['template_id'], $options);
        }
        $notice = $p['method'] === 'POST' ? ' Resultado incierto: la escritura puede haberse realizado; verifica el documento o Drive antes de repetir.' : '';
        try {
            $response = ($this->client ?? new Client())->request($p['method'], $p['url'], $options);
            $status = $response->getStatusCode();
            $raw = '';
            $stream = $response->getBody();
            while (!$stream->eof() && strlen($raw) <= 8 * 1024 * 1024) {
                $chunk = $stream->read(8192);
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Docs: fallo de transporte o lectura.' . $notice);
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'token inválido o caducado; renueva la credencial manualmente',
                403 => 'permisos insuficientes o API no habilitada; revisa scopes, acceso al archivo y Docs/Drive API',
                404 => 'archivo o carpeta inexistente o inaccesible',
                429 => 'cuota o límite de solicitudes; espera antes de repetir',
                default => 'el servicio rechazó la solicitud'
            };
            throw new RuntimeException("Google Docs: {$reason} (HTTP {$status})." . ($status >= 500 ? $notice : ''), $status);
        }
        $invalid = 'Google Docs: respuesta inválida o incompleta.' . $notice;
        if ($status !== 200 || strlen($raw) > 8 * 1024 * 1024) {
            throw new RuntimeException($invalid, $status);
        }
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body) || array_is_list($body) || array_key_exists('error', $body)) {
            throw new RuntimeException($invalid, $status);
        }
        if ($p['operation'] === 'copy_template') {
            if (!$this->validId($body['id'] ?? null) || ($body['mimeType'] ?? null) !== 'application/vnd.google-apps.document'
                || $body['id'] === $p['template_id'] || !is_string($body['name'] ?? null)) {
                throw new RuntimeException($invalid, $status);
            }
            $data = ['document_id' => $body['id'], 'title' => $body['name'], 'source_template_id' => $p['template_id']];
        } else {
            if (!$this->validId($body['documentId'] ?? null)
                || (isset($p['document_id']) && $body['documentId'] !== $p['document_id'])) {
                throw new RuntimeException($invalid, $status);
            }
            $data = ['document_id' => $body['documentId']];
            if ($p['operation'] === 'replace_text') {
                if (!is_array($body['replies'] ?? null) || !array_is_list($body['replies']) || count($body['replies']) !== $p['request_count']) {
                    throw new RuntimeException($invalid, $status);
                }
                $counts = [];
                foreach ($body['replies'] as $reply) {
                    $result = is_array($reply) ? ($reply['replaceAllText'] ?? null) : null;
                    $count = is_array($result) ? ($result['occurrencesChanged'] ?? 0) : null;
                    if (!is_int($count) || $count < 0) {
                        throw new RuntimeException($invalid, $status);
                    }
                    $counts[] = $count;
                }
                $data['occurrences_changed'] = $counts;
                if (isset($body['writeControl']['requiredRevisionId']) && is_string($body['writeControl']['requiredRevisionId'])) {
                    $data['revision_id'] = $body['writeControl']['requiredRevisionId'];
                }
            } else {
                if (!is_string($body['title'] ?? null)) {
                    throw new RuntimeException($invalid, $status);
                }
                $data['title'] = $body['title'];
                if ($p['operation'] === 'get_document') {
                    if (!is_array(json_decode($raw)->tabs ?? null) || !is_array($body['tabs'] ?? null) || !array_is_list($body['tabs'])) {
                        throw new RuntimeException($invalid, $status);
                    }
                    $data['tabs'] = $body['tabs'];
                    if (isset($body['revisionId']) && is_string($body['revisionId'])) {
                        $data['revision_id'] = $body['revisionId'];
                    }
                }
            }
        }
        $data['url'] = 'https://docs.google.com/document/d/' . rawurlencode($data['document_id']) . '/edit';
        if (str_contains(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $token)) {
            throw new RuntimeException($invalid, $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        $id = $this->text($p['credential_id'] ?? null, true, 200);
        $op = array_key_exists('operation', $p) ? $p['operation'] : 'create_document';
        if (!is_string($op) || !in_array($op, ['create_document', 'get_document', 'replace_text', 'copy_template'], true)) {
            throw new InvalidArgumentException('Google Docs: operación inválida.');
        }
        $r = ['credential_id' => $id, 'operation' => $op, 'method' => $op === 'get_document' ? 'GET' : 'POST'];
        if ($op === 'create_document') {
            $r['url'] = 'https://docs.googleapis.com/v1/documents';
            $r['payload'] = ['title' => $this->text($p['title'] ?? null, true, 1000)];
        } elseif ($op === 'copy_template') {
            $template = $p['template_id'] ?? null;
            if (!$this->validId($template)) {
                throw new InvalidArgumentException('Google Docs: ID de plantilla inválido.');
            }
            $r['template_id'] = $template;
            $r['url'] = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($template) . '/copy';
            $r['query'] = ['fields' => 'id,name,mimeType', 'supportsAllDrives' => 'true'];
            $r['payload'] = ['name' => $this->text($p['title'] ?? null, true, 1000)];
            if (array_key_exists('parent_folder_id', $p)) {
                $parent = $this->text($p['parent_folder_id'], false, 256);
                if ($parent !== '') {
                    if (!$this->validId($parent)) {
                        throw new InvalidArgumentException('Google Docs: ID de carpeta inválido.');
                    }
                    $r['payload']['parents'] = [$parent];
                }
            }
        } else {
            $document = $p['document_id'] ?? null;
            if (!$this->validId($document)) {
                throw new InvalidArgumentException('Google Docs: ID de documento inválido.');
            }
            $r['document_id'] = $document;
            $r['url'] = 'https://docs.googleapis.com/v1/documents/' . rawurlencode($document);
            if ($op === 'get_document') {
                $r['query'] = ['includeTabsContent' => 'true'];
            } else {
                $r['url'] .= ':batchUpdate';
                $replacements = $this->listValue($p['replacements'] ?? null);
                if (count($replacements) < 1 || count($replacements) > 100) {
                    throw new InvalidArgumentException('Google Docs: indica entre 1 y 100 reemplazos.');
                }
                $tabs = null;
                if (array_key_exists('tab_ids', $p) && $p['tab_ids'] !== '') {
                    $tabs = $this->listValue($p['tab_ids']);
                    if (count($tabs) < 1 || count($tabs) > 100) {
                        throw new InvalidArgumentException('Google Docs: tab_ids exige una lista de 1 a 100 IDs.');
                    }
                    foreach ($tabs as $tab) {
                        if (!is_string($tab) || preg_match('/^[A-Za-z0-9_.-]{1,256}$/D', $tab) !== 1) {
                            throw new InvalidArgumentException('Google Docs: ID de pestaña inválido.');
                        }
                    }
                }
                $requests = [];
                $total = 0;
                foreach ($replacements as $replacement) {
                    if ($replacement instanceof stdClass) {
                        $replacement = (array) $replacement;
                    }
                    if (!is_array($replacement) || array_is_list($replacement) || array_diff(array_keys($replacement), ['find', 'replace', 'match_case']) !== []) {
                        throw new InvalidArgumentException('Google Docs: cada reemplazo requiere find, replace y match_case opcional.');
                    }
                    $find = $this->text($replacement['find'] ?? null, true, 10000);
                    $replace = $this->text($replacement['replace'] ?? null, false, 1000000);
                    $match = array_key_exists('match_case', $replacement) ? $replacement['match_case'] : true;
                    if (!is_bool($match)) {
                        throw new InvalidArgumentException('Google Docs: match_case debe ser booleano.');
                    }
                    $total += strlen($find) + strlen($replace);
                    if ($total > 1000000) {
                        throw new InvalidArgumentException('Google Docs: reemplazos exceden 1 MB.');
                    }
                    $request = ['replaceText' => $replace, 'containsText' => ['text' => $find, 'matchCase' => $match, 'searchByRegex' => false]];
                    if ($tabs !== null) {
                        $request['tabsCriteria'] = ['tabIds' => array_values(array_unique($tabs))];
                    }
                    $requests[] = ['replaceAllText' => $request];
                }
                $r['payload'] = ['requests' => $requests];
                $r['request_count'] = count($requests);
                if (array_key_exists('required_revision_id', $p)) {
                    $revision = $this->text($p['required_revision_id'], false, 1000);
                    if ($revision !== '') {
                        $r['payload']['writeControl'] = ['requiredRevisionId' => $revision];
                    }
                }
            }
        }
        return $r;
    }

    private function checkTemplate(string $id, array $options): void
    {
        // Comprobar el tipo y permiso de copia antes del único efecto externo.
        unset($options['json']);
        $options['query'] = ['fields' => 'id,mimeType,capabilities(canCopy)', 'supportsAllDrives' => 'true'];
        try {
            $response = ($this->client ?? new Client())->request('GET',
                'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id), $options);
            $status = $response->getStatusCode();
            $raw = $response->getBody()->read(65537);
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Docs: fallo de transporte al verificar la plantilla; no se solicitó una copia.');
        }
        if ($status !== 200) {
            $reason = match ($status) {
                401 => 'autenticación caducada o inválida',
                403 => 'permisos insuficientes o Drive API no habilitada',
                404 => 'plantilla inexistente o inaccesible',
                429 => 'cuota o límite de solicitudes',
                default => 'el servicio rechazó la verificación de plantilla'
            };
            throw new RuntimeException("Google Docs: {$reason} (HTTP {$status}); no se solicitó una copia.", $status);
        }
        $file = json_decode($raw, true);
        if (strlen($raw) > 65536 || json_last_error() !== JSON_ERROR_NONE || !is_array($file)
            || isset($file['error']) || ($file['id'] ?? null) !== $id) {
            throw new RuntimeException('Google Docs: metadatos de plantilla inválidos; no se solicitó una copia.');
        }
        if (($file['mimeType'] ?? null) !== 'application/vnd.google-apps.document'
            || ($file['capabilities']['canCopy'] ?? null) !== true) {
            throw new InvalidArgumentException('Google Docs: la plantilla debe ser un documento Google Docs con permiso de copia; no se solicitó una copia.');
        }
    }

    private function token(string $id): string
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Docs: fallo del gestor al recuperar o descifrar la credencial.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Google Docs: credencial inexistente.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token) || strlen($token) < 8
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1) {
            throw new InvalidArgumentException('Google Docs: usa generic_bearer con access token OAuth sin prefijo Bearer.');
        }
        return $token;
    }

    private function text(mixed $value, bool $required, int $max): string
    {
        if (!is_string($value) || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8')
            || str_contains($value, "\0") || ($required && trim($value) === '')) {
            throw new InvalidArgumentException('Google Docs: texto requerido inválido o fuera de límite.');
        }
        return $value;
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $id) === 1;
    }

    private function listValue(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Google Docs: lista JSON inválida.');
            }
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Google Docs: se requiere una lista JSON o un array resuelto.');
        }
        return $value;
    }
}
