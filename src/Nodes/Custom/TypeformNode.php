<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class TypeformNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentialManager = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'type' => 'typeform', 'name' => 'Typeform', 'category' => 'Community', 'icon' => 'ClipboardList', 'is_start' => false,
            'description' => 'Consulta una página de respuestas o busca una respuesta mediante included_response_ids.',
            'help_text' => 'generic_bearer: API Key = token personal u OAuth con responses:read. La consulta individual usa el endpoint de colección con included_response_ids; no hay ruta individual. Una página por ejecución, hasta 1000 respuestas. before/after usan el token de respuesta, diferente del token de credenciales. Datos recientes pueden tardar unos 30 minutos. Elige el centro de datos de tu cuenta; no se descubren ni siguen URLs remotas. No renueva tokens. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Typeform'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'list_responses', 'options' => [
                    ['value' => 'list_responses', 'label' => 'Listar respuestas (una página)'],
                    ['value' => 'get_response', 'label' => 'Buscar una respuesta por response_id']
                ]],
                'form_id' => ['type' => 'string', 'required' => true, 'label' => 'ID del formulario'],
                'data_center' => ['type' => 'select', 'required' => false, 'label' => 'Centro de datos de la cuenta', 'default' => 'us', 'options' => [
                    ['value' => 'us', 'label' => 'US (api.typeform.com)'],
                    ['value' => 'eu', 'label' => 'EU (api.eu.typeform.com)'],
                    ['value' => 'eu2', 'label' => 'EU nuevo (api.typeform.eu)']
                ]],
                'response_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de respuesta (buscar individual)'],
                'response_type' => ['type' => 'select', 'required' => false, 'label' => 'Tipo de respuesta', 'default' => 'completed', 'options' => [
                    ['value' => 'completed', 'label' => 'Completada'], ['value' => 'partial', 'label' => 'Parcial'], ['value' => 'started', 'label' => 'Iniciada']
                ]],
                'page_size' => ['type' => 'number', 'required' => false, 'label' => 'Tamaño de página (1–1000, listar)', 'default' => 100],
                'before' => ['type' => 'string', 'required' => false, 'label' => 'Cursor before (listar; última respuesta de página anterior)'],
                'after' => ['type' => 'string', 'required' => false, 'label' => 'Cursor after (listar; exclusivo con before)'],
                'filters' => ['type' => 'textarea', 'required' => false, 'label' => 'Filtros (objeto JSON, listar)',
                    'placeholder' => '{"since":"2026-01-01T00:00:00Z","until":"2026-01-02T00:00:00Z","fields":["field123"],"query":"ejemplo"}']
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
        [$operation, $url, $query] = $this->prepare($parameters);
        try {
            $credential = ($this->credentialManager ?? new CredentialManager())->getCredentialDecrypted($parameters['credential_id']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Typeform: fallo del gestor de credenciales (almacenamiento o descifrado), anterior a contactar Typeform.');
        }
        if ($credential === null) {
            throw new RuntimeException('Typeform: credencial inexistente.');
        }
        if (($credential['type'] ?? null) !== 'generic_bearer') {
            throw new RuntimeException('Typeform: se requiere una credencial generic_bearer.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 8192 || preg_match('#^[A-Za-z0-9._+~/-]+=*$#D', $token) !== 1) {
            throw new RuntimeException('Typeform: API Key debe contener un token personal u OAuth válido.');
        }
        try {
            $response = ($this->client ?? new Client())->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'query' => $query, 'timeout' => 30, 'connect_timeout' => 10,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Typeform: fallo de transporte; no se reintentó la consulta.');
        }
        $status = $response->getStatusCode();
        if ($status !== 200) {
            $response->getBody()->close();
            $reason = match ($status) {
                400, 422 => 'filtros o solicitud inválidos', 401 => 'token caducado o autenticación rechazada',
                403 => 'permisos responses:read o acceso al formulario insuficientes',
                404 => 'formulario inexistente o inaccesible', 429 => 'cuota alcanzada',
                301, 302, 307, 308 => 'redirección bloqueada; comprueba el centro de datos',
                default => 'error del servicio o respuesta HTTP inesperada'
            };
            throw new RuntimeException('Typeform: HTTP ' . $status . ', ' . $reason . '. Sin reintentos.');
        }
        try {
            $raw = '';
            $stream = $response->getBody();
            while (!$stream->eof()) {
                $chunk = $stream->read(min(65536, 5242881 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Respuesta incompleta.');
                }
                $raw .= $chunk;
                if (strlen($raw) > 5242880) {
                    throw new RuntimeException('Límite excedido.');
                }
            }
            $document = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            if (!$document instanceof \stdClass || !isset($document->items) || !is_array($document->items)) {
                throw new RuntimeException('Estructura inválida.');
            }
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('Typeform: respuesta JSON inválida, incompleta o superior a 5 MiB.');
        } finally {
            $response->getBody()->close();
        }
        if (!is_array($body) || isset($body['code']) || isset($body['error']) || isset($body['errors'])
            || !isset($body['items'], $body['total_items'], $body['page_count']) || !is_array($body['items'])
            || !array_is_list($body['items']) || count($body['items']) > $query['page_size']
            || !is_int($body['total_items']) || $body['total_items'] < 0 || !is_int($body['page_count']) || $body['page_count'] < 0) {
            throw new RuntimeException('Typeform: estructura de respuesta inválida o error funcional del servicio.');
        }
        foreach ($body['items'] as $item) {
            if (!is_array($item) || !$this->validId($item['token'] ?? null)) {
                throw new RuntimeException('Typeform: respuesta sin cursor token válido.');
            }
        }
        // Do not return top-level API links or blindly fetch any provider-supplied URL.
        $data = ['items' => $body['items'], 'total_items' => $body['total_items'], 'page_count' => $body['page_count']];
        if ($operation === 'get_response') {
            if ($body['items'] === []) {
                throw new RuntimeException('Typeform: respuesta no encontrada con ese ID y tipo. Comprueba centro de datos y posible demora de indexación.');
            }
            if (($body['items'][0]['response_id'] ?? null) !== $parameters['response_id']) {
                throw new RuntimeException('Typeform: el servicio devolvió una respuesta diferente del ID solicitado.');
            }
            $data['response'] = $body['items'][0];
        } else {
            $items = $body['items'];
            $data['pagination'] = [
                'first_token' => $items === [] ? null : $items[0]['token'],
                'last_token' => $items === [] ? null : $items[count($items) - 1]['token'],
                'next_before' => count($items) === $query['page_size'] ? $items[count($items) - 1]['token'] : null,
                'may_have_more' => count($items) === $query['page_size']
            ];
        }
        // The response token is a cursor; the credential token must never enter history.
        $serialized = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($serialized === false || str_contains($serialized, $token)) {
            throw new RuntimeException('Typeform: respuesta rechazada para proteger la credencial.');
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        if (!isset($p['credential_id']) || !is_string($p['credential_id']) || trim($p['credential_id']) === '' || !$this->validId($p['form_id'] ?? null)) {
            throw new InvalidArgumentException('Typeform: se requieren credential_id y form_id válidos.');
        }
        $operation = array_key_exists('operation', $p) ? $p['operation'] : 'list_responses';
        $center = array_key_exists('data_center', $p) ? $p['data_center'] : 'us';
        $responseType = array_key_exists('response_type', $p) ? $p['response_type'] : 'completed';
        if (!in_array($operation, ['list_responses', 'get_response'], true) || !in_array($center, ['us', 'eu', 'eu2'], true)
            || !in_array($responseType, ['completed', 'partial', 'started'], true)) {
            throw new InvalidArgumentException('Typeform: operación, centro de datos o tipo de respuesta inválido.');
        }
        $host = match ($center) { 'eu' => 'api.eu.typeform.com', 'eu2' => 'api.typeform.eu', default => 'api.typeform.com' };
        $query = ['response_type' => $responseType];
        if ($operation === 'get_response') {
            if (!$this->validId($p['response_id'] ?? null)) {
                throw new InvalidArgumentException('Typeform: response_id requerido para consulta individual.');
            }
            $query += ['page_size' => 1, 'included_response_ids' => $p['response_id']];
        } else {
            $limit = array_key_exists('page_size', $p) ? $p['page_size'] : 100;
            if ((!is_int($limit) && !(is_string($limit) && ctype_digit($limit))) || (int) $limit < 1 || (int) $limit > 1000) {
                throw new InvalidArgumentException('Typeform: page_size debe ser un entero entre 1 y 1000.');
            }
            $query['page_size'] = (int) $limit;
            foreach (['before', 'after'] as $key) {
                if (array_key_exists($key, $p)) {
                    if (!is_string($p[$key]) || ($p[$key] !== '' && !$this->validId($p[$key]))) {
                        throw new InvalidArgumentException('Typeform: cursor before o after inválido.');
                    }
                    if ($p[$key] !== '') {
                        $query[$key] = $p[$key];
                    }
                }
            }
            if (isset($query['before'], $query['after'])) {
                throw new InvalidArgumentException('Typeform: usa solo uno de before o after.');
            }
            $filters = $this->filters(array_key_exists('filters', $p) ? $p['filters'] : new \stdClass());
            $query += $filters;
        }
        return [$operation, 'https://' . $host . '/forms/' . rawurlencode($p['form_id']) . '/responses', $query];
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $id) === 1;
    }

    private function filters(mixed $value): array
    {
        if ($value === '') {
            return [];
        }
        if (is_string($value)) {
            try {
                $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Typeform: filtros JSON inválidos.');
            }
        }
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || array_diff(array_keys($value), ['since', 'until', 'query', 'fields', 'answered_fields', 'included_response_ids', 'excluded_response_ids'])) {
            throw new InvalidArgumentException('Typeform: filtros debe ser un objeto con since/until/query/fields/answered_fields/included_response_ids/excluded_response_ids.');
        }
        $query = [];
        foreach ($value as $key => $filter) {
            if (in_array($key, ['since', 'until'], true)) {
                if (is_int($filter) && $filter >= 0) {
                    $filter = (string) $filter;
                }
                if (!is_string($filter) || !$this->validDate($filter)) {
                    throw new InvalidArgumentException('Typeform: since/until deben ser segundos Unix o fecha UTC YYYY-MM-DDTHH:MM:SS con Z opcional.');
                }
                $query[$key] = $filter;
            } elseif ($key === 'query') {
                if (!is_string($filter) || strlen($filter) > 4096 || preg_match('//u', $filter) !== 1) {
                    throw new InvalidArgumentException('Typeform: query requiere texto UTF-8 de hasta 4096 bytes.');
                }
                $query[$key] = $filter;
            } else {
                if (is_string($filter)) {
                    $filter = explode(',', $filter);
                }
                if (!is_array($filter) || !array_is_list($filter) || count($filter) < 1 || count($filter) > 100) {
                    throw new InvalidArgumentException('Typeform: los filtros de IDs requieren listas de 1 a 100 IDs.');
                }
                foreach ($filter as $id) {
                    if (!$this->validId($id)) {
                        throw new InvalidArgumentException('Typeform: identificador inválido en filtros.');
                    }
                }
                $query[$key] = implode(',', $filter);
            }
        }
        if (isset($query['since'], $query['until']) && $this->dateSeconds($query['since']) > $this->dateSeconds($query['until'])) {
            throw new InvalidArgumentException('Typeform: since no puede ser posterior a until.');
        }
        return $query;
    }

    private function validDate(string $value): bool
    {
        if (ctype_digit($value)) {
            return strlen($value) <= 10;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z?$/D', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', rtrim($value, 'Z'), new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s') === rtrim($value, 'Z') && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z?$/D', $value) === 1;
    }

    private function dateSeconds(string $value): int
    {
        return ctype_digit($value) ? (int) $value : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
