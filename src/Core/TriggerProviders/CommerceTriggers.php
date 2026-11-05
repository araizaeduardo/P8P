<?php

namespace P8P\Core\TriggerProviders;

use InvalidArgumentException;
use LogicException;
use P8P\Interfaces\TriggerProviderInterface;

/** Verification only; TriggerManager persists/deduplicates before ACK. */
class CommerceTriggers implements TriggerProviderInterface
{
    public function definitions(): array
    {
        $definitions = [];
        foreach (['payment_intent.succeeded','payment_intent.payment_failed','checkout.session.completed'] as $event) {
            $definitions['stripe_' . str_replace('.', '_', $event)] = [
                'name' => 'Stripe: ' . $event, 'node_type' => 'stripe', 'event' => $event, 'transport' => 'webhook',
                'config_fields' => ['mode' => ['type' => 'select', 'label' => 'Entorno', 'required' => true, 'options' => [['value'=>'test','label'=>'test'],['value'=>'live','label'=>'live']]], 'account_id' => ['type'=>'string','label'=>'Cuenta Connect acct_ (vacío para cuenta directa)','required'=>false]],
                'secret_fields' => ['webhook_secret' => ['type'=>'string','label'=>'Secreto whsec_ exclusivo del endpoint/entorno','required'=>true]],
                'help' => 'Registrar manualmente este evento exacto y URL en Stripe, con secreto whsec_ único del endpoint y entorno. mode debe coincidir con livemode firmado. Para Connect indicar account_id firmado; vacío solo cuenta directa (endpoint exclusivo de esa cuenta). Snapshot events v1, no thin events. Firma raw-body con timestamp ±300s y rotación v1 múltiple. Deduplicación por event.id persistida antes de ACK. checkout.session.completed NO equivale a pago exitoso: comprobar payment_status; para medios asíncronos usar payment_intent.succeeded. No se confirma ni captura dinero.',
                'docs' => ['https://docs.stripe.com/webhooks','https://docs.stripe.com/api/events/types','https://docs.stripe.com/events/manage-webhook-endpoints']];
        }
        foreach (['products'=>['create','update','delete'], 'customers'=>['create','update','delete','data_request','redact'], 'orders'=>['create','updated','paid','cancelled','delete'], 'carts'=>['create','update'], 'shop'=>['redact']] as $resource => $actions) {
            foreach ($actions as $action) {
                $event = $resource . '/' . $action;
                $definitions['shopify_' . $resource . '_' . $action] = [
                    'name' => 'Shopify: ' . $event, 'node_type'=>'shopify', 'event'=>$event, 'transport'=>'webhook',
                    'config_fields' => ['shop_domain'=>['type'=>'string','label'=>'Tienda canónica ejemplo.myshopify.com','required'=>true]],
                    'secret_fields' => ['app_secret'=>['type'=>'string','label'=>'Client secret de app exclusiva para esta tienda/tópico','required'=>true]],
                    'help' => 'Registro remoto manual. Requiere app exclusiva instalada solo en esta tienda y suscrita solo al tópico de este receptor; no reutilizar app_secret entre receptores activos. HMAC base64 autentica exclusivamente cuerpo original, no headers shop/topic; estos se comprueban como metadatos y no sustituyen ese aislamiento. Core debe impedir mismo app_secret en receptores activos. Identidad por hash del cuerpo firmado (no delivery ID header no firmado): dos eventos idénticos se colapsan; cambios de payload tras editar suscripción requieren receptor nuevo. Productos read_products; clientes read_customers y datos protegidos aprobados; pedidos/carritos read_orders; historial de pedidos >60 días requiere read_all_orders aprobado. carts/create y carts/update SOLO Online Store; no Storefront personalizado. App Store requiere customers/data_request, customers/redact y shop/redact configurados vía TOML/Dashboard; por el aislamiento de un receptor/app, gestionar los demás tópicos obligatorios en un endpoint externo propio. La verificación y entrega de estos eventos NO ejecuta borrado ni satisface por sí sola la respuesta legal: workflow debe gestionar y comprobar la solicitud. Shopify no firma timestamp: deduplicación durable impide repetición exacta mientras se conserva registro. No hay renovación OAuth automática.',
                    'docs'=>['https://shopify.dev/docs/api/admin-graphql/2026-10/enums/WebhookSubscriptionTopic','https://shopify.dev/docs/apps/build/webhooks/verify-deliveries','https://shopify.dev/docs/apps/build/compliance/privacy-law-compliance']];
            }
        }
        return $definitions;
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        throw new LogicException('Commerce: utiliza webhook, no polling.');
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        $d = $this->definitions()[$key] ?? null;
        $raw = $request['raw_body'] ?? null; $h = $request['headers'] ?? null;
        if (!$d || ($request['method'] ?? '') !== 'POST' || !is_string($raw) || strlen($raw) > 1048576 || !is_array($h)) { throw new InvalidArgumentException('Commerce: petición inválida.'); }
        if ($d['node_type'] === 'stripe') {
            $secret = $secrets['webhook_secret'] ?? null; $header = $h['stripe-signature'] ?? null;
            if (!is_string($secret) || !preg_match('/^whsec_[A-Za-z0-9]+$/D', $secret) || !is_string($header) || strlen($header) > 4096) { throw new InvalidArgumentException('Stripe: firma/credencial inválida.'); }
            $timestamp = null; $signatures = [];
            foreach (explode(',', $header) as $part) {
                [$field,$value] = array_pad(explode('=', trim($part), 2), 2, '');
                if ($field === 't') { if ($timestamp !== null || !preg_match('/^[0-9]{1,12}$/D', $value)) { throw new InvalidArgumentException('Stripe: timestamp inválido.'); } $timestamp = (int)$value; }
                if ($field === 'v1' && preg_match('/^[a-f0-9]{64}$/D', $value)) { $signatures[] = $value; }
            }
            if ($timestamp === null || abs($now - $timestamp) > 300 || !$signatures) { throw new InvalidArgumentException('Stripe: firma fuera de tolerancia.'); }
            $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret); $valid = false;
            foreach ($signatures as $signature) { $valid = hash_equals($expected, $signature) || $valid; }
            if (!$valid) { throw new InvalidArgumentException('Stripe: firma inválida.'); }
            $body = $this->body($raw);
            if (!in_array($config['mode'] ?? null, ['test','live'], true) || ($body['livemode'] ?? null) !== ($config['mode'] === 'live') || ($body['object'] ?? null) !== 'event' || !is_string($body['id'] ?? null) || !preg_match('/^evt_[A-Za-z0-9]{1,200}$/D', $body['id'])) { throw new InvalidArgumentException('Stripe: evento o entorno inválido.'); }
            $account = $config['account_id'] ?? '';
            if (!is_string($account) || ($account !== '' && !preg_match('/^acct_[A-Za-z0-9]+$/D', $account)) || ($body['account'] ?? '') !== $account) { throw new InvalidArgumentException('Stripe: cuenta del evento no coincide.'); }
            if (($body['type'] ?? null) !== $d['event']) { return $this->ack([]); }
            $object = $body['data']['object'] ?? null;
            $type = str_starts_with($d['event'], 'payment_intent.') ? 'payment_intent' : 'checkout.session';
            if (!is_array($object) || ($object['object'] ?? null) !== $type || !is_string($object['id'] ?? null) || ($object['livemode'] ?? null) !== ($config['mode'] === 'live')) { throw new InvalidArgumentException('Stripe: snapshot de evento inválido.'); }
            $data = $this->pick($object, ['id','object','livemode','status','amount','amount_received','amount_capturable','currency','customer','payment_intent','payment_status','amount_total']);
            $data['event_id'] = $body['id']; $data['created'] = is_int($body['created'] ?? null) ? $body['created'] : null;
            return $this->ack([['id'=>$body['id'], 'type'=>$d['event'], 'data'=>$data]]);
        }
        $secret = $secrets['app_secret'] ?? null; $shop = $config['shop_domain'] ?? null;
        if (!is_string($secret) || strlen($secret) < 16 || strlen($secret) > 4096 || !is_string($shop) || !preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $shop) || ($h['x-shopify-shop-domain'] ?? null) !== $shop) { throw new InvalidArgumentException('Shopify: app/tienda no coincide.'); }
        $signature = $h['x-shopify-hmac-sha256'] ?? null;
        if (!is_string($signature) || !hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), $signature)) { throw new InvalidArgumentException('Shopify: firma inválida.'); }
        if (($h['x-shopify-topic'] ?? null) !== $d['event']) { throw new InvalidArgumentException('Shopify: tópico no coincide con receptor exclusivo.'); }
        $body = $this->body($raw); [$resource,$action] = explode('/', $d['event']);
        if ($action === 'data_request' || $action === 'redact') {
            if (!is_int($body['shop_id'] ?? null) || ($body['shop_domain'] ?? null) !== $shop) { throw new InvalidArgumentException('Shopify: payload de cumplimiento inválido.'); }
            $data = $this->pick($body, ['shop_id','shop_domain']);
            // Only opaque IDs needed to resolve compliance requests; no email/phone/address.
            if (is_array($body['customer'] ?? null) && (is_int($body['customer']['id'] ?? null) || is_string($body['customer']['id'] ?? null))) { $data['customer_id'] = $body['customer']['id']; }
            foreach (['orders_requested','orders_to_redact'] as $field) {
                if (isset($body[$field])) {
                    if (!is_array($body[$field]) || !array_is_list($body[$field]) || count($body[$field]) > 1000) { throw new InvalidArgumentException('Shopify: lista de cumplimiento inválida.'); }
                    foreach ($body[$field] as $id) { if ((!is_int($id) && !is_string($id)) || !preg_match('/^[0-9]{1,25}$/D', (string)$id)) { throw new InvalidArgumentException('Shopify: ID de cumplimiento inválido.'); } }
                    $data[$field] = $body[$field];
                }
            }
            if (isset($body['data_request']['id']) && (is_int($body['data_request']['id']) || is_string($body['data_request']['id']))) { $data['request_id'] = $body['data_request']['id']; }
        } else {
            $id = $body['id'] ?? null;
            if ((!is_int($id) && !is_string($id)) || strlen((string)$id) > 255 || (string)$id === '') { throw new InvalidArgumentException('Shopify: ID de recurso inválido.'); }
            if ($resource !== 'carts' && !preg_match('/^[0-9]{1,25}$/D', (string)$id)) { throw new InvalidArgumentException('Shopify: ID de recurso inválido.'); }
            if ($action !== 'delete') {
                $gidType = ['products'=>'Product','customers'=>'Customer','orders'=>'Order'][$resource] ?? null;
                if ($gidType !== null && ($body['admin_graphql_api_id'] ?? null) !== 'gid://shopify/' . $gidType . '/' . $id) { throw new InvalidArgumentException('Shopify: tipo del recurso firmado incompatible.'); }
                if ($resource === 'carts' && (!is_array($body['line_items'] ?? null) || !array_is_list($body['line_items']))) { throw new InvalidArgumentException('Shopify: carrito inválido.'); }
            }
            $data = $this->pick($body, ['id','admin_graphql_api_id','created_at','updated_at']);
            if ($resource === 'products') { $data += $this->pick($body, ['title','handle','status']); }
            if ($resource === 'orders') { $data += $this->pick($body, ['financial_status','fulfillment_status','currency','total_price']); }
            if ($resource === 'carts') {
                // Cart token is intentionally omitted; only safe item references survive.
                $data['line_items'] = [];
                $data['line_items_count'] = count($body['line_items']);
                $data['line_items_truncated'] = count($body['line_items']) > 100;
                foreach (array_slice($body['line_items'], 0, 100) as $line) {
                    if (!is_array($line)) { throw new InvalidArgumentException('Shopify: línea inválida.'); }
                    $data['line_items'][] = $this->pick($line, ['product_id','variant_id','quantity']);
                }
            }
        }
        return $this->ack([['id'=>hash('sha256', $raw), 'type'=>$d['event'], 'data'=>$data]]);
    }

    private function pick(array $body, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) { if (array_key_exists($key, $body) && (is_scalar($body[$key]) || $body[$key] === null)) { $out[$key] = $body[$key]; } }
        return $out;
    }

    private function body(string $raw): array
    {
        try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new InvalidArgumentException('Commerce: JSON inválido.'); }
        if (!is_array($body) || array_is_list($body)) { throw new InvalidArgumentException('Commerce: objeto JSON requerido.'); }
        return $body;
    }

    private function ack(array $events): array
    {
        return ['status'=>200, 'body'=>'', 'content_type'=>'text/plain', 'events'=>$events];
    }
}
