<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class LinkedInNode implements NodeInterface
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
            'type' => 'linkedin',
            'name' => 'LinkedIn',
            'category' => 'Community',
            'description' => 'Publica texto como miembro u organización y consulta información autorizada.',
            'icon' => 'BriefcaseBusiness',
            'is_start' => false,
            'help_text' => 'Credencial generic_bearer: API Key contiene únicamente el access token OAuth 2.0, sin Bearer. Perfil propio usa /v2/userinfo con el producto OpenID Connect y scopes openid profile (email opcional); no consulta perfiles ajenos. Publicar usa /rest/posts: w_member_social para miembro; w_organization_social, producto Community Management autorizado y rol ADMINISTRATOR, CONTENT_ADMIN o DIRECT_SPONSORED_CONTENT_POSTER para organización. LinkedIn comprueba la titularidad y los permisos, no basta pertenecer a la empresa. Consultar organización usa rw_organization_admin y rol ADMINISTRATOR. OIDC no concede permisos de contenido ni organizaciones. Versión REST fijada inicialmente en 202610: actualízala antes de su retirada. Posts de texto públicos (máximo local 3000 caracteres), sin medios ni paginación. El token caduca y se reemplaza manualmente; no hay OAuth ni renovación automática. Errores detienen el flujo. Sin reintentos: una publicación puede haberse creado si falla el transporte. Salidas: data.id en publicaciones; data.sub en perfil; data.id en organización. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial OAuth (Bearer Token / API Key)'],
                'operation' => [
                    'type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'get_profile',
                    'options' => [
                        ['value' => 'get_profile', 'label' => 'Consultar mi perfil (OpenID Connect)'],
                        ['value' => 'get_organization', 'label' => 'Consultar organización administrada'],
                        ['value' => 'create_member_post', 'label' => 'Publicar texto como miembro'],
                        ['value' => 'create_organization_post', 'label' => 'Publicar texto como organización']
                    ]
                ],
                'api_version' => ['type' => 'string', 'required' => false, 'label' => 'Versión REST (YYYYMM; no aplica a OIDC)', 'default' => '202610'],
                'author_urn' => ['type' => 'string', 'required' => false, 'label' => 'Autor de publicación (URN de miembro u organización)', 'placeholder' => 'urn:li:person:abc123 o urn:li:organization:123456'],
                'commentary' => ['type' => 'textarea', 'required' => false, 'label' => 'Texto a publicar (público; máximo 3000 caracteres)', 'placeholder' => '{{node_1.data.text}}'],
                'organization_id' => ['type' => 'string', 'required' => false, 'label' => 'ID numérico (consultar organización)', 'placeholder' => '123456']
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
        // Engine ya resuelve ExpressionEvaluator: no reinterpretar el texto resultante.
        $prepared = $this->prepareParameters($parameters);
        try {
            $manager = $this->credentials ?? new CredentialManager();
            $credential = $manager->getCredentialDecrypted($prepared['credential_id']);
        } catch (\Throwable $e) {
            throw new RuntimeException('LinkedIn: fallo de almacenamiento o descifrado de la credencial; revisa el gestor.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('LinkedIn: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1) {
            throw new InvalidArgumentException('LinkedIn: usa generic_bearer con data.api_key que contenga un access token OAuth 2.0 sin prefijo Bearer.');
        }

        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        if ($prepared['operation'] !== 'get_profile') {
            $headers['LinkedIn-Version'] = $prepared['api_version'];
            $headers['X-Restli-Protocol-Version'] = '2.0.0';
        }
        $options = [
            'headers' => $headers, 'timeout' => 30, 'connect_timeout' => 10,
            'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true
        ];
        $posting = isset($prepared['payload']);
        if ($posting) {
            $options['json'] = $prepared['payload'];
        }
        try {
            $response = ($this->client ?? new Client())->request($posting ? 'POST' : 'GET',
                'https://api.linkedin.com' . $prepared['path'], $options);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            throw new RuntimeException($posting
                ? 'LinkedIn: fallo de transporte; el resultado de la publicación es incierto. Comprueba LinkedIn antes de repetirla.'
                : 'LinkedIn: fallo de transporte al consultar el recurso.');
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'token inválido, expirado o revocado; reemplázalo manualmente',
                403 => $prepared['operation'] === 'get_profile'
                    ? 'revisa el producto OpenID Connect y los scopes openid/profile'
                    : 'revisa el producto autorizado, los scopes y la titularidad del miembro o el rol de la página; OIDC no concede estos permisos',
                404 => 'recurso inexistente o inaccesible',
                429 => 'cuota o límite de solicitudes alcanzado; no se reintentó',
                426 => 'versión REST retirada o no admitida; revisa api_version',
                400, 422 => 'datos rechazados; revisa el identificador, el texto y la versión REST',
                409 => 'conflicto; comprueba si la publicación ya existe antes de repetir',
                default => 'solicitud rechazada; revisa el servicio y el resultado antes de repetir'
            };
            throw new RuntimeException("LinkedIn: {$reason} (HTTP {$status}).", $status);
        }

        try {
            $body = '';
            $stream = $response->getBody();
            while (!$stream->eof() && strlen($body) <= 2097152) {
                $chunk = $stream->read(min(8192, 2097153 - strlen($body)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $body .= $chunk;
            }
            if (strlen($body) > 2097152) {
                throw new RuntimeException();
            }
            $data = trim($body) === '' ? [] : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data !== [] && array_is_list($data))
                || (trim($body) !== '' && !str_starts_with(ltrim($body), '{'))) {
                throw new RuntimeException();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException($posting
                ? 'LinkedIn: respuesta inválida o demasiado grande; la publicación puede existir. Comprueba LinkedIn antes de repetir.'
                : 'LinkedIn: respuesta inválida o superior al límite local de 2 MiB.');
        }
        if (array_key_exists('error', $data) || array_key_exists('serviceErrorCode', $data)
            || (isset($data['status']) && is_numeric($data['status']) && (int) $data['status'] >= 400)) {
            throw new RuntimeException('LinkedIn: la respuesta contiene un error funcional; revisa permisos y recurso antes de repetir.');
        }
        if ($posting) {
            $id = $response->getHeaderLine('x-restli-id');
            if ($status !== 201 || preg_match('/^urn:li:(?:share|ugcPost):[0-9]+$/D', $id) !== 1) {
                throw new RuntimeException('LinkedIn: no confirmó el ID de la publicación; comprueba LinkedIn antes de repetir.');
            }
            $data['id'] = $id;
        } elseif ($status !== 200 || ($prepared['operation'] === 'get_profile'
            ? !is_string($data['sub'] ?? null) || $data['sub'] === ''
            : (!is_int($data['id'] ?? null) && !is_string($data['id'] ?? null))
                || (string) $data['id'] !== $prepared['organization_id'])) {
            throw new RuntimeException('LinkedIn: respuesta de consulta incompleta o incompatible.');
        }
        return ['success' => true, 'status_code' => $status, 'data' => $this->sanitize($data, $token)];
    }

    private function prepareParameters(array $parameters): array
    {
        $credentialId = $parameters['credential_id'] ?? null;
        if (!is_string($credentialId) || trim($credentialId) === '' || strlen($credentialId) > 200) {
            throw new InvalidArgumentException('LinkedIn: credential_id es obligatorio y debe ser texto.');
        }
        $operation = $parameters['operation'] ?? 'get_profile';
        if (!is_string($operation) || !in_array($operation,
            ['get_profile', 'get_organization', 'create_member_post', 'create_organization_post'], true)) {
            throw new InvalidArgumentException('LinkedIn: operación inválida.');
        }
        $prepared = ['credential_id' => $credentialId, 'operation' => $operation, 'path' => '/v2/userinfo'];
        if ($operation === 'get_profile') {
            return $prepared;
        }
        $version = $parameters['api_version'] ?? '202610';
        if (!is_string($version) || preg_match('/^20[0-9]{2}(?:0[1-9]|1[0-2])$/D', $version) !== 1) {
            throw new InvalidArgumentException('LinkedIn: api_version debe tener formato YYYYMM y ser una versión soportada por LinkedIn.');
        }
        $prepared['api_version'] = $version;
        if ($operation === 'get_organization') {
            $id = $parameters['organization_id'] ?? null;
            if (is_int($id) && $id > 0) {
                $id = (string) $id;
            }
            if (!is_string($id) || preg_match('/^[1-9][0-9]{0,19}$/D', $id) !== 1) {
                throw new InvalidArgumentException('LinkedIn: organization_id debe ser un ID numérico positivo.');
            }
            $prepared['organization_id'] = $id;
            $prepared['path'] = '/rest/organizations/' . $id;
            return $prepared;
        }
        $author = $parameters['author_urn'] ?? null;
        $pattern = $operation === 'create_member_post'
            ? '/^urn:li:person:[A-Za-z0-9_-]{1,200}$/D' : '/^urn:li:organization:[1-9][0-9]{0,19}$/D';
        if (!is_string($author) || preg_match($pattern, $author) !== 1) {
            throw new InvalidArgumentException('LinkedIn: author_urn debe corresponder al tipo de publicación seleccionado y estar autorizado por el token.');
        }
        $text = $parameters['commentary'] ?? null;
        if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || trim($text) === ''
            || mb_strlen($text, 'UTF-8') > 3000 || str_contains($text, "\0")) {
            throw new InvalidArgumentException('LinkedIn: commentary debe ser texto UTF-8 no vacío de hasta 3000 caracteres.');
        }
        $prepared['path'] = '/rest/posts';
        $prepared['payload'] = [
            'author' => $author, 'commentary' => $text, 'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false
        ];
        return $prepared;
    }

    private function sanitize(mixed $value, string $token): mixed
    {
        if (is_string($value)) {
            return str_replace([$token, rawurlencode($token)], '[REDACTED]', $value);
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key) ? str_replace($token, '[REDACTED]', $key) : $key;
                $safe[$safeKey] = is_string($key) && preg_match('/^(?:authorization|access_token|refresh_token|api_key|client_secret)$/iD', $key)
                    ? '[REDACTED]' : $this->sanitize($item, $token);
            }
            return $safe;
        }
        return $value;
    }
}
