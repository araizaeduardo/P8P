<?php

/** Offline Notion signature/bootstrap/event regression tests. No subscriptions or HTTP calls. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use P8P\Core\TriggerProviders\NotionWebhooks;

$checks = 0;
$now = strtotime('2026-10-25T12:00:00Z');
$token = 'secret_NOTION_FICTITIOUS_VERIFICATION_TOKEN';
$secrets = ['verification_token' => $token];
$config = ['workspace_id' => '11111111-1111-1111-1111-111111111111', 'integration_id' => '22222222-2222-2222-2222-222222222222', 'subscription_id' => '33333333-3333-3333-3333-333333333333'];
$provider = new NotionWebhooks();
$base = [
    'id' => '44444444-4444-4444-4444-444444444444', 'timestamp' => '2026-10-25T11:59:00.123Z',
    'workspace_id' => $config['workspace_id'], 'integration_id' => $config['integration_id'], 'subscription_id' => $config['subscription_id'],
    'type' => 'page.created', 'attempt_number' => 1, 'api_version' => '2025-09-03',
    'entity' => ['id' => '55555555-5555-5555-5555-555555555555', 'type' => 'page'],
    'authors' => [['id' => '66666666-6666-6666-6666-666666666666', 'type' => 'person']],
    'data' => ['parent' => ['id' => '77777777-7777-7777-7777-777777777777', 'type' => 'space']],
];

function check(bool $ok, string $label): void
{
    global $checks;
    $checks++;
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

function request(array $payload, string $token): array
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return ['method' => 'POST', 'raw_body' => $raw, 'headers' => ['x-notion-signature' => 'sha256=' . hash_hmac('sha256', $raw, $token)]];
}

function rejects(callable $call, string $label): void
{
    global $token;
    try {
        $call();
    } catch (Throwable $e) {
        check(!str_contains($e->getMessage(), $token) && $e->getPrevious() === null, $label . ' safe exception');
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $label);
}

check(count($provider->definitions()) === 3, 'Three exact official event definitions');
foreach ($provider->definitions() as $key => $definition) {
    check($definition['node_type'] === 'notion' && $definition['transport'] === 'webhook', 'Notion webhook definition ' . $key);
    check(array_keys($definition['secret_fields']) === ['verification_token'], 'Signing token distinct from API credentials');
    rejects(fn () => $provider->poll($key, [], [], [], $now), 'Notion webhook has no polling');
    $payload = $base;
    $payload['type'] = $definition['event'];
    $payload['data']['updated_properties'] = ['XGe%40', 'title'];
    $payload['data']['updated_blocks'] = [['id' => '88888888-8888-8888-8888-888888888888', 'type' => 'block', 'download_url' => 'https://private.invalid/']];
    $payload['data']['verification_token'] = $token;
    $payload['data']['arbitrary_content'] = 'Full page content should not be copied';
    $payload['accessible_by'] = [['id' => 'private-user']];
    $result = $provider->verify($key, $config, $secrets, request($payload, $token), $now);
    check($result['status'] === 200 && count($result['events']) === 1, 'Valid signed event ' . $key);
    check($result['events'][0]['id'] === $base['id'] && $result['events'][0]['type'] === $definition['event'], 'Stable native event ID/type ' . $key);
    check(!str_contains(json_encode($result), $token) && !str_contains(json_encode($result), 'arbitrary_content') && !str_contains(json_encode($result), 'download_url') && !str_contains(json_encode($result), 'accessible_by'), 'Filtered notification fields ' . $key);
    $again = $payload;
    $again['attempt_number'] = 8;
    $result2 = $provider->verify($key, $config, $secrets, request($again, $token), $now + 100);
    check($result2['events'][0]['id'] === $result['events'][0]['id'], 'Retry attempt does not change dedupe identity');
    if ($definition['event'] === 'page.properties_updated') {
        check($result['events'][0]['data']['data']['updated_properties'] === ['XGe%40', 'title'], 'Properties payload kept as IDs');
    } elseif ($definition['event'] === 'page.content_updated') {
        check(count($result['events'][0]['data']['data']['updated_blocks']) === 1, 'Content payload carries changed block IDs');
    }
}

$key = 'notion_webhook_page_created';
$validRequest = request($base, $token);
$candidate = ['method' => 'POST', 'raw_body' => json_encode(['verification_token' => $token]), 'headers' => [], 'setup_allowed' => true];
$setup = $provider->verify($key, $config, [], $candidate, $now);
check($setup['status'] === 200 && $setup['events'] === [] && $setup['challenge'] === true, 'Unsigned handshake never creates event');
check($setup['body'] === '' && $setup['setup_secret']['verification_token'] === $token, 'Setup token returned only in private manager channel');
$withoutSetup = $candidate;
unset($withoutSetup['setup_allowed']);
rejects(fn () => $provider->verify($key, $config, [], $withoutSetup, $now), 'Unsigned handshake forbidden outside trusted setup path');
rejects(fn () => $provider->verify($key, $config, $secrets, $candidate, $now), 'Existing signing credential cannot be overwritten by unsigned handshake');
$notBootstrap = $candidate;
$notBootstrap['raw_body'] = json_encode(['verification_token' => $token, 'type' => 'page.created']);
rejects(fn () => $provider->verify($key, $config, [], $notBootstrap, $now), 'Setup accepts exactly token object');
$notBootstrap['raw_body'] = $validRequest['raw_body'];
rejects(fn () => $provider->verify($key, $config, [], $notBootstrap, $now), 'An event cannot masquerade as bootstrap');
$notBootstrap = $candidate;
$notBootstrap['raw_body'] = json_encode(['verification_token' => ['invalid']]);
rejects(fn () => $provider->verify($key, $config, [], $notBootstrap, $now), 'Bootstrap token type checked');

$tampered = $validRequest;
$tampered['raw_body'] .= ' ';
rejects(fn () => $provider->verify($key, $config, $secrets, $tampered, $now), 'Altered raw body invalidates signature');
$tampered = $validRequest;
$tampered['headers']['x-notion-signature'] = 'sha256=' . str_repeat('0', 64);
rejects(fn () => $provider->verify($key, $config, $secrets, $tampered, $now), 'Wrong signature');
$tampered['headers'] = [];
rejects(fn () => $provider->verify($key, $config, $secrets, $tampered, $now), 'Absent signature');
$tampered['headers'] = ['x-notion-signature' => $validRequest['headers']['x-notion-signature'], 'X-Notion-Signature' => $validRequest['headers']['x-notion-signature']];
rejects(fn () => $provider->verify($key, $config, $secrets, $tampered, $now), 'Duplicate header rejected');
$tampered['headers'] = ['X-NoTiOn-SiGnAtUrE' => $validRequest['headers']['x-notion-signature']];
check(count($provider->verify($key, $config, $secrets, $tampered, $now)['events']) === 1, 'Case insensitive header name');
rejects(fn () => $provider->verify($key, $config, ['verification_token' => 'secret_OTHER_TEST_VERIFICATION_TOKEN'], $validRequest, $now), 'Different receiver secret');
foreach (['workspace_id', 'integration_id', 'subscription_id'] as $field) {
    $other = $config;
    $other[$field] = '99999999-9999-9999-9999-999999999999';
    rejects(fn () => $provider->verify($key, $other, $secrets, $validRequest, $now), 'Receiver scope ' . $field);
}
$otherType = $base;
$otherType['type'] = 'page.content_updated';
check($provider->verify($key, $config, $secrets, request($otherType, $token), $now)['events'] === [], 'Authenticated other event type acknowledged without workflow event');
$late = $base;
$late['timestamp'] = gmdate('Y-m-d\TH:i:s\Z', $now - 86400);
check(count($provider->verify($key, $config, $secrets, request($late, $token), $now)['events']) === 1, 'Last provider retry after 24 hours supported');
$late['timestamp'] = gmdate('Y-m-d\TH:i:s\Z', $now - 90001);
rejects(fn () => $provider->verify($key, $config, $secrets, request($late, $token), $now), 'Replay outside 25-hour window');
$late['timestamp'] = gmdate('Y-m-d\TH:i:s\Z', $now + 301);
rejects(fn () => $provider->verify($key, $config, $secrets, request($late, $token), $now), 'Future timestamp');
$old = $base;
$old['timestamp'] = '2026-10-25T11:58:00Z';
check(count($provider->verify($key, $config, $secrets, request($old, $token), $now)['events']) === 1, 'Out-of-order recent event accepted');

foreach ([['id' => 'invalid'], ['entity' => ['id' => $base['entity']['id'], 'type' => 'database']], ['attempt_number' => 9], ['attempt_number' => '1'], ['authors' => 'not array'], ['data' => 'not object'], ['timestamp' => 'not date'], ['workspace_id' => 'https://evil.invalid/']] as $mutation) {
    $bad = array_replace($base, $mutation);
    rejects(fn () => $provider->verify($key, $config, $secrets, request($bad, $token), $now), 'Signed malformed structure');
}
$bad = ['method' => 'POST', 'raw_body' => '{invalid json', 'headers' => ['x-notion-signature' => 'sha256=' . hash_hmac('sha256', '{invalid json', $token)]];
rejects(fn () => $provider->verify($key, $config, $secrets, $bad, $now), 'Signed invalid JSON');
$bad['raw_body'] = str_repeat('x', 1048577);
rejects(fn () => $provider->verify($key, $config, $secrets, $bad, $now), 'Payload size bounded');
$bad = $validRequest;
$bad['method'] = 'GET';
rejects(fn () => $provider->verify($key, $config, $secrets, $bad, $now), 'Only POST supported');
rejects(fn () => $provider->verify('unknown', $config, $secrets, $validRequest, $now), 'Unknown event key');

echo "Notion webhooks: {$checks} assertions passed (offline HMAC/bootstrap/clock fixtures).\n";
