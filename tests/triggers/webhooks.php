<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use P8P\Core\TriggerProviders\WebhookProviders;

$provider = new WebhookProviders();
$count = 0;
$now = 1800000000;
$secret = 'fixture_secret_123';
$assert = function (bool $value, string $name) use (&$count): void {
    $count++;
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $name);
    }
};
$reject = function (callable $call, string $name) use ($assert): void {
    try {
        $call();
    } catch (InvalidArgumentException | RuntimeException $e) {
        $assert(!str_contains($e->getMessage(), 'fixture_secret'), $name . ' safe error');
        return;
    }
    $assert(false, $name);
};
$fixtures = [];
foreach ($provider->definitions() as $key => $definition) {
    $config = array_fill_keys(array_keys($definition['config_fields']), '123');
    $secrets = array_fill_keys(array_keys($definition['secret_fields']), $secret);
    $headers = [];
    $payload = [];
    $node = $definition['node_type'];
    $event = $definition['event'];
    switch ($node) {
        case 'github':
            $payload = ['repository' => ['id' => 123, 'token' => $secret], 'ref' => 'refs/heads/main', 'before' => 'commit1', 'after' => 'commit2', 'issue' => ['id' => 1], 'pull_request' => ['id' => 2], 'action' => 'opened'];
            $headers['x-github-event'] = $event;
            break;
        case 'woocommerce':
            $payload = ['id' => 123, 'name' => 'fixture', 'order_key' => $secret, 'meta_data' => [['value' => $secret]], 'line_items' => [['id' => 2, 'quantity' => 1, 'download_url' => $secret]]];
            $headers['x-wc-webhook-topic'] = $event;
            break;
        case 'clickup':
            $payload = ['webhook_id' => '123', 'event' => $event, 'task_id' => 'task123', 'history_items' => [['id' => 'history1', 'field' => 'status', 'before' => 'open', 'after' => 'closed']]];
            break;
        case 'linear':
            $payload = ['organizationId' => '123', 'type' => 'Issue', 'action' => explode('.', $event)[1], 'webhookTimestamp' => $now * 1000, 'createdAt' => '2027-01-15T08:00:00Z', 'data' => ['id' => 'issue1', 'title' => 'Fixture', 'updatedAt' => '2027-01-15T08:00:00Z']];
            break;
        case 'intercom':
            $payload = ['app_id' => '123', 'id' => 'notif1', 'topic' => $event, 'data' => ['item' => ['id' => 'contact1', 'name' => 'Fixture']]];
            break;
        case 'zendesk':
            $payload = ['event_id' => 'event1', 'ticket_id' => 123, 'status' => 'open'];
            break;
        case 'typeform':
            $payload = ['event_id' => 'response1', 'event_type' => 'form_response', 'form_response' => ['form_id' => '123', 'answers' => [], 'response_url' => $secret]];
            break;
        case 'slack':
            $payload = ['team_id' => '123', 'event_id' => 'Ev1', 'type' => 'event_callback', 'token' => $secret, 'event' => ['type' => $event, 'channel' => 'C1', 'text' => 'Hello']];
            break;
        case 'telegram_bot':
            $payload = ['update_id' => 123, 'message' => ['message_id' => 1, 'text' => 'Hello', 'chat' => ['id' => 123], 'from' => ['id' => 456]]];
            break;
        case 'whatsapp_business':
            $field = $key === 'whatsapp_message' ? 'messages' : 'statuses';
            $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '123', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '123'], $field => [['id' => 'wamid.1', 'status' => 'delivered', 'type' => 'text', 'text' => ['body' => 'Hello']]]]]]]]];
            break;
        case 'twilio':
            $payload = ['AccountSid' => '123', 'From' => '+15550001', 'To' => '+15550002', 'MessageSid' => 'SM123', 'CallSid' => 'CA123', 'MessageStatus' => 'received', 'CallStatus' => 'ringing', 'Body' => 'Hello + & á'];
            break;
        case 'brevo':
            $config['event'] = 'delivered';
            $payload = ['event' => 'delivered', 'email' => 'demo@example.test', 'message-id' => 'msg1', 'ts_event' => $now, 'api_key' => $secret];
            break;
        case 'trello':
            $payload = ['model' => ['id' => '123'], 'action' => ['id' => 'action1', 'type' => 'createCard', 'date' => '2027-01-15', 'data' => ['card' => ['id' => 'card1', 'name' => 'Fixture']]]];
            break;
    }
    $raw = $node === 'twilio' ? http_build_query($payload, '', '&', PHP_QUERY_RFC1738) : json_encode($payload, JSON_THROW_ON_ERROR);
    $request = ['method' => 'POST', 'headers' => $headers, 'raw_body' => $raw, 'callback_url' => 'https://example.test/trigger_webhook.php?receiver=123', 'query' => []];
    $sign = function (array $request) use ($node, $secret, $now): array {
        $raw = $request['raw_body'];
        $h = &$request['headers'];
        switch ($node) {
            case 'github': $h['x-hub-signature-256'] = 'sha256=' . hash_hmac('sha256', $raw, $secret); break;
            case 'woocommerce': $h['x-wc-webhook-signature'] = base64_encode(hash_hmac('sha256', $raw, $secret, true)); break;
            case 'clickup': $h['x-signature'] = hash_hmac('sha256', $raw, $secret); break;
            case 'linear': $h['linear-signature'] = hash_hmac('sha256', $raw, $secret); break;
            case 'intercom': $h['x-hub-signature'] = 'sha1=' . hash_hmac('sha1', $raw, $secret); break;
            case 'zendesk':
                $h['x-zendesk-webhook-signature-timestamp'] = gmdate('Y-m-d\TH:i:s\Z', $now);
                $h['x-zendesk-webhook-signature'] = base64_encode(hash_hmac('sha256', $h['x-zendesk-webhook-signature-timestamp'] . $raw, $secret, true)); break;
            case 'typeform': $h['typeform-signature'] = 'sha256=' . base64_encode(hash_hmac('sha256', $raw, $secret, true)); break;
            case 'slack':
                $h['x-slack-request-timestamp'] = (string) $now;
                $h['x-slack-signature'] = 'v0=' . hash_hmac('sha256', 'v0:' . $now . ':' . $raw, $secret); break;
            case 'telegram_bot': $h['x-telegram-bot-api-secret-token'] = $secret; break;
            case 'whatsapp_business': $h['x-hub-signature-256'] = 'sha256=' . hash_hmac('sha256', $raw, $secret); break;
            case 'brevo': $h['authorization'] = 'Bearer ' . $secret; break;
            case 'trello': $h['x-trello-webhook'] = base64_encode(hash_hmac('sha1', $raw . $request['callback_url'], $secret, true)); break;
            case 'twilio':
                $h['content-type'] = 'application/x-www-form-urlencoded';
                parse_str($raw, $params);
                ksort($params, SORT_STRING);
                $base = $request['callback_url'];
                foreach ($params as $k => $v) { $base .= $k . $v; }
                $h['x-twilio-signature'] = base64_encode(hash_hmac('sha1', $base, $secret, true)); break;
        }
        return $request;
    };
    $request = $sign($request);
    $result = $provider->verify($key, $config, $secrets, $request, $now);
    $assert(count($result['events']) === 1, $key . ' accepted');
    $assert(!str_contains(json_encode($result), $secret), $key . ' sanitized');
    $assert($provider->verify($key, $config, $secrets, $request, $now)['events'][0]['id'] === $result['events'][0]['id'], $key . ' stable duplicate');
    $bad = $request;
    $bad['headers'] = [];
    $reject(fn () => $provider->verify($key, $config, $secrets, $bad, $now), $key . ' missing signature');
    $reject(fn () => $provider->verify($key, $config, [], $request, $now), $key . ' missing credential');
    $wrongSecrets = array_fill_keys(array_keys($secrets), 'wrong-secret');
    $reject(fn () => $provider->verify($key, $config, $wrongSecrets, $request, $now), $key . ' wrong credential');
    $bad = $request;
    $bad['raw_body'] .= ' ';
    // Static header authentication has no body-integrity protection; TLS is mandatory.
    if (!in_array($node, ['telegram_bot', 'brevo'], true)) {
        $reject(fn () => $provider->verify($key, $config, $secrets, $bad, $now), $key . ' altered bytes');
    }
    if (in_array($node, ['slack', 'linear', 'zendesk'], true)) {
        $reject(fn () => $provider->verify($key, $config, $secrets, $request, $now + 601), $key . ' stale');
    }
    if ($config && !in_array($node, ['brevo', 'whatsapp_business'], true)) {
        $badConfig = array_map(fn () => 'other_scope', $config);
        $reject(fn () => $provider->verify($key, $badConfig, $secrets, $request, $now), $key . ' wrong scope');
    }
    $fixtures[$key] = [$config, $secrets, $request, $sign];
}
[$c, $s, $r, $sign] = $fixtures['slack_message'];
$r['raw_body'] = json_encode(['type' => 'url_verification', 'challenge' => 'challenge123']);
$r = $sign($r);
$assert($provider->verify('slack_message', $c, $s, $r, $now)['body'] === 'challenge123', 'Slack handshake');
$r['raw_body'] .= ' ';
$reject(fn () => $provider->verify('slack_message', $c, $s, $r, $now), 'Slack forged challenge');
[$c, $s, $r] = $fixtures['whatsapp_message'];
$r['method'] = 'GET';
$r['query'] = ['hub_mode' => 'subscribe', 'hub_verify_token' => $secret, 'hub_challenge' => '123456'];
$assert($provider->verify('whatsapp_message', $c, $s, $r, $now)['body'] === '123456', 'Meta handshake PHP normalized query');
$r['query']['hub_verify_token'] = 'wrong';
$reject(fn () => $provider->verify('whatsapp_message', $c, $s, $r, $now), 'Meta wrong token');
[$c, $s, $r] = $fixtures['woocommerce_order_created'];
$r['headers'] = [];
$r['raw_body'] = 'webhook_id=123';
$assert($provider->verify('woocommerce_order_created', $c, $s, $r, $now)['events'] === [], 'Woo unsigned ping cannot enqueue');
[$c, $s, $r] = $fixtures['trello_action'];
$r['method'] = 'HEAD';
$r['headers'] = [];
$assert($provider->verify('trello_action', $c, $s, $r, $now)['events'] === [], 'Trello HEAD no event');
[$c, $s, $r, $sign] = $fixtures['linear_issue_updated'];
$before = $provider->verify('linear_issue_updated', $c, $s, $r, $now)['events'][0]['id'];
$p = json_decode($r['raw_body'], true);
$p['webhookTimestamp'] += 1000;
$r['raw_body'] = json_encode($p);
$r = $sign($r);
$assert($provider->verify('linear_issue_updated', $c, $s, $r, $now)['events'][0]['id'] === $before, 'Linear retry timestamp does not change identity');
[$c, $s, $r, $sign] = $fixtures['twilio_sms_received'];
$r['callback_url'] .= '&altered=1';
$reject(fn () => $provider->verify('twilio_sms_received', $c, $s, $r, $now), 'Twilio exact callback signature');
[$c, $s, $r] = $fixtures['github_push'];
$r['headers']['x-github-delivery'] = 'attacker_changes_header';
$a = $provider->verify('github_push', $c, $s, $r, $now);
$r['headers']['x-github-delivery'] = 'another_delivery_header';
$b = $provider->verify('github_push', $c, $s, $r, $now);
$assert($a['events'][0]['id'] === $b['events'][0]['id'], 'Unsigned GitHub delivery header ignored for identity');
[$c, $s, $r] = $fixtures['whatsapp_message'];
$c['waba_id'] = 'another_account';
$assert($provider->verify('whatsapp_message', $c, $s, $r, $now)['events'] === [], 'Meta other account ignored');
[$c, $s, $r] = $fixtures['github_push'];
$r['raw_body'] = str_repeat('x', 1048577);
$reject(fn () => $provider->verify('github_push', $c, $s, $r, $now), 'Oversize rejected');
$reject(fn () => $provider->verify('unknown', [], [], [], $now), 'Unknown adapter');
try { $provider->poll('github_push', [], [], [], $now); $assert(false, 'Webhook polling'); } catch (LogicException $e) { $assert(true, 'Webhook polling rejected'); }
echo 'PASS webhook adapters: ' . $count . ' assertions, ' . count($fixtures) . " definitions; no network/DB.\n";
