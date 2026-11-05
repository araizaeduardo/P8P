<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use Throwable;

/** Stripe v1: no automatic confirmation/capture or raw payment instrument data. */
class StripeNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return ['type' => 'stripe', 'name' => 'Stripe Payments', 'category' => 'Community', 'icon' => 'CreditCard', 'is_start' => false,
            'description' => 'Clientes, Checkout y PaymentIntents con idempotencia explícita.',
            'help_text' => 'generic_bearer API Key JSON: {"secret_key":"sk_test_ficticia","mode":"test"}; producción exige sk_live y mode live. API v1; versión opcional api_version en credencial. No tarjetas, CVV, confirmación, captura ni reintentos. customer_create usa email/name/description; payment_intent_create usa amount entero en unidad menor, currency, customer y payment_method pm_ opcionales; siempre confirm=false. Checkout mode=payment, line_items [{price:"price_…",quantity:1}], success_url/cancel_url HTTPS, customer opcional; el cliente paga en Checkout. idempotency_key requerida para crear: reutilizarla únicamente para la misma operación lógica y parámetros. Consultas payments representan PaymentIntents, no liquidaciones. Una página 1–100 con starting_after. Salida data filtrada: no client_secret, PII, metadata ni datos de tarjeta. Los PaymentIntents se completan mediante integración Stripe.js/SDK externa con autenticación/SCA; este nodo no entrega client_secret a historiales. Checkout devuelve URL alojada necesaria para pago (tratar como enlace privado). Ante transporte incierto verificar Stripe antes de repetir con la misma clave. Eventos se configuran aparte en Triggers.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'label' => 'Credencial Stripe JSON', 'required' => true],
                'operation' => ['type' => 'select', 'label' => 'Operación', 'default' => 'payment_get', 'required' => true, 'options' => array_map(fn($v) => ['value' => $v, 'label' => $v], ['customer_create', 'customer_get', 'customer_list', 'checkout_create', 'payment_intent_create', 'payment_get', 'payment_list'])],
                'resource_id' => ['type' => 'string', 'label' => 'ID cus_ o pi_ (get)', 'required' => false],
                'data' => ['type' => 'textarea', 'label' => 'Datos JSON (create)', 'required' => false],
                'idempotency_key' => ['type' => 'string', 'label' => 'Idempotency-Key estable (create)', 'required' => false],
                'limit' => ['type' => 'number', 'label' => 'Tamaño de página', 'default' => 10, 'min' => 1, 'max' => 100],
                'starting_after' => ['type' => 'string', 'label' => 'Último ID de página anterior', 'required' => false]
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine already resolves expressions exactly once.
        $p = $this->prepare($parameters);
        $c = $this->credential($p['credential_id']);
        $headers = ['Authorization' => 'Bearer ' . $c['secret_key'], 'Accept' => 'application/json'];
        if (isset($c['api_version'])) { $headers['Stripe-Version'] = $c['api_version']; }
        if ($p['method'] === 'POST') { $headers['Idempotency-Key'] = $p['idempotency_key']; }
        $options = ['headers' => $headers, 'timeout' => 25, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true];
        $options[$p['method'] === 'GET' ? 'query' : 'form_params'] = $p['payload'];
        try { $response = ($this->client ?? new Client())->request($p['method'], 'https://api.stripe.com/v1/' . $p['path'], $options); }
        catch (Throwable $e) { throw new RuntimeException('Stripe: fallo de transporte; resultado de escritura incierto. No se reintentó.'); }
        try { $raw = $response->getBody()->read(2097153); }
        catch (Throwable $e) { throw new RuntimeException('Stripe: respuesta interrumpida; resultado de escritura incierto. No se reintentó.'); }
        if (strlen($raw) > 2097152) { throw new RuntimeException('Stripe: respuesta demasiado grande.'); }
        $body = json_decode($raw, true);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || !is_array($body) || isset($body['error'])) {
            throw new RuntimeException('Stripe: API rechazó la operación (HTTP ' . $response->getStatusCode() . '); revisa permisos, datos y modo. No se reintentó.');
        }
        if (str_ends_with($p['operation'], '_list')) {
            if (($body['object'] ?? null) !== 'list' || !is_array($body['data'] ?? null) || !array_is_list($body['data']) || count($body['data']) > $p['payload']['limit'] || !is_bool($body['has_more'] ?? null)) { throw new RuntimeException('Stripe: página inválida.'); }
            $items = array_map(fn($item) => $this->result($item, $p['object'], $c['mode']), $body['data']);
            return ['success' => true, 'operation' => $p['operation'], 'data' => ['items' => $items, 'has_more' => $body['has_more'], 'next_cursor' => $body['has_more'] && $items ? end($items)['id'] : null]];
        }
        return ['success' => true, 'operation' => $p['operation'], 'data' => $this->result($body, $p['object'], $c['mode'])];
    }

    private function prepare(array $p): array
    {
        if (array_diff(array_keys($p), ['credential_id','operation','resource_id','data','idempotency_key','limit','starting_after','_sub_connections'])) { throw new InvalidArgumentException('Stripe: parámetro no admitido.'); }
        $this->text($p['credential_id'] ?? null, 255);
        $op = $p['operation'] ?? 'payment_get';
        $map = ['customer_create' => ['POST', 'customers', 'customer'], 'customer_get' => ['GET', 'customers', 'customer'], 'customer_list' => ['GET', 'customers', 'customer'], 'checkout_create' => ['POST', 'checkout/sessions', 'checkout.session'], 'payment_intent_create' => ['POST', 'payment_intents', 'payment_intent'], 'payment_get' => ['GET', 'payment_intents', 'payment_intent'], 'payment_list' => ['GET', 'payment_intents', 'payment_intent']];
        if (!is_string($op) || !isset($map[$op])) { throw new InvalidArgumentException('Stripe: operación inválida.'); }
        [$method, $path, $object] = $map[$op]; $payload = [];
        if (str_ends_with($op, '_get')) {
            $id = $p['resource_id'] ?? null; $this->id($id, $object === 'customer' ? 'cus' : 'pi'); $path .= '/' . $id;
        } elseif (str_ends_with($op, '_list')) {
            $limit = $p['limit'] ?? 10;
            if (is_string($limit) && preg_match('/^[0-9]+$/D', $limit)) { $limit = (int)$limit; }
            if (!is_int($limit) || $limit < 1 || $limit > 100) { throw new InvalidArgumentException('Stripe: limit debe ser entero 1–100.'); }
            $payload['limit'] = $limit;
            if (($p['starting_after'] ?? '') !== '') { $this->id($p['starting_after'], $object === 'customer' ? 'cus' : 'pi'); $payload['starting_after'] = $p['starting_after']; }
        } else {
            $this->text($p['idempotency_key'] ?? null, 255);
            if (preg_match('/[\x00-\x20\x7f]/', $p['idempotency_key'])) { throw new InvalidArgumentException('Stripe: Idempotency-Key inválida.'); }
            $payload = $this->object($p['data'] ?? null);
            $allowed = match ($op) { 'customer_create' => ['email','name','description'], 'payment_intent_create' => ['amount','currency','customer','payment_method','description'], default => ['line_items','success_url','cancel_url','customer','client_reference_id'] };
            if (in_array(null, $payload, true) || array_diff(array_keys($payload), $allowed)) { throw new InvalidArgumentException('Stripe: campo no admitido; nunca enviar tarjetas, CVV, confirm ni capture.'); }
            foreach (['email','name','description','client_reference_id'] as $field) { if (isset($payload[$field])) { $this->text($payload[$field], 500); } }
            if (isset($payload['email']) && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Stripe: email inválido.'); }
            if (isset($payload['customer'])) { $this->id($payload['customer'], 'cus'); }
            if (isset($payload['payment_method'])) { $this->id($payload['payment_method'], 'pm'); }
            if ($op === 'customer_create' && !$payload) { throw new InvalidArgumentException('Stripe: proporciona al menos email, name o description.'); }
            if ($op === 'payment_intent_create') {
                if (!is_int($payload['amount'] ?? null) || $payload['amount'] < 1 || $payload['amount'] > 99999999 || !is_string($payload['currency'] ?? null) || !preg_match('/^[a-z]{3}$/D', $payload['currency'])) { throw new InvalidArgumentException('Stripe: amount entero positivo en unidad menor y currency ISO minúscula requeridos.'); }
                $payload['confirm'] = 'false';
            }
            if ($op === 'checkout_create') {
                foreach (['success_url','cancel_url'] as $field) {
                    $url = $payload[$field] ?? null; $parts = is_string($url) ? parse_url($url) : false;
                    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || strlen($url) > 2048) { throw new InvalidArgumentException('Stripe: URLs HTTPS de retorno requeridas.'); }
                }
                if (!is_array($payload['line_items'] ?? null) || !array_is_list($payload['line_items']) || count($payload['line_items']) < 1 || count($payload['line_items']) > 100) { throw new InvalidArgumentException('Stripe: 1–100 line_items requeridos.'); }
                foreach ($payload['line_items'] as $line) {
                    if (!is_array($line) || array_diff(array_keys($line), ['price','quantity'])) { throw new InvalidArgumentException('Stripe: línea inválida.'); }
                    $this->id($line['price'] ?? null, 'price');
                    if (!is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || $line['quantity'] > 999999) { throw new InvalidArgumentException('Stripe: quantity entero positivo requerido.'); }
                }
                $payload['mode'] = 'payment';
            }
        }
        return compact('method','path','object','payload') + ['operation' => $op, 'credential_id' => $p['credential_id'], 'idempotency_key' => $p['idempotency_key'] ?? ''];
    }

    private function credential(string $id): array
    {
        try { $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id); }
        catch (Throwable $e) { throw new RuntimeException('Stripe: no fue posible recuperar o descifrar la credencial.'); }
        if (($credential['type'] ?? '') !== 'generic_bearer') { throw new InvalidArgumentException('Stripe: requiere credencial generic_bearer.'); }
        $c = $this->object($credential['data']['api_key'] ?? null);
        if (!in_array($c['mode'] ?? null, ['test','live'], true) || !is_string($c['secret_key'] ?? null) || !preg_match('/^sk_' . $c['mode'] . '_[A-Za-z0-9]+$/D', $c['secret_key'])) { throw new InvalidArgumentException('Stripe: secret_key sk_test/sk_live y mode coincidente requeridos.'); }
        if (isset($c['api_version']) && (!is_string($c['api_version']) || !preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}(?:\.[a-z]+)?$/D', $c['api_version']))) { throw new InvalidArgumentException('Stripe: api_version inválida.'); }
        return $c;
    }

    private function result(mixed $body, string $object, string $mode): array
    {
        if (!is_array($body) || ($body['object'] ?? null) !== $object || !is_string($body['id'] ?? null)) { throw new RuntimeException('Stripe: recurso inválido.'); }
        if (($body['livemode'] ?? null) !== ($mode === 'live')) { throw new RuntimeException('Stripe: entorno de respuesta no coincide con la credencial.'); }
        $allowed = ['id','object','created','livemode','status','amount','amount_received','amount_capturable','currency','customer','payment_intent','payment_status','amount_total','expires_at'];
        $out = [];
        foreach ($allowed as $key) { if (array_key_exists($key, $body) && (is_scalar($body[$key]) || $body[$key] === null)) { $out[$key] = $body[$key]; } }
        if ($object === 'checkout.session' && isset($body['url'])) {
            if (!is_string($body['url']) || !preg_match('~^https://checkout\.stripe\.com/[^\s]+$~D', $body['url'])) { throw new RuntimeException('Stripe: URL Checkout inesperada.'); }
            $out['url'] = $body['url'];
        }
        return $out;
    }

    private function text(mixed $value, int $max): void
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) { throw new InvalidArgumentException('Stripe: texto requerido inválido.'); }
    }

    private function id(mixed $value, string $prefix): void
    {
        if (!is_string($value) || !preg_match('/^' . $prefix . '_[A-Za-z0-9]{1,200}$/D', $value)) { throw new InvalidArgumentException('Stripe: ID de recurso inválido.'); }
    }

    private function object(mixed $value): array
    {
        try {
            if (is_string($value)) { if (strlen($value) > 131072) { throw new \Exception(); } $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR); }
            if ($value instanceof \stdClass) { $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR); }
            if (!is_array($value) || ($value !== [] && array_is_list($value)) || strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 131072) { throw new \Exception(); }
            $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
            return $value;
        } catch (Throwable $e) { throw new InvalidArgumentException('Stripe: objeto JSON inválido.'); }
    }
}
