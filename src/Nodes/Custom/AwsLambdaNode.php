<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use P8P\Core\AwsSignatureV4;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;

class AwsLambdaNode implements NodeInterface
{
    private \Closure $clock;

    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null, ?callable $clock = null)
    {
        $this->clock = \Closure::fromCallable($clock ?? 'time');
    }

    public function getSchema(): array
    {
        return ['type' => 'aws_lambda', 'name' => 'AWS Lambda Invoker', 'category' => 'Community',
            'description' => 'Invoca Lambda con JSON mediante firma AWS SigV4 compartida con S3.', 'icon' => 'Zap', 'is_start' => false,
            'help_text' => 'generic_bearer/API Key JSON: access_key_id, secret_access_key y session_token opcional. IAM mínimo lambda:InvokeFunction sobre la función/alias. RequestResponse espera resultado; Event solo acepta para ejecución posterior y exige reconocer los reintentos propios de AWS. Este nodo no reintenta. FunctionError o payload success:false/statusCode>=400 detienen el grafo. Una caída de transporte deja invocación incierta. Sin logs remotos en salida. Resultado data.payload, accepted, completed, executed_version; límite local 6 MiB síncrono/1 MiB asíncrono, timeout máximo 60 s. No es un trigger ni administra recursos o destinos.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'label' => 'Credencial AWS JSON', 'required' => true],
                'region' => ['type' => 'string', 'label' => 'Región AWS', 'required' => true, 'default' => 'us-east-1'],
                'function_name' => ['type' => 'string', 'label' => 'Nombre o ARN sin versión/alias', 'required' => true],
                'qualifier' => ['type' => 'string', 'label' => 'Versión o alias (opcional)', 'required' => false],
                'invocation_type' => ['type' => 'select', 'label' => 'Tipo de invocación', 'required' => true, 'default' => 'RequestResponse', 'options' => [
                    ['value' => 'RequestResponse', 'label' => 'Síncrona'], ['value' => 'Event', 'label' => 'Asíncrona (aceptación)']]],
                'acknowledge_async_retries' => ['type' => 'select', 'label' => 'Acepto posibles reintentos propios de AWS (asíncrona)', 'required' => false, 'default' => 'no', 'options' => [['value' => 'no', 'label' => 'No'], ['value' => 'yes', 'label' => 'Sí']]],
                'payload' => ['type' => 'textarea', 'label' => 'Payload JSON u objeto/lista resuelto', 'required' => true, 'default' => '{}'],
                'timeout_seconds' => ['type' => 'number', 'label' => 'Timeout (1–60 segundos)', 'required' => false, 'default' => 30]
            ]];
    }

    private function normalize(array $p): array
    {
        foreach (['credential_id', 'region', 'function_name'] as $key) {
            if (!is_string($p[$key] ?? null) || $p[$key] === '') {
                throw new \InvalidArgumentException('AWS Lambda: faltan credencial, región o función.');
            }
        }
        if (!preg_match('/^[a-z]{2}(?:-[a-z]+)+-\d+$/D', $p['region'])) {
            throw new \InvalidArgumentException('AWS Lambda: región inválida.');
        }
        $function = $p['function_name'];
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $function)) {
            if (!preg_match('/^arn:(aws|aws-cn|aws-us-gov):lambda:([a-z0-9-]+):[0-9]{12}:function:[A-Za-z0-9_-]{1,64}$/D', $function, $arn)
                || $arn[2] !== $p['region'] || ($arn[1] === 'aws-cn') !== str_starts_with($p['region'], 'cn-')
                || ($arn[1] === 'aws-us-gov') !== str_starts_with($p['region'], 'us-gov-')) {
                throw new \InvalidArgumentException('AWS Lambda: ARN o nombre de función inválido para la región.');
            }
        }
        $p['qualifier'] ??= '';
        if (!is_string($p['qualifier']) || ($p['qualifier'] !== '' && !preg_match('/^(?:\$LATEST|[A-Za-z0-9_-]{1,128})$/D', $p['qualifier']))) {
            throw new \InvalidArgumentException('AWS Lambda: versión/alias inválido.');
        }
        $p['invocation_type'] ??= 'RequestResponse';
        if (!in_array($p['invocation_type'], ['RequestResponse', 'Event'], true) || ($p['invocation_type'] === 'Event' && ($p['acknowledge_async_retries'] ?? 'no') !== 'yes')) {
            throw new \InvalidArgumentException('AWS Lambda: selecciona tipo y reconoce los reintentos de AWS para Event.');
        }
        $timeoutValue = $p['timeout_seconds'] ?? 30;
        $timeout = (is_int($timeoutValue) || is_string($timeoutValue)) ? filter_var($timeoutValue, FILTER_VALIDATE_INT) : false;
        if ($timeout === false || $timeout < 1 || $timeout > 60 || !array_key_exists('payload', $p)) {
            throw new \InvalidArgumentException('AWS Lambda: payload o timeout inválido.');
        }
        try {
            $raw = $p['payload'];
            if (is_string($raw)) {
                if (strlen($raw) > ($p['invocation_type'] === 'Event' ? 1048576 : 6291456)) {
                    throw new \InvalidArgumentException('AWS Lambda: payload excede el límite de la invocación.');
                }
                json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
                $p['body'] = $raw;
            } else {
                $p['body'] = json_encode($raw, JSON_THROW_ON_ERROR, 64);
            }
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('AWS Lambda: JSON inválido.');
        }
        if (strlen($p['body']) > ($p['invocation_type'] === 'Event' ? 1048576 : 6291456)) {
            throw new \InvalidArgumentException('AWS Lambda: payload excede el límite de la invocación.');
        }
        $p['timeout_seconds'] = $timeout;
        return $p;
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->normalize($parameters);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        $p = $this->normalize($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($p['credential_id']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('AWS Lambda: no se pudo recuperar la credencial; no se contactó AWS.');
        }
        $secrets = ($credential['type'] ?? '') === 'generic_bearer' && is_string($credential['data']['api_key'] ?? null)
            ? json_decode($credential['data']['api_key'], true) : null;
        foreach (['access_key_id', 'secret_access_key', 'session_token'] as $key) {
            if ($key === 'session_token' && !isset($secrets[$key])) {
                continue;
            }
            if (!is_string($secrets[$key] ?? null) || $secrets[$key] === '' || strlen($secrets[$key]) > 16384 || preg_match('/[\x00-\x20\x7f]/', $secrets[$key])) {
                throw new \InvalidArgumentException('AWS Lambda: credencial generic_bearer JSON incompleta.');
            }
        }
        if (!preg_match('/^[A-Z0-9]{16,128}$/D', $secrets['access_key_id'])) {
            throw new \InvalidArgumentException('AWS Lambda: identificador de acceso inválido.');
        }
        $host = 'lambda.' . $p['region'] . '.amazonaws.com' . (str_starts_with($p['region'], 'cn-') ? '.cn' : '');
        $path = '/2015-03-31/functions/' . rawurlencode($p['function_name']) . '/invocations';
        $query = $p['qualifier'] === '' ? '' : 'Qualifier=' . rawurlencode($p['qualifier']);
        $headers = ['host' => $host, 'content-type' => 'application/json', 'x-amz-date' => gmdate('Ymd\THis\Z', ($this->clock)()),
            'x-amz-content-sha256' => hash('sha256', $p['body']), 'x-amz-invocation-type' => $p['invocation_type']];
        if (isset($secrets['session_token'])) {
            $headers['x-amz-security-token'] = $secrets['session_token'];
        }
        $headers['authorization'] = AwsSignatureV4::authorization('POST', $path, $query, $headers, hash('sha256', $p['body']), $p['region'], $secrets, 'lambda', true);
        try {
            $response = ($this->client ?? new Client())->request('POST', 'https://' . $host . $path . ($query !== '' ? '?' . $query : ''), [
                'headers' => $headers, 'body' => $p['body'], 'timeout' => $p['timeout_seconds'], 'connect_timeout' => min(8, $p['timeout_seconds']),
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true, 'read_timeout' => $p['timeout_seconds']]);
            $stream = $response->getBody();
            $raw = '';
            try {
                while (!$stream->eof() && strlen($raw) <= 6291456) {
                    $part = $stream->read(min(65536, 6291457 - strlen($raw)));
                    if ($part === '' && !$stream->eof()) {
                        throw new \RuntimeException();
                    }
                    $raw .= $part;
                }
            } finally {
                $stream->close();
            }
            if (strlen($raw) > 6291456) {
                throw new \RuntimeException();
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('AWS Lambda: transporte o lectura falló; la invocación puede haberse realizado. Comprueba AWS antes de repetir.');
        }
        $status = $response->getStatusCode();
        if ($status !== ($p['invocation_type'] === 'Event' ? 202 : 200)) {
            throw new \RuntimeException('AWS Lambda: invocación rechazada HTTP ' . $status . '; sin reintento automático.');
        }
        if ($response->getHeaderLine('X-Amz-Function-Error') !== '') {
            throw new \RuntimeException('AWS Lambda: FunctionError; la función terminó con error. No se reintenta.');
        }
        $payload = null;
        $encoding = 'json';
        if ($p['invocation_type'] === 'RequestResponse') {
            try {
                $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $payload = base64_encode(str_replace(array_values($secrets), '[redactado]', $raw));
                $encoding = 'base64';
            }
            if (is_array($payload) && (($payload['success'] ?? null) === false || isset($payload['errorType']) || isset($payload['errorMessage'])
                || (isset($payload['statusCode']) && is_numeric($payload['statusCode']) && (int)$payload['statusCode'] >= 400))) {
                throw new \RuntimeException('AWS Lambda: el payload indica fallo funcional; revisa la función sin repetir sus efectos automáticamente.');
            }
        } elseif ($raw !== '') {
            throw new \RuntimeException('AWS Lambda: aceptación asíncrona con respuesta inesperada; comprueba AWS antes de repetir.');
        }
        if ($encoding === 'json') {
            $payload = $this->redact($payload, $secrets);
        }
        return ['success' => true, 'status_code' => $status, 'data' => ['accepted' => true, 'completed' => $p['invocation_type'] === 'RequestResponse',
            'payload' => $payload, 'encoding' => $encoding, 'executed_version' => $response->getHeaderLine('X-Amz-Executed-Version'),
            'request_id' => $response->getHeaderLine('X-Amzn-RequestId')]];
    }

    private function redact(mixed $value, array $secrets): mixed
    {
        if (is_string($value)) {
            return str_replace(array_values($secrets), '[redactado]', $value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $output = [];
        foreach ($value as $key => $item) {
            if (preg_match('/(?:password|secret|token|authorization|api[_-]?key|access[_-]?key)/i', (string)$key)) {
                continue;
            }
            $output[$key] = $this->redact($item, $secrets);
        }
        return $output;
    }
}
