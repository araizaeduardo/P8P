<?php

namespace P8P\Nodes\Custom;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class GoogleFormsNode implements NodeInterface
{
    private ?ClientInterface $client;
    private ?CredentialManager $credentials;
    private const MAX_BODY = 4194304;

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null)
    {
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'google_forms', 'name' => 'Google Forms', 'category' => 'Community',
            'description' => 'Consulta una respuesta o una página de respuestas de un formulario autorizado.',
            'icon' => 'ClipboardList', 'is_start' => false,
            'help_text' => 'Credencial generic_bearer con data.api_key = access token OAuth sin Bearer. Habilita Forms API; scope mínimo forms.responses.readonly y acceso del usuario al formulario (ser encuestado no concede lectura de respuestas). Token caducado: reemplazo manual; sin OAuth interactivo ni renovación. form_id es el ID del formulario editable, no el enlace público de respuesta. list_responses obtiene solo una página de 1–100 (límite local), filtro oficial opcional timestamp > N o timestamp >= N; N en UTC YYYY-MM-DDTHH:MM:SS[.1–9 decimales]Z. El cursor data.pagination.next_page_token se pasa en page_token con la misma credencial, formulario, filtro y tamaño; es un sobre local, no una URL ni un token Google sin envolver. No recorre páginas automáticamente. get_response exige response_id. Salidas conservan formId/responseId, createTime/lastSubmittedTime, answers por questionId y grades cuando Google los devuelve; archivos adjuntos solo metadatos, nunca descargados. Cuotas y acceso dependen del proyecto; errores detienen el flujo, sin reintentos. Respuesta máxima local4MiB; reduce page_size si se supera. Los eventos de respuestas disponibles por separado usan polling; no registran watches Pub/Sub. Engine resuelve expresiones una vez. Ejemplo {{node_id.data.answers.123.textAnswers.answers.0.value}}. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial OAuth Forms'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'list_responses', 'options' => [
                    ['value' => 'list_responses', 'label' => 'Listar respuestas (una página)'],
                    ['value' => 'get_response', 'label' => 'Obtener una respuesta']
                ]],
                'form_id' => ['type' => 'string', 'required' => true, 'label' => 'ID del formulario'],
                'response_id' => ['type' => 'string', 'required' => false, 'label' => 'ID de respuesta (obtener)'],
                'filter' => ['type' => 'string', 'required' => false, 'label' => 'Filtro de listado (opcional)', 'placeholder' => 'timestamp >= 2026-10-01T00:00:00.123456789Z'],
                'page_size' => ['type' => 'number', 'required' => false, 'label' => 'Respuestas por página (1–100)', 'default' => 100],
                'page_token' => ['type' => 'textarea', 'required' => false, 'label' => 'Cursor local de data.pagination.next_page_token']
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
        // Engine aplica ExpressionEvaluator: no reinterpretar respuestas o filtros resueltos.
        $p = $this->prepare($parameters);
        $token = $this->credential($p['credential_id']);
        $url = 'https://forms.googleapis.com/v1/forms/' . rawurlencode($p['form_id']) . '/responses';
        $options = [
            'headers' => null, 'auth' => null, 'cookies' => false,
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false, 'stream' => true, 'query' => null
        ];
        if ($p['operation'] === 'get_response') {
            $url .= '/' . rawurlencode($p['response_id']);
        } else {
            $options['query'] = ['pageSize' => $p['page_size']];
            if ($p['filter'] !== '') {
                $options['query']['filter'] = $p['filter'];
            }
            if ($p['provider_token'] !== '') {
                $options['query']['pageToken'] = $p['provider_token'];
            }
        }
        try {
            $request = new Request('GET', $url, ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);
            $response = ($this->client ?? new Client())->send($request, $options);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Forms: fallo de transporte; no se completó la consulta y no se reintentó.');
        }
        if ($status !== 200) {
            try {
                $response->getBody()->close();
            } catch (\Throwable $e) {
                throw new RuntimeException('Google Forms: no se pudo cerrar la lectura de forma segura.');
            }
            $reason = match ($status) {
                401 => 'token inválido, caducado o revocado; reemplázalo manualmente',
                403 => 'permisos insuficientes, API no habilitada o cuota; revisa scope y acceso del usuario al formulario',
                404 => 'formulario o respuesta inexistente o inaccesible',
                429 => 'cuota de lectura alcanzada; espera y revisa los límites del proyecto',
                400 => 'filtro, cursor o parámetros rechazados',
                default => 'estado HTTP inesperado o solicitud rechazada'
            };
            throw new RuntimeException("Google Forms: {$reason} (HTTP {$status}).", $status);
        }
        try {
            $stream = $response->getBody();
            $raw = '';
            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && (preg_match('/^[0-9]{1,12}$/D', $length) !== 1 || (int)$length > self::MAX_BODY)) {
                throw new RuntimeException();
            }
            while (!$stream->eof() && strlen($raw) <= self::MAX_BODY) {
                $chunk = $stream->read(min(8192, self::MAX_BODY + 1 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $raw .= $chunk;
            }
            if (strlen($raw) > self::MAX_BODY) {
                throw new RuntimeException();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Forms: respuesta incompleta o superior al límite local de 4 MiB; reduce page_size.');
        } finally {
            try {
                $response->getBody()->close();
            } catch (\Throwable $e) {
                throw new RuntimeException('Google Forms: no se pudo cerrar la lectura de forma segura.');
            }
        }
        try {
            $body = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            if (!$body instanceof \stdClass || property_exists($body, 'error') || property_exists($body, 'errors')) {
                throw new InvalidArgumentException();
            }
            if ($p['operation'] === 'get_response') {
                $data = $this->formResponse($body, $p['form_id']);
                if ($data['responseId'] !== $p['response_id']) {
                    throw new InvalidArgumentException();
                }
            } else {
                if (array_diff(array_keys(get_object_vars($body)), ['responses', 'nextPageToken']) !== []) {
                    throw new InvalidArgumentException();
                }
                $responses = property_exists($body, 'responses') ? $body->responses : [];
                if (!is_array($responses) || count($responses) > $p['page_size']) {
                    throw new InvalidArgumentException();
                }
                $items = [];
                foreach ($responses as $item) {
                    $items[] = $this->formResponse($item, $p['form_id']);
                }
                $next = property_exists($body, 'nextPageToken') ? $body->nextPageToken : '';
                $this->providerToken($next);
                $cursor = $next === '' ? null : $this->encodeCursor($p, $next);
                $data = ['formId' => $p['form_id'], 'responses' => $items,
                    'pagination' => ['next_page_token' => $cursor, 'page_size' => $p['page_size'], 'result_count' => count($items)]];
            }
            $serialized = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (str_contains($serialized, $token) || str_contains($serialized, rawurlencode($token)) || str_contains($serialized, urlencode($token))) {
                throw new InvalidArgumentException();
            }
            // El cursor codificado también puede ocultar una reflexión del Bearer en nextPageToken.
            if (isset($next) && (str_contains($next, $token) || str_contains($next, rawurlencode($token)) || str_contains($next, urlencode($token)))) {
                throw new InvalidArgumentException();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Forms: respuesta JSON inválida, incompleta o con datos sensibles; no se reintentó.', $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function formResponse(mixed $value, string $formId): array
    {
        if (!$value instanceof \stdClass) {
            throw new InvalidArgumentException();
        }
        if (property_exists($value, 'formId') && $value->formId !== $formId) {
            throw new InvalidArgumentException();
        }
        $id = $this->id($value->responseId ?? null, 'responseId');
        $created = $this->timestamp($value->createTime ?? null);
        $submitted = $this->timestamp($value->lastSubmittedTime ?? null);
        // Comparación lexicográfica con nanosegundos normalizados, sin perder precisión en floats/microsegundos.
        if ($this->timeKey($submitted) < $this->timeKey($created)) {
            throw new InvalidArgumentException();
        }
        $answers = property_exists($value, 'answers') ? $value->answers : new \stdClass();
        if (!$answers instanceof \stdClass || count(get_object_vars($answers)) > 10000) {
            throw new InvalidArgumentException();
        }
        $safeAnswers = new \stdClass();
        foreach ($answers as $questionId => $answer) {
            $questionId = $this->id((string)$questionId, 'questionId');
            if (!$answer instanceof \stdClass || ($answer->questionId ?? null) !== $questionId) {
                throw new InvalidArgumentException();
            }
            $safe = ['questionId' => $questionId];
            $text = property_exists($answer, 'textAnswers');
            $files = property_exists($answer, 'fileUploadAnswers');
            if ($text && $files) {
                throw new InvalidArgumentException();
            }
            if ($text || $files) {
                $kind = $text ? 'textAnswers' : 'fileUploadAnswers';
                if (!$answer->{$kind} instanceof \stdClass) {
                    throw new InvalidArgumentException();
                }
                $values = property_exists($answer->{$kind}, 'answers') ? $answer->{$kind}->answers : [];
                if (!is_array($values) || count($values) > 10000) {
                    throw new InvalidArgumentException();
                }
                $safeValues = [];
                foreach ($values as $entry) {
                    if (!$entry instanceof \stdClass) {
                        throw new InvalidArgumentException();
                    }
                    if ($text) {
                        // Proto JSON puede omitir value=""; conservar el vaciado como cadena vacía.
                        $safeValues[] = ['value' => $this->string(property_exists($entry, 'value') ? $entry->value : '', 'answer', 1000000)];
                    } else {
                        $safeValues[] = [
                            'fileId' => $this->id($entry->fileId ?? null, 'fileId'),
                            'fileName' => $this->string($entry->fileName ?? null, 'fileName', 4096),
                            'mimeType' => $this->string($entry->mimeType ?? null, 'mimeType', 255)
                        ];
                    }
                }
                $safe[$kind] = ['answers' => $safeValues];
            }
            if (property_exists($answer, 'grade')) {
                if (!$answer->grade instanceof \stdClass) {
                    throw new InvalidArgumentException();
                }
                $safe['grade'] = new \stdClass();
                foreach (['score', 'correct', 'feedback'] as $field) {
                    if (property_exists($answer->grade, $field)) {
                        $grade = $answer->grade->{$field};
                        if (($field === 'score' && !$this->number($grade)) || ($field === 'correct' && !is_bool($grade))
                            || ($field === 'feedback' && !$grade instanceof \stdClass)) {
                            throw new InvalidArgumentException();
                        }
                        $safe['grade']->{$field} = $grade;
                    }
                }
            }
            $safeAnswers->{$questionId} = $safe;
        }
        $result = ['formId' => $formId, 'responseId' => $id, 'createTime' => $created,
            'lastSubmittedTime' => $submitted, 'answers' => $safeAnswers];
        if (property_exists($value, 'respondentEmail')) {
            $result['respondentEmail'] = $this->string($value->respondentEmail, 'respondentEmail', 512);
        }
        if (property_exists($value, 'totalScore')) {
            if (!$this->number($value->totalScore)) {
                throw new InvalidArgumentException();
            }
            $result['totalScore'] = $value->totalScore;
        }
        return $result;
    }

    private function prepare(array $parameters): array
    {
        $credentialId = $this->string($parameters['credential_id'] ?? null, 'credential_id', 255);
        if (trim($credentialId) === '') {
            throw new InvalidArgumentException('Google Forms: selecciona credential_id.');
        }
        $operation = array_key_exists('operation', $parameters) ? $parameters['operation'] : 'list_responses';
        if (!is_string($operation) || !in_array($operation, ['list_responses', 'get_response'], true)) {
            throw new InvalidArgumentException('Google Forms: operación inválida.');
        }
        $p = ['credential_id' => $credentialId, 'operation' => $operation, 'form_id' => $this->id($parameters['form_id'] ?? null, 'form_id')];
        if ($operation === 'get_response') {
            $p['response_id'] = $this->id($parameters['response_id'] ?? null, 'response_id');
            return $p;
        }
        $pageSize = array_key_exists('page_size', $parameters) ? $parameters['page_size'] : 100;
        if (is_string($pageSize) && preg_match('/^[0-9]{1,3}$/D', $pageSize)) {
            $pageSize = (int)$pageSize;
        }
        if (!is_int($pageSize) || $pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('Google Forms: page_size debe ser entero entre 1 y 100 (límite local).');
        }
        $p['page_size'] = $pageSize;
        $filter = $this->string(array_key_exists('filter', $parameters) ? $parameters['filter'] : '', 'filter', 128);
        if ($filter !== '') {
            if (preg_match('/^timestamp (>|>=) (.+)$/D', $filter, $match) !== 1) {
                throw new InvalidArgumentException('Google Forms: filtro admite solo timestamp > N o timestamp >= N, UTC con Z.');
            }
            $this->timestamp($match[2]);
        }
        $p['filter'] = $filter;
        $cursor = $this->string(array_key_exists('page_token', $parameters) ? $parameters['page_token'] : '', 'page_token', 16000);
        $p['provider_token'] = $cursor === '' ? '' : $this->decodeCursor($cursor, $p);
        return $p;
    }

    private function credential(string $id): string
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Forms: no se pudo recuperar o descifrar la credencial; revisa el gestor y el almacenamiento.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Google Forms: credencial inexistente.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token) || strlen($token) < 8 || strlen($token) > 16384
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1) {
            throw new InvalidArgumentException('Google Forms: usa generic_bearer con access token OAuth en data.api_key, sin prefijo Bearer.');
        }
        return $token;
    }

    private function timestamp(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,9}))?Z$/D', $value, $match) !== 1
            || substr($value, 0, 4) === '0000') {
            throw new InvalidArgumentException('Google Forms: timestamp requiere RFC3339 UTC con Z y hasta 9 decimales.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $match[1], new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s') !== $match[1]) {
            throw new InvalidArgumentException('Google Forms: timestamp contiene fecha u hora inexistente.');
        }
        return $value;
    }

    private function timeKey(string $value): string
    {
        $parts = explode('.', substr($value, 0, -1), 2);
        return $parts[0] . '.' . str_pad($parts[1] ?? '', 9, '0');
    }

    private function scope(array $p): string
    {
        return hash('sha256', json_encode([$p['credential_id'], $p['form_id'], $p['filter'], $p['page_size']], JSON_UNESCAPED_SLASHES));
    }

    private function encodeCursor(array $p, string $token): string
    {
        return rtrim(strtr(base64_encode(json_encode(['v' => 1, 'scope' => $this->scope($p), 'token' => $token], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor, array $p): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1 || ($raw = base64_decode(strtr($cursor, '-_', '+/'), true)) === false
            || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $cursor) {
            throw new InvalidArgumentException('Google Forms: cursor local inválido; usa data.pagination.next_page_token.');
        }
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body) || ($body['v'] ?? null) !== 1
            || ($body['scope'] ?? null) !== $this->scope($p)) {
            throw new InvalidArgumentException('Google Forms: cursor incompatible con la credencial, formulario, filtro o tamaño de página.');
        }
        $token = $body['token'] ?? null;
        $this->providerToken($token);
        if ($token === '') {
            throw new InvalidArgumentException('Google Forms: cursor vacío; omite page_token para la primera página.');
        }
        return $token;
    }

    private function providerToken(mixed $value): void
    {
        if (!is_string($value) || strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value)
            || str_contains($value, '://')) {
            throw new InvalidArgumentException('Google Forms: token de página inválido.');
        }
    }

    private function id(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $value) !== 1) {
            throw new InvalidArgumentException("Google Forms: {$field} debe ser un ID opaco válido, sin ruta ni URL.");
        }
        return $value;
    }

    private function string(mixed $value, string $field, int $max): string
    {
        if (!is_string($value) || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new InvalidArgumentException("Google Forms: campo {$field} debe ser texto válido.");
        }
        return $value;
    }

    private function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float)$value);
    }
}
