<?php

namespace P8P\Core\TriggerProviders;

use InvalidArgumentException;
use LogicException;
use P8P\Interfaces\TriggerProviderInterface;
use RuntimeException;

/** Pure protocol adapters: no network, storage, environment or credential loading. */
class WebhookProviders implements TriggerProviderInterface
{
    public function definitions(): array
    {
        $definitions = [];
        $add = function (string $key, string $node, string $event, string $secret, array $fields, string $docs) use (&$definitions): void {
            $config = [];
            foreach ($fields as $field) {
                $config[$field] = ['label' => $field, 'type' => 'string', 'required' => true];
            }
            $secretFields = [];
            foreach (explode(',', $secret) as $field) {
                $secretFields[$field] = ['label' => $field, 'type' => 'string', 'required' => true];
            }
            $definitions[$key] = ['name' => ucfirst($node) . ': ' . $event, 'node_type' => $node,
                'event' => $event, 'transport' => 'webhook', 'config_fields' => $config,
                'secret_fields' => $secretFields, 'docs' => [$docs],
                'help' => 'Registro externo manual con URL HTTPS exacta. Guardar secretos en credencial generic_bearer (API Key JSON). No se crea ninguna suscripción remota automáticamente.'];
        };
        foreach (['push', 'issues', 'pull_request'] as $event) {
            $add('github_' . $event, 'github', $event, 'webhook_secret', ['repository_id'], 'https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries');
        }
        foreach (['order', 'product', 'customer'] as $resource) {
            foreach (['created', 'updated', 'deleted'] as $action) {
                $add("woocommerce_{$resource}_{$action}", 'woocommerce', "$resource.$action", 'webhook_secret', [], 'https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks/');
            }
        }
        foreach (['created' => 'taskCreated', 'updated' => 'taskUpdated', 'status_updated' => 'taskStatusUpdated'] as $suffix => $event) {
            $add('clickup_task_' . $suffix, 'clickup', $event, 'webhook_secret', ['webhook_id'], 'https://developer.clickup.com/docs/webhooks');
        }
        foreach (['created' => 'create', 'updated' => 'update'] as $suffix => $action) {
            $add('linear_issue_' . $suffix, 'linear', 'Issue.' . $action, 'webhook_secret', ['organization_id'], 'https://linear.app/developers/webhooks');
        }
        $add('intercom_contact_created', 'intercom', 'contact.user.created', 'client_secret', ['app_id'], 'https://developers.intercom.com/docs/references/webhooks/webhook-models');
        $add('intercom_lead_created', 'intercom', 'contact.lead.created', 'client_secret', ['app_id'], 'https://developers.intercom.com/docs/references/webhooks/webhook-models');
        $add('intercom_conversation_created', 'intercom', 'conversation.user.created', 'client_secret', ['app_id'], 'https://developers.intercom.com/docs/references/webhooks/webhook-models');
        $add('zendesk_ticket_event', 'zendesk', 'ticket.event', 'webhook_secret', [], 'https://developer.zendesk.com/documentation/webhooks/verifying/');
        $add('typeform_response', 'typeform', 'form_response', 'webhook_secret', ['form_id'], 'https://www.typeform.com/developers/webhooks/secure-your-webhooks/');
        foreach (['message', 'app_mention'] as $event) {
            $add('slack_' . $event, 'slack', $event, 'signing_secret', ['team_id'], 'https://docs.slack.dev/authentication/verifying-requests-from-slack/');
        }
        $add('telegram_message', 'telegram_bot', 'message', 'secret_token', [], 'https://core.telegram.org/bots/api#setwebhook');
        foreach (['message', 'status'] as $event) {
            $add('whatsapp_' . $event, 'whatsapp_business', $event, 'app_secret,verify_token', ['waba_id', 'phone_number_id'], 'https://developers.facebook.com/docs/graph-api/webhooks/getting-started/');
        }
        foreach (['sms' => 'sms.received', 'call' => 'call.received'] as $kind => $event) {
            $add('twilio_' . $kind . '_received', 'twilio', $event, 'auth_token', ['account_sid'], 'https://www.twilio.com/docs/usage/webhooks/webhooks-security');
        }
        foreach (['transactional', 'marketing'] as $kind) {
            $add('brevo_' . $kind, 'brevo', $kind, 'webhook_token', ['event'], 'https://developers.brevo.com/docs/secured-webhooks');
        }
        $add('trello_action', 'trello', 'action', 'app_secret', ['model_id'], 'https://developer.atlassian.com/cloud/trello/guides/rest-api/webhooks/');
        return $definitions;
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        throw new LogicException('Este proveedor requiere webhook.');
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        $definition = $this->definitions()[$key] ?? null;
        if (!$definition) {
            throw new InvalidArgumentException('Trigger webhook desconocido.');
        }
        foreach ($definition['config_fields'] as $field => $schema) {
            $this->required($config, $field);
        }
        foreach ($definition['secret_fields'] as $field => $schema) {
            $this->required($secrets, $field);
        }
        $raw = $request['raw_body'] ?? '';
        $headers = $request['headers'] ?? [];
        if (!is_string($raw) || strlen($raw) > 1048576 || !is_array($headers)) {
            throw new InvalidArgumentException('Petición webhook inválida o demasiado grande.');
        }
        $method = $request['method'] ?? '';
        $provider = $definition['node_type'];
        $result = ['status' => 200, 'body' => '', 'content_type' => 'text/plain', 'events' => []];
        if ($provider === 'whatsapp_business' && $method === 'GET') {
            $q = $request['query'] ?? [];
            $mode = $q['hub.mode'] ?? $q['hub_mode'] ?? '';
            $token = $q['hub.verify_token'] ?? $q['hub_verify_token'] ?? '';
            $challenge = $q['hub.challenge'] ?? $q['hub_challenge'] ?? null;
            $this->authenticate($mode === 'subscribe' && is_string($token) && hash_equals($secrets['verify_token'], $token));
            if (!is_string($challenge) || !preg_match('/^[0-9]{1,100}$/D', $challenge)) {
                throw new InvalidArgumentException('Desafío inválido.');
            }
            return array_replace($result, ['body' => $challenge, 'challenge' => true]);
        }
        if ($provider === 'trello' && $method === 'HEAD') {
            return array_replace($result, ['challenge' => true]);
        }
        if ($method !== 'POST') {
            throw new InvalidArgumentException('Método webhook no admitido.');
        }
        // WooCommerce activation ping has no signature or data. ACK only, never enqueue.
        if ($provider === 'woocommerce' && preg_match('/^webhook_id=[0-9]+$/D', $raw)) {
            return array_replace($result, ['challenge' => true]);
        }
        if ($provider === 'twilio') {
            return $this->twilio($key, $config, $secrets, $request, $result);
        }
        $secret = $secrets['webhook_secret'] ?? '';
        switch ($provider) {
            case 'github':
                $this->hmac($headers, 'x-hub-signature-256', 'sha256', $raw, $secret, 'sha256=');
                break;
            case 'woocommerce':
                $this->hmac($headers, 'x-wc-webhook-signature', 'sha256', $raw, $secret, '', true);
                break;
            case 'clickup':
                $this->hmac($headers, 'x-signature', 'sha256', $raw, $secret);
                break;
            case 'linear':
                $this->hmac($headers, 'linear-signature', 'sha256', $raw, $secret);
                break;
            case 'intercom':
                $this->hmac($headers, 'x-hub-signature', 'sha1', $raw, $secrets['client_secret'], 'sha1=');
                break;
            case 'zendesk':
                $timestamp = $this->header($headers, 'x-zendesk-webhook-signature-timestamp');
                $time = strtotime($timestamp);
                $this->authenticate($time !== false && abs($now - $time) <= 300);
                $this->hmac($headers, 'x-zendesk-webhook-signature', 'sha256', $timestamp . $raw, $secret, '', true);
                break;
            case 'typeform':
                $this->hmac($headers, 'typeform-signature', 'sha256', $raw, $secret, 'sha256=', true);
                break;
            case 'slack':
                $timestamp = $this->header($headers, 'x-slack-request-timestamp');
                $this->authenticate(ctype_digit($timestamp) && abs($now - (int) $timestamp) <= 300);
                $this->hmac($headers, 'x-slack-signature', 'sha256', 'v0:' . $timestamp . ':' . $raw, $secrets['signing_secret'], 'v0=');
                break;
            case 'telegram_bot':
                $this->authenticate((bool) preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $secrets['secret_token']));
                $this->authenticate(hash_equals($secrets['secret_token'], $this->header($headers, 'x-telegram-bot-api-secret-token')));
                break;
            case 'whatsapp_business':
                $this->hmac($headers, 'x-hub-signature-256', 'sha256', $raw, $secrets['app_secret'], 'sha256=');
                break;
            case 'brevo':
                $this->authenticate(hash_equals('Bearer ' . $secrets['webhook_token'], $this->header($headers, 'authorization')));
                break;
            case 'trello':
                $this->hmac($headers, 'x-trello-webhook', 'sha1', $raw . $this->callback($request), $secrets['app_secret'], '', true);
                break;
            default:
                throw new LogicException('Protocolo no implementado.');
        }
        $payload = $this->json($raw);
        $event = $definition['event'];
        $hash = hash('sha256', $raw);
        $id = $hash;
        $data = [];
        switch ($provider) {
            case 'github':
                $this->scope($config['repository_id'], $payload['repository']['id'] ?? null);
                if (($headers['x-github-event'] ?? '') === 'ping') {
                    return array_replace($result, ['challenge' => true]);
                }
                if (($headers['x-github-event'] ?? '') !== $event) {
                    return $result;
                }
                if ($event === 'push') {
                    $this->required($payload, 'ref');
                    $this->required($payload, 'before');
                    $this->required($payload, 'after');
                } else {
                    $field = $event === 'issues' ? 'issue' : 'pull_request';
                    if (!is_array($payload[$field] ?? null)) {
                        throw new InvalidArgumentException('Evento GitHub incompatible con su estructura.');
                    }
                    $this->identifier($payload[$field]['id'] ?? null);
                }
                $data = $this->pick($payload, ['action', 'ref', 'before', 'after', 'created', 'deleted', 'forced', 'number']);
                $data['repository'] = $this->pick($payload['repository'] ?? [], ['id', 'name', 'full_name']);
                foreach (['issue', 'pull_request'] as $field) {
                    if (isset($payload[$field])) {
                        $data[$field] = $this->pick($payload[$field], ['id', 'number', 'title', 'body', 'state', 'html_url', 'created_at', 'updated_at']);
                    }
                }
                break;
            case 'woocommerce':
                if (($headers['x-wc-webhook-topic'] ?? '') !== $event) {
                    return $result;
                }
                $this->identifier($payload['id'] ?? null);
                $data = $this->pick($payload, ['id', 'status', 'name', 'sku', 'type', 'price', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'total', 'currency', 'customer_id', 'email', 'first_name', 'last_name', 'date_created_gmt', 'date_modified_gmt']);
                if (isset($payload['line_items']) && is_array($payload['line_items'])) {
                    $data['line_items'] = array_map(fn ($item) => $this->pick($item, ['id', 'product_id', 'variation_id', 'name', 'quantity', 'total', 'sku']), array_slice($payload['line_items'], 0, 1000));
                }
                // order_key, payment URLs, meta_data and downloads are deliberately excluded.
                break;
            case 'clickup':
                $this->scope($config['webhook_id'], $payload['webhook_id'] ?? null);
                if (($payload['event'] ?? '') !== $event) {
                    return $result;
                }
                $this->identifier($payload['task_id'] ?? null);
                $base = $this->pick($payload, ['event', 'task_id', 'webhook_id']);
                $history = $payload['history_items'] ?? [];
                if (!is_array($history) || count($history) > 1000) {
                    throw new InvalidArgumentException('Historial webhook inválido.');
                }
                if ($history) {
                    foreach ($history as $item) {
                        $historyId = $this->identifier($item['id'] ?? null);
                        $result['events'][] = $this->event($config['webhook_id'] . ':' . $historyId, $event, $base + ['history_item' => $this->pick($item, ['id', 'type', 'date', 'field', 'before', 'after'])]);
                    }
                    return $result;
                }
                $data = $base;
                break;
            case 'linear':
                $this->authenticate(isset($payload['webhookTimestamp']) && is_numeric($payload['webhookTimestamp']) && abs($now * 1000 - (float) $payload['webhookTimestamp']) <= 60000);
                $this->scope($config['organization_id'], $payload['organizationId'] ?? null);
                if (($payload['type'] ?? '') . '.' . ($payload['action'] ?? '') !== $event) {
                    return $result;
                }
                $item = $payload['data'] ?? [];
                $this->identifier($item['id'] ?? null);
                $data = ['action' => $payload['action'], 'issue' => $this->pick($item, ['id', 'identifier', 'title', 'description', 'priority', 'stateId', 'teamId', 'assigneeId', 'createdAt', 'updatedAt'])];
                // Exclude delivery timestamp: re-signed retries must keep their identity.
                $id = hash('sha256', json_encode([$event, $item['id'], $payload['createdAt'] ?? null, $item['updatedAt'] ?? null, $data], JSON_THROW_ON_ERROR));
                break;
            case 'intercom':
                $this->scope($config['app_id'], $payload['app_id'] ?? null);
                if (($payload['topic'] ?? '') !== $event) {
                    return $result;
                }
                $id = $this->identifier($payload['id'] ?? null);
                $item = $payload['data']['item'] ?? [];
                $data = ['topic' => $event, 'item' => $this->pick($item, ['id', 'type', 'role', 'name', 'email', 'created_at', 'updated_at', 'title', 'state'])];
                break;
            case 'zendesk':
                // User-defined ticket notification body; event_id MUST be stable across retries.
                $id = $this->identifier($payload['event_id'] ?? null);
                $this->identifier($payload['ticket_id'] ?? null);
                $data = $this->pick($payload, ['event_id', 'ticket_id', 'subject', 'status', 'priority', 'updated_at', 'comment']);
                break;
            case 'typeform':
                $this->scope($config['form_id'], $payload['form_response']['form_id'] ?? null);
                if (($payload['event_type'] ?? '') !== 'form_response') {
                    return $result;
                }
                $id = $this->identifier($payload['event_id'] ?? null);
                $data = ['form_response' => $this->pick($payload['form_response'], ['form_id', 'token', 'landed_at', 'submitted_at', 'answers'])];
                break;
            case 'slack':
                if (($payload['type'] ?? '') === 'url_verification') {
                    $challenge = $this->required($payload, 'challenge');
                    return array_replace($result, ['body' => $challenge, 'challenge' => true]);
                }
                $this->scope($config['team_id'], $payload['team_id'] ?? null);
                if (($payload['type'] ?? '') !== 'event_callback' || ($payload['event']['type'] ?? '') !== $event) {
                    return $result;
                }
                $id = $this->identifier($payload['event_id'] ?? null);
                $data = ['team_id' => $payload['team_id'], 'event' => $this->pick($payload['event'], ['type', 'subtype', 'user', 'channel', 'text', 'ts', 'thread_ts', 'event_ts'])];
                break;
            case 'telegram_bot':
                $id = $this->identifier($payload['update_id'] ?? null);
                if (!isset($payload['message'])) {
                    return $result;
                }
                $message = $payload['message'];
                $this->identifier($message['message_id'] ?? null);
                $data = ['update_id' => $payload['update_id'], 'message' => $this->pick($message, ['message_id', 'date', 'text', 'caption'])];
                $data['message']['chat'] = $this->pick($message['chat'] ?? [], ['id', 'type', 'title', 'username']);
                $data['message']['from'] = $this->pick($message['from'] ?? [], ['id', 'is_bot', 'first_name', 'last_name', 'username']);
                break;
            case 'whatsapp_business':
                return $this->whatsapp($key, $config, $payload, $result);
            case 'brevo':
                $allowed = $key === 'brevo_transactional'
                    ? ['request', 'delivered', 'hard_bounce', 'soft_bounce', 'blocked', 'spam', 'invalid_email', 'deferred', 'click', 'opened', 'unique_opened', 'unsubscribed', 'error', 'proxy_open', 'unique_proxy_open']
                    : ['delivered', 'opened', 'click', 'hard_bounce', 'soft_bounce', 'spam', 'unsubscribe', 'list_addition', 'contact_updated', 'contact_deleted', 'proxy_open'];
                if (!in_array($config['event'], $allowed, true)) {
                    throw new InvalidArgumentException('Evento Brevo no admitido.');
                }
                $batch = array_is_list($payload) ? $payload : [$payload];
                if (count($batch) > 1000) {
                    throw new InvalidArgumentException('Lote webhook demasiado grande.');
                }
                foreach ($batch as $item) {
                    if (!is_array($item) || ($item['event'] ?? '') !== $config['event']) {
                        continue;
                    }
                    $data = $this->pick($item, ['event', 'email', 'id', 'message-id', 'ts', 'ts_event', 'date', 'camp_id', 'campaign_id', 'list_id', 'link', 'reason']);
                    $result['events'][] = $this->event(hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)), $config['event'], $data);
                }
                return $result;
            case 'trello':
                $this->scope($config['model_id'], $payload['model']['id'] ?? null);
                $id = $this->identifier($payload['action']['id'] ?? null);
                $action = $payload['action'];
                $data = ['model_id' => $payload['model']['id'], 'action' => $this->pick($action, ['id', 'type', 'date'])];
                foreach (['card', 'list', 'board'] as $field) {
                    if (isset($action['data'][$field])) {
                        $data[$field] = $this->pick($action['data'][$field], ['id', 'name', 'idList', 'closed']);
                    }
                }
                break;
        }
        $result['events'][] = $this->event($id, $event, $data);
        return $result;
    }

    private function twilio(string $key, array $config, array $secrets, array $request, array $result): array
    {
        $headers = $request['headers'];
        if (!str_starts_with(strtolower($this->header($headers, 'content-type')), 'application/x-www-form-urlencoded')) {
            throw new InvalidArgumentException('Twilio requiere formulario URL encoded.');
        }
        // Parse without PHP key rewriting or silent duplicate/array truncation.
        $fields = [];
        foreach (explode('&', $request['raw_body']) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name) || array_key_exists($name, $fields)) {
                throw new InvalidArgumentException('Formulario Twilio ambiguo.');
            }
            $fields[$name] = urldecode($value);
        }
        if (count($fields) > 500) {
            throw new InvalidArgumentException('Formulario demasiado grande.');
        }
        ksort($fields, SORT_STRING);
        $signatureBase = $this->callback($request);
        foreach ($fields as $name => $value) {
            $signatureBase .= $name . $value;
        }
        $this->hmac($headers, 'x-twilio-signature', 'sha1', $signatureBase, $secrets['auth_token'], '', true);
        $this->scope($config['account_sid'], $fields['AccountSid'] ?? null);
        $sms = $key === 'twilio_sms_received';
        $id = $this->identifier($fields[$sms ? 'MessageSid' : 'CallSid'] ?? null);
        if ($sms && !in_array($fields['MessageStatus'] ?? $fields['SmsStatus'] ?? '', ['received', 'receiving'], true)) {
            return array_replace($result, ['content_type' => 'text/xml', 'body' => '<?xml version="1.0" encoding="UTF-8"?><Response/>']);
        }
        if (!$sms && !in_array($fields['CallStatus'] ?? '', ['ringing', 'queued', 'in-progress'], true)) {
            return array_replace($result, ['content_type' => 'text/xml', 'body' => '<?xml version="1.0" encoding="UTF-8"?><Response/>']);
        }
        $data = $this->pick($fields, ['AccountSid', 'MessageSid', 'SmsSid', 'CallSid', 'From', 'To', 'Body', 'NumMedia', 'MessageStatus', 'SmsStatus', 'CallStatus', 'Direction']);
        $result['events'][] = $this->event($id, $sms ? 'sms.received' : 'call.received', $data);
        // Empty response ACK only: no SMS, dialing or call handling side effects.
        return array_replace($result, ['content_type' => 'text/xml', 'body' => '<?xml version="1.0" encoding="UTF-8"?><Response/>']);
    }

    private function whatsapp(string $key, array $config, array $payload, array $result): array
    {
        if (($payload['object'] ?? '') !== 'whatsapp_business_account' || !is_array($payload['entry'] ?? null)) {
            throw new InvalidArgumentException('Payload WhatsApp inválido.');
        }
        foreach ($payload['entry'] as $entry) {
            if ((string) ($entry['id'] ?? '') !== $config['waba_id']) {
                continue;
            }
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (($change['field'] ?? '') !== 'messages' || (string) ($value['metadata']['phone_number_id'] ?? '') !== $config['phone_number_id']) {
                    continue;
                }
                $field = $key === 'whatsapp_message' ? 'messages' : 'statuses';
                foreach ($value[$field] ?? [] as $item) {
                    $id = $this->identifier($item['id'] ?? null);
                    $data = $this->pick($item, ['id', 'from', 'timestamp', 'type', 'status', 'recipient_id']);
                    if ($field === 'messages') {
                        if (isset($item['text'])) {
                            $data['text'] = $this->pick($item['text'], ['body']);
                        }
                        foreach (['image', 'audio', 'video', 'document', 'sticker'] as $media) {
                            if (isset($item[$media])) {
                                $data[$media] = $this->pick($item[$media], ['id', 'mime_type', 'sha256', 'caption', 'filename']);
                            }
                        }
                    } else {
                        $id .= ':' . $this->identifier($item['status'] ?? null);
                    }
                    $data['phone_number_id'] = $config['phone_number_id'];
                    $result['events'][] = $this->event($id, $field === 'messages' ? 'message' : 'status', $data);
                    if (count($result['events']) > 1000) {
                        throw new InvalidArgumentException('Lote demasiado grande.');
                    }
                }
            }
        }
        return $result;
    }

    private function event(string $id, string $type, array $data): array
    {
        return ['id' => $id, 'type' => $type, 'data' => $this->filterSensitiveKeys($data)];
    }

    private function filterSensitiveKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/^(?:order_key|authorization|api_key|access_token|refresh_token|client_secret|app_secret|webhook_secret|signing_secret|consumer_secret|password|secret_token|auth_token)$/iD', $key)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->filterSensitiveKeys($value);
            }
        }
        return $data;
    }

    private function callback(array $request): string
    {
        $url = $request['callback_url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Callback HTTPS exacto requerido.');
        }
        return $url;
    }

    private function required(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 4096) {
            throw new InvalidArgumentException('Configuración de trigger incompleta o inválida.');
        }
        return $value;
    }

    private function identifier(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || (string) $value === '' || strlen((string) $value) > 255) {
            throw new InvalidArgumentException('Identificador de evento inválido.');
        }
        return (string) $value;
    }

    private function scope(string $expected, mixed $actual): void
    {
        $this->authenticate((is_string($actual) || is_int($actual)) && hash_equals($expected, (string) $actual));
    }

    private function authenticate(bool $valid): void
    {
        if (!$valid) {
            throw new RuntimeException('Autenticidad de webhook no verificada.');
        }
    }

    private function header(array $headers, string $name): string
    {
        $value = $headers[$name] ?? '';
        $this->authenticate(is_string($value) && $value !== '' && strlen($value) <= 4096);
        return $value;
    }

    private function hmac(array $headers, string $header, string $algorithm, string $message, string $secret, string $prefix = '', bool $base64 = false): void
    {
        $digest = hash_hmac($algorithm, $message, $secret, $base64);
        $expected = $prefix . ($base64 ? base64_encode($digest) : $digest);
        $this->authenticate(hash_equals($expected, $this->header($headers, $header)));
    }

    private function json(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('JSON webhook inválido.');
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Se requiere objeto o lote JSON.');
        }
        return $decoded;
    }

    private function pick(mixed $source, array $fields): array
    {
        if (!is_array($source)) {
            throw new InvalidArgumentException('Estructura webhook inválida.');
        }
        return array_intersect_key($source, array_flip($fields));
    }
}
