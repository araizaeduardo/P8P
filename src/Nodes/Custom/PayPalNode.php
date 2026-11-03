<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use stdClass;
use Throwable;

class PayPalNode implements NodeInterface
{
    private const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'RUB', 'SGD', 'SEK', 'CHF', 'THB', 'USD'];
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
            'type' => 'paypal', 'name' => 'PayPal', 'category' => 'Community', 'icon' => 'CreditCard', 'is_start' => false,
            'description' => 'Crea o captura órdenes y consulta órdenes, capturas y reembolsos PayPal v2.',
            'help_text' => 'Guarda un JSON {"client_id":"...","client_secret":"..."} en API Key de una credencial Bearer Token / API Key. Usa credenciales separadas para sandbox y producción. Cada ejecución solicita un token OAuth local y realiza una acción, sin reintentos. Crear usa intent CAPTURE y purchase_units: lista de 1–10 objetos con amount {currency_code,value} y reference_id opcional (obligatorio y único si hay varias unidades). Importes positivos como texto decimal, no números JSON; máximo 2 decimales y ninguno en HUF/JPY/TWD. No admite payer, tarjetas ni datos de envío. Capturar requiere aprobación previa del comprador en PayPal; este nodo no aprueba órdenes. Crear/capturar exige request_id estable (UUID recomendado, hasta 38 ASCII): reutilízalo solo para la misma acción lógica y payload; usa claves distintas para crear y capturar. La retención de idempotencia depende del endpoint y de PayPal; confirma la ventana aplicable antes de repetir. Una escritura incierta requiere comprobar el recurso, no crear otra clave. Salida data.id/status, importes y capturas filtradas, y approve_url cuando esté disponible; no incluye payer ni token OAuth. PAYMENT.SALE.COMPLETED no representa todos los pagos v2. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'label' => 'Credencial PayPal (JSON)', 'required' => true],
                'environment' => ['type' => 'select', 'label' => 'Entorno', 'required' => true, 'default' => 'sandbox', 'options' => [
                    ['value' => 'sandbox', 'label' => 'Sandbox (pruebas)'], ['value' => 'production', 'label' => 'Producción']
                ]],
                'operation' => ['type' => 'select', 'label' => 'Operación', 'required' => true, 'default' => 'get_order', 'options' => [
                    ['value' => 'create_order', 'label' => 'Crear orden CAPTURE'], ['value' => 'capture_order', 'label' => 'Capturar orden aprobada'],
                    ['value' => 'get_order', 'label' => 'Consultar orden'], ['value' => 'get_capture', 'label' => 'Consultar captura'],
                    ['value' => 'get_refund', 'label' => 'Consultar reembolso']
                ]],
                'order_id' => ['type' => 'string', 'label' => 'ID de orden (consultar/capturar)', 'required' => false],
                'capture_id' => ['type' => 'string', 'label' => 'ID de captura (consultar)', 'required' => false],
                'refund_id' => ['type' => 'string', 'label' => 'ID de reembolso (consultar)', 'required' => false],
                'request_id' => ['type' => 'string', 'label' => 'Clave idempotente estable (crear/capturar)', 'required' => false],
                'purchase_units' => ['type' => 'textarea', 'label' => 'Unidades (crear, JSON o expresión)', 'required' => false,
                    'placeholder' => '[{"amount":{"currency_code":"USD","value":"10.00"}}]']
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
        // Engine ya resuelve ExpressionEvaluator; jamás interpolar código o datos por segunda vez.
        $config = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($config['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('PayPal: no se pudo recuperar o descifrar la credencial en el gestor de p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('PayPal: la credencial seleccionada no existe.');
        }
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException('PayPal: selecciona una credencial Bearer Token / API Key con client_id y client_secret en JSON.');
        }
        $auth = $this->credential($credential['data']['api_key']);
        $client = $this->client ?? new Client();
        $baseOptions = ['timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false];
        $tokenBody = $this->request($client, 'POST', $config['origin'] . '/v1/oauth2/token', $baseOptions + [
            'headers' => ['Authorization' => 'Basic ' . base64_encode($auth['client_id'] . ':' . $auth['client_secret']), 'Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'client_credentials']
        ], false, true);
        $token = $tokenBody['body']['access_token'] ?? null;
        if (!is_string($token) || strlen($token) > 8192 || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1
            || !is_string($tokenBody['body']['token_type'] ?? null)
            || strcasecmp($tokenBody['body']['token_type'], 'Bearer') !== 0
            || !is_int($tokenBody['body']['expires_in'] ?? null) || $tokenBody['body']['expires_in'] < 1) {
            throw new RuntimeException('PayPal: respuesta OAuth inválida o incompleta; no se ejecutó la acción.');
        }
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        if ($config['write']) {
            $headers['PayPal-Request-Id'] = $config['request_id'];
            $headers['Prefer'] = 'return=representation';
        }
        $options = $baseOptions + ['headers' => $headers];
        if ($config['write']) {
            $options['json'] = $config['payload'];
        }
        $response = $this->request($client, $config['write'] ? 'POST' : 'GET', $config['origin'] . $config['path'], $options, $config['write'], false);
        try {
            $data = $this->result($response['body'], $config);
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            foreach ([$token, $auth['client_id'], $auth['client_secret'], base64_encode($auth['client_id'] . ':' . $auth['client_secret'])] as $secret) {
                if (str_contains($encoded, $secret)) {
                    throw new InvalidArgumentException();
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException('PayPal: respuesta de acción inválida o incompleta.' . $this->notice($config['write']), $response['status']);
        }
        return ['success' => true, 'status_code' => $response['status'], 'data' => $data];
    }

    private function prepare(array $parameters): array
    {
        $credential = $this->text($parameters['credential_id'] ?? null, 256);
        $environment = $parameters['environment'] ?? 'sandbox';
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new InvalidArgumentException('PayPal: el entorno debe ser sandbox o production.');
        }
        $operation = $parameters['operation'] ?? 'get_order';
        if (!in_array($operation, ['create_order', 'capture_order', 'get_order', 'get_capture', 'get_refund'], true)) {
            throw new InvalidArgumentException('PayPal: operación inválida.');
        }
        $write = in_array($operation, ['create_order', 'capture_order'], true);
        $config = ['credential_id' => $credential, 'environment' => $environment, 'operation' => $operation, 'write' => $write,
            'origin' => $environment === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com'];
        if ($write) {
            $requestId = $parameters['request_id'] ?? null;
            if (!is_string($requestId) || preg_match('/^[A-Za-z0-9_-]{1,38}$/D', $requestId) !== 1) {
                throw new InvalidArgumentException('PayPal: crear/capturar exige request_id estable de 1 a 38 caracteres ASCII, letras, números, guion o guion bajo.');
            }
            $config['request_id'] = $requestId;
        }
        if ($operation === 'create_order') {
            $config['path'] = '/v2/checkout/orders';
            $config['payload'] = ['intent' => 'CAPTURE', 'purchase_units' => $this->units($parameters['purchase_units'] ?? null)];
        } else {
            $field = match ($operation) { 'get_capture' => 'capture_id', 'get_refund' => 'refund_id', default => 'order_id' };
            $id = $this->id($parameters[$field] ?? null);
            $config['id'] = $id;
            $config['path'] = match ($operation) {
                'capture_order' => '/v2/checkout/orders/' . $id . '/capture',
                'get_capture' => '/v2/payments/captures/' . $id,
                'get_refund' => '/v2/payments/refunds/' . $id,
                default => '/v2/checkout/orders/' . $id
            };
            if ($write) {
                $config['payload'] = new stdClass();
            }
        }
        return $config;
    }

    private function units(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('PayPal: purchase_units contiene JSON inválido.');
            }
        }
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 10) {
            throw new InvalidArgumentException('PayPal: purchase_units debe ser una lista de 1 a 10 unidades.');
        }
        $units = []; $references = []; $currency = null;
        foreach ($value as $unit) {
            $unit = $this->object($unit, ['reference_id', 'amount']);
            $prepared = ['amount' => $this->money($unit['amount'] ?? null)];
            if ($currency !== null && $currency !== $prepared['amount']['currency_code']) {
                throw new InvalidArgumentException('PayPal: todas las unidades deben usar la misma moneda.');
            }
            $currency = $prepared['amount']['currency_code'];
            if (array_key_exists('reference_id', $unit) || count($value) > 1) {
                $reference = $this->text($unit['reference_id'] ?? null, 256);
                if (isset($references[$reference])) {
                    throw new InvalidArgumentException('PayPal: reference_id debe ser único en cada unidad.');
                }
                $references[$reference] = true;
                $prepared['reference_id'] = $reference;
            }
            $units[] = $prepared;
        }
        return $units;
    }

    private function money(mixed $value): array
    {
        $value = $this->object($value, ['currency_code', 'value']);
        $currency = $value['currency_code'] ?? null; $amount = $value['value'] ?? null;
        if (!is_string($currency) || !in_array($currency, self::CURRENCIES, true) || !is_string($amount)
            || strlen($amount) > 32 || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $amount) !== 1
            || preg_match('/[1-9]/', $amount) !== 1 || (in_array($currency, ['HUF', 'JPY', 'TWD'], true) && str_contains($amount, '.'))) {
            throw new InvalidArgumentException('PayPal: moneda no admitida o importe inválido; usa texto decimal positivo, hasta 2 decimales, sin decimales en HUF/JPY/TWD.');
        }
        return ['currency_code' => $currency, 'value' => $amount];
    }

    private function credential(string $value): array
    {
        try {
            $config = $this->object(json_decode($value, false, 8, JSON_THROW_ON_ERROR), ['client_id', 'client_secret']);
            foreach (['client_id', 'client_secret'] as $field) {
                if (!is_string($config[$field] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $config[$field]) !== 1) {
                    throw new InvalidArgumentException();
                }
            }
            return $config;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('PayPal: configuración de credencial inválida; requiere un objeto JSON con client_id y client_secret.');
        }
    }

    private function request(ClientInterface $client, string $method, string $url, array $options, bool $write, bool $oauth): array
    {
        $phase = $oauth ? 'OAuth' : 'acción';
        try {
            $response = $client->request($method, $url, $options);
            $status = $response->getStatusCode();
            $raw = $response->getBody()->read(1048577);
        } catch (Throwable $e) {
            throw new RuntimeException("PayPal: fallo de transporte o lectura durante {$phase}." . $this->notice($write));
        }
        if ($status < 200 || $status >= 300) {
            $reason = match (true) {
                $status === 401 => 'autenticación rechazada; revisa las credenciales y el entorno',
                $status === 403 => 'permisos insuficientes o cuenta restringida',
                $status === 404 => 'recurso inexistente o inaccesible en este entorno',
                $status === 422 => 'operación no procesable; comprueba aprobación, estado de la orden, importe e idempotencia',
                $status === 429 => 'límite de solicitudes alcanzado; espera antes de repetir',
                $status >= 500 => 'error del servicio PayPal',
                $status >= 300 && $status < 400 => 'redirección rechazada',
                default => 'solicitud rechazada; revisa configuración y datos'
            };
            throw new RuntimeException("PayPal: {$reason} durante {$phase} (HTTP {$status})." . $this->notice($write), $status);
        }
        try {
            $body = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            if (strlen($raw) > 1048576 || !$body instanceof stdClass || isset($body->error) || isset($body->name)) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable $e) {
            throw new RuntimeException("PayPal: respuesta JSON inválida durante {$phase}." . $this->notice($write), $status);
        }
        return ['status' => $status, 'body' => get_object_vars($body)];
    }

    private function result(array $body, array $config): array
    {
        $data = ['id' => $this->id($body['id'] ?? null)];
        if (isset($config['id']) && $config['id'] !== $data['id']) {
            throw new InvalidArgumentException();
        }
        if (!is_string($body['status'] ?? null) || preg_match('/^[A-Z_]{1,40}$/D', $body['status']) !== 1) {
            throw new InvalidArgumentException();
        }
        $data['status'] = $body['status'];
        if (in_array($config['operation'], ['get_capture', 'get_refund'], true)) {
            $data['amount'] = $this->money($body['amount'] ?? null);
        } else {
            if (isset($body['intent'])) {
                if (!in_array($body['intent'], ['CAPTURE', 'AUTHORIZE'], true)) {
                    throw new InvalidArgumentException();
                }
                $data['intent'] = $body['intent'];
            }
            if (array_key_exists('purchase_units', $body)) {
                if (!is_array($body['purchase_units']) || count($body['purchase_units']) > 10) {
                    throw new InvalidArgumentException();
                }
                $data['purchase_units'] = [];
                foreach ($body['purchase_units'] as $unit) {
                    if (!$unit instanceof stdClass) {
                        throw new InvalidArgumentException();
                    }
                    $filtered = [];
                    if (isset($unit->amount)) {
                        $filtered['amount'] = $this->money($unit->amount);
                    }
                    if (isset($unit->reference_id)) {
                        $filtered['reference_id'] = $this->text($unit->reference_id, 256, false);
                    }
                    if (isset($unit->payments->captures)) {
                        if (!is_array($unit->payments->captures) || count($unit->payments->captures) > 100) {
                            throw new InvalidArgumentException();
                        }
                        $filtered['captures'] = [];
                        foreach ($unit->payments->captures as $capture) {
                            if (!$capture instanceof stdClass || !is_string($capture->status ?? null)
                                || preg_match('/^[A-Z_]{1,40}$/D', $capture->status) !== 1) {
                                throw new InvalidArgumentException();
                            }
                            $filtered['captures'][] = ['id' => $this->id($capture->id ?? null), 'status' => $capture->status,
                                'amount' => $this->money($capture->amount ?? null)];
                        }
                    }
                    $data['purchase_units'][] = $filtered;
                }
            }
            foreach ($body['links'] ?? [] as $link) {
                if ($link instanceof stdClass && in_array($link->rel ?? null, ['approve', 'payer-action'], true)) {
                    $data['approve_url'] = $this->approvalUrl($link->href ?? null, $config['environment'], $data['id']);
                    break;
                }
            }
        }
        return $data;
    }

    private function approvalUrl(mixed $value, string $environment, string $id): string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            throw new InvalidArgumentException();
        }
        $url = parse_url($value);
        $hosts = $environment === 'sandbox' ? ['www.sandbox.paypal.com', 'sandbox.paypal.com'] : ['www.paypal.com', 'paypal.com'];
        if ($url === false || ($url['scheme'] ?? '') !== 'https' || !in_array($url['host'] ?? '', $hosts, true)
            || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['port']) || isset($url['fragment'])
            || ($url['path'] ?? '') !== '/checkoutnow') {
            throw new InvalidArgumentException();
        }
        parse_str($url['query'] ?? '', $query);
        if (($query['token'] ?? null) !== $id || array_diff(array_keys($query), ['token', 'useraction']) !== []
            || (isset($query['useraction']) && $query['useraction'] !== 'commit')) {
            throw new InvalidArgumentException();
        }
        return $value;
    }

    private function object(mixed $value, array $keys): array
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), $keys) !== []) {
            throw new InvalidArgumentException('PayPal: objeto de configuración inválido o con campos no admitidos.');
        }
        return $value;
    }

    private function id(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z0-9]{1,36}$/D', $value) !== 1) {
            throw new InvalidArgumentException('PayPal: indica el ID alfanumérico correcto de orden, captura o reembolso.');
        }
        return $value;
    }

    private function text(mixed $value, int $max, bool $rejectExpression = true): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value, 'UTF-8') > $max || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || ($rejectExpression && preg_match('/{{\s*[A-Za-z0-9_.]+\s*}}/', $value) === 1)) {
            throw new InvalidArgumentException('PayPal: campo requerido vacío, inválido o con expresión sin resolver.');
        }
        return trim($value);
    }

    private function notice(bool $write): string
    {
        return $write ? ' El resultado de escritura puede ser incierto; consulta la orden y conserva el mismo request_id antes de repetir, sin reintento automático.' : '';
    }
}
