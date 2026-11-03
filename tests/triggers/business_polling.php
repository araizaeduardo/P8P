<?php
/** php tests/triggers/business_polling.php — HTTP, reloj y estado simulados; sin BD ni red. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use P8P\Core\TriggerProviders\BusinessPollingProviders;
use P8P\Core\TriggerRetryException;

$checks = 0;
$history = [];
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function fails(callable $call, string $class = Throwable::class): Throwable
{
    try { $call(); } catch (Throwable $e) {
        check($e instanceof $class, 'Unexpected exception ' . get_class($e) . ': ' . $e->getMessage());
        return $e;
    }
    throw new RuntimeException('Expected rejection');
}
function provider(array $responses, array &$history): BusinessPollingProviders
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new BusinessPollingProviders(new Client(['handler' => $stack]));
}
function response(array $body, array $headers = []): Response
{
    return new Response(200, $headers, json_encode($body));
}
$at = '2026-10-25T10:02:00Z';
$activated = '2026-10-25T10:00:00Z';
$now = strtotime('2026-10-25T10:05:00Z');
$configs = [
    'airtable' => ['base_id' => 'appDemo', 'table' => 'tblDemo', 'modified_field' => 'Modified'],
    'notion' => ['data_source_id' => 'data-source-id'], 'asana' => ['project_id' => '123'],
    'trello' => ['board_id' => 'board-id'], 'jira' => ['project_key' => 'DEMO', 'user_timezone' => 'UTC'],
    'hubspot' => [], 'salesforce' => ['object' => 'Lead'], 'wordpress' => [], 'discord' => ['channel_id' => '123456789012345678'],
];
$secrets = [
    'airtable' => ['access_token' => 'secret-token-only-123'], 'notion' => ['access_token' => 'secret-token-only-123'],
    'asana' => ['access_token' => 'secret-token-only-123'], 'hubspot' => ['access_token' => 'secret-token-only-123'],
    'trello' => ['api_key' => 'secret-api-only-456', 'token' => 'secret-token-only-123'],
    'jira' => ['site_url' => 'https://example.atlassian.net', 'email' => 'bot@example.com', 'api_token' => 'secret-token-only-123'],
    'salesforce' => ['instance_url' => 'https://example.my.salesforce.com', 'api_version' => 'v66.0', 'access_token' => 'secret-token-only-123'],
    'wordpress' => ['site_url' => 'https://example.com/blog', 'username' => 'bot', 'application_password' => 'secret-token-only-123'],
    'discord' => ['bot_token' => 'secret-token-only-123'],
];
$fixtures = [
    'airtable' => ['records' => [['id' => 'recDemo', 'createdTime' => $at, 'fields' => ['Modified' => $at, 'password' => 'DO-NOT-COPY']]]],
    'notion' => ['results' => [['id' => 'pageDemo', 'created_time' => $at, 'last_edited_time' => $at, 'properties' => ['secret' => 'DO-NOT-COPY']]], 'has_more' => false],
    'asana' => ['data' => [['gid' => '321', 'created_at' => $at, 'modified_at' => $at, 'completed' => false, 'notes' => 'DO-NOT-COPY']], 'next_page' => null],
    'trello' => [['id' => 'actionDemo', 'date' => $at, 'data' => ['card' => ['id' => 'cardDemo'], 'listBefore' => ['id' => 'one'], 'listAfter' => ['id' => 'two'], 'secret' => 'DO-NOT-COPY']]],
    'jira' => ['issues' => [['id' => '11', 'key' => 'DEMO-11', 'fields' => ['created' => $at, 'updated' => $at, 'status' => ['id' => '2'], 'description' => 'DO-NOT-COPY']]], 'isLast' => true],
    'hubspot' => ['total' => 1, 'results' => [['id' => '42', 'createdAt' => $at, 'updatedAt' => $at, 'properties' => ['createdate' => $at, 'lastmodifieddate' => $at, 'hs_lastmodifieddate' => $at, 'email' => 'DO-NOT-COPY']]]],
    'salesforce' => ['done' => true, 'records' => [['Id' => '00Q000000000001AAA', 'CreatedDate' => $at, 'SystemModstamp' => $at, 'Name' => 'DO-NOT-COPY']]],
    'wordpress' => [['id' => 42, 'status' => 'publish', 'date_gmt' => '2026-10-25T10:02:00', 'modified_gmt' => '2026-10-25T10:02:00', 'content' => 'DO-NOT-COPY']],
    'discord' => [['id' => '123456789012345679', 'channel_id' => '123456789012345678', 'timestamp' => $at, 'author' => ['id' => 'author42'], 'content' => 'DO-NOT-COPY']],
];
$catalog = new BusinessPollingProviders();
$definitions = $catalog->definitions();
check(count($definitions) === 31, 'Expected 19 polling + 12 webhook definitions');
foreach ($definitions as $key => $definition) {
    check($definition['docs'] !== [], 'Official documentation required');
    if ($definition['transport'] !== 'polling') { continue; }
    $name = $definition['node_type'];
    $p = provider([], $history);
    $baseline = $p->poll($key, $configs[$name], $secrets[$name], ['activated_at' => $activated], $now);
    check($baseline['events'] === [] && $history === [], 'Baseline must never fetch historical records');
    check($baseline['state']['watermark'] === $activated, 'Activation survives first tick and restart');
    $p = provider([response($fixtures[$name], ['X-WP-TotalPages' => '1'])], $history);
    $result = $p->poll($key, $configs[$name], $secrets[$name], $baseline['state'], $now);
    check(count($result['events']) === 1, $key . ' did not emit event');
    check($result['has_more'] === false && !isset($result['state']['window']), 'Finished window releases cursor');
    check($result['events'][0]['type'] === $key, 'Stable exact event type');
    check(!str_contains(json_encode($result), 'DO-NOT-COPY'), 'No full payloads in results/state');
    check(!str_contains(json_encode($result), 'secret-token-only'), 'No tokens in results/state');
    $request = $history[0]['request'];
    check($request->getUri()->getScheme() === 'https', 'TLS required');
    check(!str_contains((string) $request->getUri(), 'secret-'), 'Tokens never in query');
    check($history[0]['options']['verify'] === true && $history[0]['options']['allow_redirects'] === false, 'Transport options safe');
    // Overlap returns the same ID. The shared durable layer deduplicates per trigger, not per polling process.
    $p = provider([response($fixtures[$name], ['X-WP-TotalPages' => '1'])], $history);
    $again = $p->poll($key, $configs[$name], $secrets[$name], $result['state'], $now + 60);
    check($again['events'][0]['id'] === $result['events'][0]['id'], 'Overlap dedupe identity must survive restart');
}
// Cursor persisted across ticks; no upper-window drift when cron is late.
$first = $fixtures['notion']; $first['has_more'] = true; $first['next_cursor'] = 'opaque-page-two';
$p = provider([response($first), response(['results' => [], 'has_more' => false])], $history);
$state = ['activated_at' => $activated, 'watermark' => $activated];
$page1 = $p->poll('notion_page_updated', $configs['notion'], $secrets['notion'], $state, $now);
check($page1['has_more'] && $page1['state']['window']['cursor'] === 'opaque-page-two', 'Cursor persisted');
$page2 = $p->poll('notion_page_updated', $configs['notion'], $secrets['notion'], $page1['state'], $now + 3600);
check(!$page2['has_more'] && strtotime($page2['state']['watermark']) === $now - 30, 'Frozen upper bound across restart');
check(json_decode((string) $history[1]['request']->getBody(), true)['start_cursor'] === 'opaque-page-two', 'Cursor reused without URL following');
// Invalid/non-monotone pagination cannot silently advance state.
$p = provider([response($first)], $history);
$invalidCursor = $page1['state'];
fails(fn () => $p->poll('notion_page_updated', $configs['notion'], $secrets['notion'], $invalidCursor, $now));
// Exact activation boundary, stale and future records are filtered, including unordered pages.
$dates = [$activated, '2026-10-24T10:00:00Z', '2026-10-25T11:00:00Z', $at];
$records = [];
foreach ($dates as $i => $date) { $records[] = ['id' => 'page' . $i, 'created_time' => $date, 'last_edited_time' => $date]; }
$p = provider([response(['results' => $records, 'has_more' => false])], $history);
$r = $p->poll('notion_page_updated', $configs['notion'], $secrets['notion'], $state, $now);
check(count($r['events']) === 1 && $r['events'][0]['data']['id'] === 'page3', 'Local boundaries reject historical/out of order future records');
// Salesforce bounded keyset remains safe with timestamp ties.
$sf = $fixtures['salesforce']; $sf['records'] = [];
for ($i = 0; $i < 100; $i++) { $sf['records'][] = ['Id' => '00Q' . str_pad((string) $i, 15, '0', STR_PAD_LEFT), 'CreatedDate' => $at, 'SystemModstamp' => $at]; }
$p = provider([response($sf), response(['records' => []])], $history);
$a = $p->poll('salesforce_record_updated', $configs['salesforce'], $secrets['salesforce'], $state, $now);
check(count($a['events']) === 100 && $a['has_more'], 'Salesforce bounded keyset');
$b = $p->poll('salesforce_record_updated', $configs['salesforce'], $secrets['salesforce'], $a['state'], $now + 60);
check(!$b['has_more'] && str_contains($history[1]['request']->getUri()->getQuery(), 'Id%20%3E'), 'Salesforce ID tie-breaker');
// Provider errors: retry reads only; never leak provider text or requests.
foreach ([408, 429, 500, 503] as $httpStatus) {
    $p = provider([new Response($httpStatus, ['Retry-After' => '123'], 'SECRET-REMOTE')], $history);
    $error = fails(fn () => $p->poll('asana_task_updated', $configs['asana'], $secrets['asana'], $state, $now), TriggerRetryException::class);
    check($error->retryAfter === 123 && !str_contains($error->getMessage(), 'SECRET'), 'Retry after and safe error');
}
foreach ([401, 403, 400] as $httpStatus) {
    $p = provider([new Response($httpStatus, [], 'SECRET-REMOTE')], $history);
    $error = fails(fn () => $p->poll('asana_task_updated', $configs['asana'], $secrets['asana'], $state, $now));
    check(!str_contains($error->getMessage(), 'SECRET'), 'Permanent safe error');
}
$p = provider([new ConnectException('secret-token-in-exception', new Request('GET', 'https://example.com'))], $history);
fails(fn () => $p->poll('asana_task_updated', $configs['asana'], $secrets['asana'], $state, $now), TriggerRetryException::class);
$p = provider([new Response(200, [], '{invalid')], $history);
fails(fn () => $p->poll('asana_task_updated', $configs['asana'], $secrets['asana'], $state, $now));
$p = provider([response(['data' => [['gid' => '42', 'modified_at' => 'bad-date']]])], $history);
fails(fn () => $p->poll('asana_task_updated', $configs['asana'], $secrets['asana'], $state, $now));
$p = provider([response(['total' => 10001, 'results' => []])], $history);
fails(fn () => $p->poll('hubspot_contact_created', [], $secrets['hubspot'], $state, $now));
$p = provider([], $history);
fails(fn () => $p->poll('jira_issue_updated', ['project_key' => 'DEMO', 'user_timezone' => 'Europe/Madrid'], $secrets['jira'], $state, $now), InvalidArgumentException::class);
fails(fn () => $p->poll('airtable_record_updated', ['base_id' => 'appDemo', 'table' => 'tbl'], $secrets['airtable'], $state, $now), InvalidArgumentException::class);
fails(fn () => $p->poll('salesforce_record_updated', ['object' => 'Lead WHERE Id!=null'], $secrets['salesforce'], $state, $now), InvalidArgumentException::class);
foreach (['https://example.com?x=1', 'https://example.com#frag', 'http://example.com', 'https://localhost', 'https://127.0.0.1', 'https://example.com:443'] as $url) {
    fails(fn () => $p->poll('wordpress_post_updated', [], array_replace($secrets['wordpress'], ['site_url' => $url]), $state, $now), InvalidArgumentException::class);
}
// HMAC Svix Resend: raw bytes, rotation, altered body, wrong receiver and replay.
$secretBytes = str_repeat('r', 32); $resendSecrets = ['signing_secret' => 'whsec_' . base64_encode($secretBytes)];
$raw = json_encode(['type' => 'email.delivered', 'created_at' => $at, 'data' => ['email_id' => 'email-demo', 'to' => ['private@example.com']]]);
$ts = (string) $now;
$headers = ['svix-id' => 'msg-demo', 'svix-timestamp' => $ts, 'svix-signature' => 'v1,' . base64_encode(hash_hmac('sha256', 'msg-demo.' . $ts . '.' . $raw, $secretBytes, true))];
$request = ['method' => 'POST', 'headers' => $headers, 'raw_body' => $raw];
$r = $catalog->verify('resend_email_delivered', [], $resendSecrets, $request, $now);
check(count($r['events']) === 1 && !str_contains(json_encode($r), 'private@'), 'Resend safe metadata');
check($catalog->verify('resend_email_delivered', [], $resendSecrets, $request, $now)['events'][0]['id'] === $r['events'][0]['id'], 'Resend stable ID');
fails(fn () => $catalog->verify('resend_email_delivered', [], $resendSecrets, array_replace($request, ['raw_body' => $raw . ' ']), $now));
fails(fn () => $catalog->verify('resend_email_delivered', [], ['signing_secret' => 'whsec_' . base64_encode(str_repeat('x', 32))], $request, $now));
fails(fn () => $catalog->verify('resend_email_delivered', [], $resendSecrets, $request, $now + 301));
check($catalog->verify('resend_email_bounced', [], $resendSecrets, $request, $now)['events'] === [], 'Resend exact event filtering');
// SendGrid ECDSA signature over timestamp + raw body, ASN.1 DER public key.
$privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048]);
$public = openssl_pkey_get_details($privateKey)['key'];
$publicDerBase64 = preg_replace('/-----[^-]+-----|\s/', '', $public);
$sgRaw = json_encode([['event' => 'delivered', 'sg_event_id' => 'evt-demo', 'sg_message_id' => 'msg-demo', 'timestamp' => $now, 'email' => 'private@example.com']]);
openssl_sign($ts . $sgRaw, $sgSig, $privateKey, OPENSSL_ALGO_SHA256);
$sgReq = ['method' => 'POST', 'raw_body' => $sgRaw, 'headers' => ['x-twilio-email-event-webhook-timestamp' => $ts, 'x-twilio-email-event-webhook-signature' => base64_encode($sgSig)]];
$r = $catalog->verify('sendgrid_email_delivered', [], ['verification_key' => $publicDerBase64], $sgReq, $now);
check(count($r['events']) === 1 && !str_contains(json_encode($r), 'private@'), 'SendGrid ECDSA verified and filtered');
fails(fn () => $catalog->verify('sendgrid_email_delivered', [], ['verification_key' => $publicDerBase64], array_replace($sgReq, ['raw_body' => $sgRaw . ' ']), $now));
fails(fn () => $catalog->verify('sendgrid_email_delivered', [], ['verification_key' => $publicDerBase64], $sgReq, $now + 301));
// Mailchimp signed Marketing delivery; no unsigned legacy URL secrets.
$mcSecret = ['signing_secret' => 'mailchimp-signing-only-123'];
$mcRaw = http_build_query(['type' => 'subscribe', 'fired_at' => '2026-10-25 10:02:00', 'data' => ['list_id' => 'audience-demo', 'id' => 'subscriber-demo', 'email' => 'private@example.com']]);
$mcReq = ['method' => 'POST', 'raw_body' => $mcRaw, 'headers' => ['x-mailchimp-signature' => 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $mcRaw, $mcSecret['signing_secret'])]];
$r = $catalog->verify('mailchimp_subscriber_changed', ['list_id' => 'audience-demo'], $mcSecret, $mcReq, $now);
check(count($r['events']) === 1 && !str_contains(json_encode($r), 'private@'), 'Mailchimp signed verified');
fails(fn () => $catalog->verify('mailchimp_subscriber_changed', ['list_id' => 'other-audience'], $mcSecret, $mcReq, $now));
fails(fn () => $catalog->verify('mailchimp_subscriber_changed', ['list_id' => 'audience-demo'], $mcSecret, array_replace($mcReq, ['raw_body' => $mcRaw . 'x']), $now));
fails(fn () => $catalog->verify('mailchimp_subscriber_changed', ['list_id' => 'audience-demo'], $mcSecret, array_replace($mcReq, ['headers' => []]), $now));
// LinkedIn validated GET challenge and exact official HMAC prefix, tenant isolation.
$liSecret = ['client_secret' => 'linkedin-client-secret-123'];
$challenge = '890e4665-4dfe-4ab1-b689-ed553bceeed0';
$r = $catalog->verify('linkedin_organization_social_action', [], $liSecret, ['method' => 'GET', 'query' => ['challengeCode' => $challenge]], $now);
check(json_decode($r['body'], true)['challengeResponse'] === hash_hmac('sha256', $challenge, $liSecret['client_secret']), 'LinkedIn challenge signature');
$liRaw = json_encode(['type' => 'ORGANIZATION_SOCIAL_ACTION_NOTIFICATIONS', 'notifications' => [['notificationId' => 123, 'organizationalEntity' => 'urn:li:organization:42', 'action' => 'LIKE', 'lastModifiedAt' => $now * 1000, 'private' => 'DO-NOT-COPY']]]);
$liReq = ['method' => 'POST', 'raw_body' => $liRaw, 'headers' => ['x-li-signature' => hash_hmac('sha256', 'hmacsha256=' . $liRaw, $liSecret['client_secret'])]];
$r = $catalog->verify('linkedin_organization_social_action', ['organization_urn' => 'urn:li:organization:42'], $liSecret, $liReq, $now);
check(count($r['events']) === 1 && !str_contains(json_encode($r), 'DO-NOT-COPY'), 'LinkedIn notification verified');
fails(fn () => $catalog->verify('linkedin_organization_social_action', ['organization_urn' => 'urn:li:organization:99'], $liSecret, $liReq, $now));
fails(fn () => $catalog->verify('linkedin_organization_social_action', ['organization_urn' => 'urn:li:organization:42'], $liSecret, array_replace($liReq, ['raw_body' => $liRaw . ' ']), $now));
// PayPal authenticates server-to-server without following attacker-provided certificate URL.
$ppRaw = json_encode(['id' => 'WH-DEMO', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'create_time' => $at, 'resource' => ['id' => 'CAPTURE-DEMO', 'status' => 'COMPLETED', 'payer' => 'DO-NOT-COPY']]);
$ppSecrets = ['client_id' => 'client-demo', 'client_secret' => 'paypal-client-secret-123', 'webhook_id' => 'webhook-demo'];
$ppReq = ['method' => 'POST', 'raw_body' => $ppRaw, 'headers' => ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://attacker.example.invalid/cert', 'paypal-transmission-id' => 'transmission-demo', 'paypal-transmission-sig' => 'signature-demo', 'paypal-transmission-time' => gmdate('Y-m-d\TH:i:s\Z', $now)]];
$p = provider([response(['access_token' => 'oauth-private-demo']), response(['verification_status' => 'SUCCESS'])], $history);
$r = $p->verify('paypal_payment_capture_completed', ['environment' => 'sandbox'], $ppSecrets, $ppReq, $now);
check(count($r['events']) === 1 && count($history) === 2, 'PayPal postback verification');
check($history[1]['request']->getUri()->getHost() === 'api-m.sandbox.paypal.com', 'No certificate SSRF');
check(json_decode((string) $history[1]['request']->getBody(), true)['webhook_id'] === 'webhook-demo', 'PayPal receiver isolation');
check(!str_contains(json_encode($r), 'DO-NOT-COPY'), 'PayPal filtered metadata');
$p = provider([response(['access_token' => 'oauth-private-demo']), response(['verification_status' => 'FAILURE'])], $history);
fails(fn () => $p->verify('paypal_payment_capture_completed', ['environment' => 'sandbox'], $ppSecrets, $ppReq, $now));
fails(fn () => $catalog->verify('paypal_payment_capture_completed', ['environment' => 'sandbox'], $ppSecrets, $ppReq, $now + 301));
// Credential/resource binding prevents stale cursors crossing an account or resource change.
$p = provider([], $history);
$bound = $p->poll('asana_task_updated', $configs['asana'], $secrets['asana'], ['activated_at' => $activated], $now)['state'];
fails(fn () => $p->poll('asana_task_updated', $configs['asana'], ['access_token' => 'rotated-secret-token-456'], $bound, $now));
fails(fn () => $p->poll('asana_task_updated', ['project_id' => '999'], $secrets['asana'], $bound, $now));
fails(fn () => $p->poll('asana_task_created', $configs['asana'], $secrets['asana'], $bound, $now));
check($history === [], 'Binding rejection before HTTP');
$p = provider([response(['data' => [['gid' => $secrets['asana']['access_token'], 'modified_at' => $at]]])], $history);
fails(fn () => $p->poll('asana_task_updated', $configs['asana'], $secrets['asana'], $state, $now));
$p = provider([response(['issues' => [['id' => '11', 'fields' => ['updated' => $at, 'status' => ['id' => ['private_payload' => 'DO-NOT-COPY']]]]], 'isLast' => true])], $history);
fails(fn () => $p->poll('jira_issue_updated', $configs['jira'], $secrets['jira'], $state, $now));
$p = provider([response(['issues' => [], 'isLast' => false])], $history);
fails(fn () => $p->poll('jira_issue_updated', $configs['jira'], $secrets['jira'], $state, $now));
$p = provider([response(['results' => []])], $history);
fails(fn () => $p->poll('notion_page_updated', $configs['notion'], $secrets['notion'], $state, $now));
// Both email event variants and PayPal subscription use the same verified protocol and exact type selection.
$bounceRaw = json_encode(['type' => 'email.bounced', 'created_at' => $at, 'data' => ['email_id' => 'bounce-demo']]);
$bounceReq = ['method' => 'POST', 'raw_body' => $bounceRaw, 'headers' => ['svix-id' => 'msg-bounced', 'svix-timestamp' => $ts,
    'svix-signature' => 'v1,' . base64_encode(hash_hmac('sha256', 'msg-bounced.' . $ts . '.' . $bounceRaw, $secretBytes, true))]];
check(count($catalog->verify('resend_email_bounced', [], $resendSecrets, $bounceReq, $now)['events']) === 1, 'Resend bounce exact event');
$sgBounce = json_encode([['event' => 'bounce', 'sg_event_id' => 'evt-bounce', 'sg_message_id' => 'msg-bounce', 'timestamp' => $now]]);
openssl_sign($ts . $sgBounce, $sgBounceSig, $privateKey, OPENSSL_ALGO_SHA256);
$sgBounceReq = ['method' => 'POST', 'raw_body' => $sgBounce, 'headers' => ['x-twilio-email-event-webhook-timestamp' => $ts, 'x-twilio-email-event-webhook-signature' => base64_encode($sgBounceSig)]];
check(count($catalog->verify('sendgrid_email_bounced', [], ['verification_key' => $publicDerBase64], $sgBounceReq, $now)['events']) === 1, 'SendGrid bounce exact event');
$subscriptionRaw = json_encode(['id' => 'WH-SUB-DEMO', 'event_type' => 'BILLING.SUBSCRIPTION.CREATED', 'create_time' => $at, 'resource' => ['id' => 'I-SUB-DEMO', 'status' => 'APPROVAL_PENDING']]);
$p = provider([response(['access_token' => 'oauth-private-demo']), response(['verification_status' => 'SUCCESS'])], $history);
$subscription = $p->verify('paypal_subscription_created', ['environment' => 'sandbox'], $ppSecrets, array_replace($ppReq, ['raw_body' => $subscriptionRaw]), $now);
check($subscription['events'][0]['data']['subscription_id'] === 'I-SUB-DEMO', 'PayPal subscription exact event');
// HubSpot exact property events: HMAC v3, selected URI decoding, portal/app isolation and retry-stable identity.
$hsSecret = ['client_secret' => 'hubspot-client-secret-only-123'];
$hsConfig = ['portal_id' => '33', 'app_id' => '1160452', 'property_name' => 'dealstage'];
$hsUrl = 'https://p8p.example.com/trigger_webhook.php?id=receiver-demo&extra=%3A%2F%40%26';
$hsDecodedUrl = 'https://p8p.example.com/trigger_webhook.php?id=receiver-demo&extra=:/@%26';
$hsTs = (string) ($now * 1000);
$hsRow = ['objectId' => 1246965, 'propertyName' => 'dealstage', 'propertyValue' => 'PRIVATE-VALUE',
    'eventId' => 3816279340, 'subscriptionId' => 25, 'portalId' => 33, 'appId' => 1160452,
    'occurredAt' => $now * 1000, 'subscriptionType' => 'deal.propertyChange', 'attemptNumber' => 0];
$hsRequest = static function (array $rows) use ($hsUrl, $hsDecodedUrl, $hsTs, $hsSecret): array {
    $raw = json_encode($rows);
    return ['method' => 'POST', 'raw_body' => $raw, 'callback_url' => $hsUrl,
        'headers' => ['x-hubspot-request-timestamp' => $hsTs,
            'x-hubspot-signature-v3' => base64_encode(hash_hmac('sha256', 'POST' . $hsDecodedUrl . $raw . $hsTs, $hsSecret['client_secret'], true))]];
};
foreach (['contact_created' => 'contact.creation', 'contact_property_changed' => 'contact.propertyChange',
    'deal_created' => 'deal.creation', 'deal_property_changed' => 'deal.propertyChange'] as $suffix => $eventType) {
    $row = array_replace($hsRow, ['subscriptionType' => $eventType]);
    $config = str_ends_with($suffix, 'created') ? ['portal_id' => '33', 'app_id' => '1160452'] : $hsConfig;
    $request = $hsRequest([$row]);
    $key = 'hubspot_webhook_' . $suffix;
    $r = $catalog->verify($key, $config, $hsSecret, $request, $now);
    check(count($r['events']) === 1, 'HubSpot exact event ' . $eventType);
    check(!str_contains(json_encode($r), 'PRIVATE-VALUE'), 'HubSpot never emits property values');
    $retried = $catalog->verify($key, $config, $hsSecret, $hsRequest([array_replace($row, ['attemptNumber' => 9])]), $now);
    check($retried['events'][0]['id'] === $r['events'][0]['id'], 'HubSpot attemptNumber excluded from identity');
    fails(fn () => $catalog->verify($key, $config, $hsSecret, array_replace($request, ['raw_body' => $request['raw_body'] . ' ']), $now));
    fails(fn () => $catalog->verify($key, $config, $hsSecret, array_replace($request, ['callback_url' => $hsUrl . 'x']), $now));
    fails(fn () => $catalog->verify($key, $config, $hsSecret, $request, $now + 301));
    fails(fn () => $catalog->verify($key, $config, ['client_secret' => 'other-hubspot-app-secret'], $request, $now));
}
$hsKey = 'hubspot_webhook_deal_property_changed';
fails(fn () => $catalog->verify($hsKey, array_replace($hsConfig, ['portal_id' => '99']), $hsSecret, $hsRequest([$hsRow]), $now));
fails(fn () => $catalog->verify($hsKey, array_replace($hsConfig, ['app_id' => '99']), $hsSecret, $hsRequest([$hsRow]), $now));
fails(fn () => $catalog->verify($hsKey, $hsConfig, $hsSecret, $hsRequest(array_fill(0, 101, $hsRow)), $now));
fails(fn () => $catalog->verify($hsKey, $hsConfig, $hsSecret, array_replace($hsRequest([$hsRow]), ['headers' => []]), $now));
fails(fn () => $catalog->verify($hsKey, array_replace($hsConfig, ['property_name' => 'hs_lastmodifieddate']), $hsSecret, $hsRequest([$hsRow]), $now));
check($catalog->verify($hsKey, array_replace($hsConfig, ['property_name' => 'amount']), $hsSecret, $hsRequest([$hsRow]), $now)['events'] === [], 'HubSpot filters unsubscribed property');
$collision = $catalog->verify($hsKey, $hsConfig, $hsSecret, $hsRequest([$hsRow, array_replace($hsRow, ['propertyValue' => 'DIFFERENT-VALUE'])]), $now);
check(count(array_unique(array_column($collision['events'], 'id'))) === 2, 'Non-unique HubSpot eventId does not collapse distinct property events');
$reordered = $catalog->verify($hsKey, $hsConfig, $hsSecret, $hsRequest([array_replace($hsRow, ['occurredAt' => ($now - 1000) * 1000]), $hsRow]), $now);
check(count($reordered['events']) === 2, 'HubSpot accepts validly signed out-of-order events');
// Fractional creation just after activation must not be discarded by whole-second comparisons.
$p = provider([response(['results' => [['id' => 'fraction-page', 'created_time' => '2026-10-25T10:00:00.100Z', 'last_edited_time' => $at]], 'has_more' => false])], $history);
check(count($p->poll('notion_page_created', $configs['notion'], $secrets['notion'], $state, $now)['events']) === 1, 'Fractional activation boundary');
$p = provider([new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', $now + 91)])], $history);
$e = fails(fn () => $p->poll('asana_task_updated', $configs['asana'], $secrets['asana'], $state, $now), TriggerRetryException::class);
check($e->retryAfter === 91, 'Retry-After date uses injected clock');
echo "Business trigger adapters: {$checks} checks passed (mock HTTP, no DB/network).\n";
