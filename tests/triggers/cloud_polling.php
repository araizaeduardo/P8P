<?php

/** Offline regression tests. Run: php tests/triggers/cloud_polling.php */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use P8P\Core\TriggerProviders\CloudPollingProviders;
use P8P\Core\TriggerRetryException;

$assertions = 0;
$now = strtotime('2026-10-25T12:00:00Z');
$active = ['activated_at' => '2026-10-25T11:00:00Z'];
$secret = ['access_token' => 'SENSITIVE_TEST_BEARER'];

function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function response(array $data, int $status = 200, array $headers = []): Response
{
    return new Response($status, $headers, json_encode($data, JSON_THROW_ON_ERROR));
}

function adapter(array $queue, array &$history): CloudPollingProviders
{
    $history = [];
    $handler = HandlerStack::create(new MockHandler($queue));
    $handler->push(Middleware::history($history));
    return new CloudPollingProviders(new Client(['handler' => $handler]));
}

function rejected(callable $call, string $contains = ''): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        check($contains === '' || str_contains($e->getMessage(), $contains), 'Unexpected safe error: ' . $e->getMessage());
        check(!str_contains($e->getMessage(), 'SENSITIVE_TEST_BEARER'), 'Credential in exception');
        check($e->getPrevious() === null, 'Unsafe exception chain');
        return $e;
    }
    throw new RuntimeException('Expected rejection');
}

$provider = new CloudPollingProviders();
check(count($provider->definitions()) === 12, 'Expected all twelve cloud events');
foreach ($provider->definitions() as $key => $definition) {
    check($definition['transport'] === 'polling', $key . ' transport');
    check(isset($definition['node_type'], $definition['event'], $definition['config_fields'], $definition['secret_fields'], $definition['docs']), $key . ' metadata');
    rejected(fn () => $provider->verify($key, [], [], [], $now), 'polling');
}
rejected(fn () => $provider->poll('unknown', [], $secret, [], $now), 'desconocido');
rejected(fn () => $provider->poll('google_drive_changes', [], ['access_token' => "bad\r\nheader"], [], $now), 'access_token');

// X uses an activation lower bound; continuation never advances since_id prematurely.
$lower = (string) (($now - 3600) * 1000 - 1288834974657 << 22);
$id1 = (string) ((int) $lower + 100);
$id2 = (string) ((int) $lower + 50);
$history = [];
$provider = adapter([
    response(['data' => [['id' => $id1, 'text' => 'Hello', 'created_at' => '2026-10-25T11:00:00Z']], 'meta' => ['next_token' => 'page2']]),
    response(['data' => [['id' => $id2, 'text' => 'Earlier']], 'meta' => []]),
    response(['data' => [['id' => $id1, 'text' => 'Repeated out of order']], 'meta' => []]),
], $history);
$r1 = $provider->poll('x_mentions', ['user_id' => '123'], $secret, $active, $now);
check(count($r1['events']) === 1 && $r1['has_more'], 'X first page includes activation second');
check($r1['state']['since_id'] === $lower, 'X lower bound held during pagination');
$r2 = $provider->poll('x_mentions', ['user_id' => '123'], $secret, $r1['state'], $now + 1);
check(count($r2['events']) === 1 && !$r2['has_more'] && $r2['state']['since_id'] === $id1, 'X final maximum persisted');
$r3 = $provider->poll('x_mentions', ['user_id' => '123'], $secret, $r2['state'], $now + 60);
check($r3['events'] === [], 'X repeated older ID filtered');
parse_str($history[1]['request']->getUri()->getQuery(), $query);
check($query['pagination_token'] === 'page2' && $query['since_id'] === $lower, 'X continuation query');
rejected(fn () => $provider->poll('x_mentions', ['user_id' => '456'], $secret, $r2['state'], $now), 'cambiada');
$provider = adapter([response(['data' => [], 'meta' => []])], $history);
$provider->poll('x_hashtag', ['hashtag' => 'p8p'], $secret, $active, $now);
parse_str($history[0]['request']->getUri()->getQuery(), $query);
check($query['query'] === '#p8p' && $query['max_results'] === '100', 'Hashtag official query');
rejected(fn () => $provider->poll('x_hashtag', ['hashtag' => 'p8p OR anything'], $secret, $active, $now), 'hashtag');

// YouTube only top-level published comments; old history stops page traversal.
$thread = static fn ($id, $date) => ['snippet' => ['topLevelComment' => ['id' => $id, 'snippet' => ['publishedAt' => $date, 'textDisplay' => 'A SENSITIVE_TEST_BEARER echo']]]];
$provider = adapter([
    response(['items' => [$thread('c1', '2026-10-25T11:00:00Z')], 'nextPageToken' => 'more']),
    response(['items' => [$thread('old', '2026-10-24T12:00:00Z')], 'nextPageToken' => 'historic']),
], $history);
$r1 = $provider->poll('youtube_comments', ['video_id' => 'video1'], $secret, $active, $now);
check($r1['has_more'] && $r1['events'][0]['id'] === 'c1', 'YouTube recent first page');
check(!str_contains(json_encode($r1['events']), 'SENSITIVE_TEST_BEARER'), 'Configured token redacted in echoed text');
$r2 = $provider->poll('youtube_comments', ['video_id' => 'video1'], $secret, $r1['state'], $now + 100);
check(!$r2['has_more'] && $r2['events'] === [], 'YouTube historical boundary');
check($r2['state']['watermark'] === '2026-10-25T11:58:00Z', 'YouTube cycle watermark frozen across pages');

// Forms new submission is distinct from resubmission; stable timestamp event IDs.
$newResponse = ['responseId' => 'r1', 'createTime' => '2026-10-25T11:10:00Z', 'lastSubmittedTime' => '2026-10-25T11:10:00Z', 'answers' => ['q1' => ['questionId' => 'q1', 'textAnswers' => ['answers' => [['value' => 'Hello']]], 'fileUploadAnswers' => ['answers' => [['fileId' => 'private']]]]]];
$updatedResponse = ['responseId' => 'r2', 'createTime' => '2026-10-24T10:00:00Z', 'lastSubmittedTime' => '2026-10-25T11:20:00Z'];
$provider = adapter([
    response(['responses' => [$newResponse, $updatedResponse], 'nextPageToken' => 'f2']),
    response(['responses' => []]),
    response(['responses' => [$newResponse, $updatedResponse]]),
], $history);
$r1 = $provider->poll('google_forms_new_response', ['form_id' => 'form1'], $secret, $active, $now);
check($r1['has_more'] && count($r1['events']) === 1 && $r1['events'][0]['id'] === 'r1', 'Forms separates new response');
check(!str_contains(json_encode($r1['events']), 'fileUploadAnswers'), 'Forms file URLs excluded');
$r2 = $provider->poll('google_forms_new_response', ['form_id' => 'form1'], $secret, $r1['state'], $now + 30);
parse_str($history[0]['request']->getUri()->getQuery(), $q1);
parse_str($history[1]['request']->getUri()->getQuery(), $q2);
check($q1['filter'] === $q2['filter'] && $q2['pageToken'] === 'f2', 'Forms immutable page filter');
check(!$r2['has_more'], 'Forms finishes continuation');
$r3 = $provider->poll('google_forms_response_changed', ['form_id' => 'form1'], $secret, $active, $now);
check(count($r3['events']) === 1 && $r3['events'][0]['id'] === 'r2:2026-10-25T11:20:00Z', 'Forms exact updated event identity');

// Drive starts from current change token, with no historical scan.
$provider = adapter([
    response(['startPageToken' => 'd0']),
    response(['changes' => [['fileId' => 'file1', 'time' => '2026-10-25T12:00:01Z', 'file' => ['id' => 'file1', 'version' => '42', 'webContentLink' => 'https://private.invalid/?token=secret']]], 'nextPageToken' => 'd1']),
    response(['changes' => [['fileId' => 'file2', 'removed' => true, 'time' => '2026-10-25T12:00:02Z']], 'newStartPageToken' => 'd2']),
], $history);
$r0 = $provider->poll('google_drive_changes', [], $secret, $active, $now);
check($r0['events'] === [] && $r0['state']['cursor'] === 'd0', 'Drive no initial history');
$r1 = $provider->poll('google_drive_changes', [], $secret, $r0['state'], $now + 1);
check($r1['has_more'] && $r1['state']['cursor'] === 'd1', 'Drive nextPageToken');
check(!str_contains(json_encode($r1['events']), 'webContentLink'), 'Drive excludes download URLs');
$r2 = $provider->poll('google_drive_changes', [], $secret, $r1['state'], $now + 2);
check(!$r2['has_more'] && $r2['state']['cursor'] === 'd2' && $r2['events'][0]['data']['removed'], 'Drive removal and new token');

// Calendar full baseline paginates, then syncToken must remain unchanged through incremental pages.
$oldEvent = ['id' => 'old', 'updated' => '2026-10-24T12:00:00Z', 'etag' => 'v0'];
$newEvent = ['id' => 'event1', 'updated' => '2026-10-25T11:01:00Z', 'etag' => 'v1', 'summary' => 'Event'];
$provider = adapter([
    response(['items' => [$oldEvent], 'nextPageToken' => 'c2']),
    response(['items' => [$newEvent], 'nextSyncToken' => 's1']),
    response(['items' => [['id' => 'cancelled', 'status' => 'cancelled', 'etag' => 'v2']], 'nextPageToken' => 'c3']),
    response(['items' => [], 'nextSyncToken' => 's2']),
], $history);
$r1 = $provider->poll('google_calendar_changes', ['calendar_id' => 'primary'], $secret, $active, $now);
check($r1['events'] === [] && $r1['has_more'], 'Calendar old baseline suppressed');
$r2 = $provider->poll('google_calendar_changes', ['calendar_id' => 'primary'], $secret, $r1['state'], $now + 1);
check(count($r2['events']) === 1 && $r2['state']['sync'] === 's1', 'Calendar updates since activation');
$r3 = $provider->poll('google_calendar_changes', ['calendar_id' => 'primary'], $secret, $r2['state'], $now + 2);
check($r3['events'][0]['data']['status'] === 'cancelled' && $r3['state']['sync'] === 's1', 'Calendar cancellation and frozen sync');
$r4 = $provider->poll('google_calendar_changes', ['calendar_id' => 'primary'], $secret, $r3['state'], $now + 3);
parse_str($history[3]['request']->getUri()->getQuery(), $query);
check($query['syncToken'] === 's1' && $query['pageToken'] === 'c3' && $r4['state']['sync'] === 's2', 'Calendar incremental continuation');

// Graph mail: creation-only delta, guarded against unrelated returned changes.
$mailPath = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta';
$provider = adapter([
    response(['value' => [['id' => 'm1', 'receivedDateTime' => '2026-10-25T11:01:00Z', 'subject' => 'Hello'], ['id' => 'old', 'receivedDateTime' => '2026-10-20T00:00:00Z'], ['id' => 'removed', '@removed' => ['reason' => 'deleted']]], '@odata.nextLink' => $mailPath . '?$skiptoken=next']),
    response(['value' => [], '@odata.deltaLink' => $mailPath . '?$deltatoken=done']),
], $history);
$r1 = $provider->poll('microsoft_graph_new_mail', ['mail_folder_id' => 'inbox'], $secret, $active, $now);
check(count($r1['events']) === 1 && $r1['events'][0]['id'] === 'm1' && $r1['has_more'], 'Graph only recent mail');
$r2 = $provider->poll('microsoft_graph_new_mail', ['mail_folder_id' => 'inbox'], $secret, $r1['state'], $now + 1);
check(!$r2['has_more'] && str_contains((string) $history[1]['request']->getUri(), '$skiptoken=next'), 'Graph follows exact continuation query');
parse_str($history[0]['request']->getUri()->getQuery(), $query);
check($query['changeType'] === 'created' && $query['$top'] === '100', 'Graph mail delta creation filter');
check(str_contains($history[0]['request']->getHeaderLine('Prefer'), 'ImmutableId'), 'Graph immutable mail IDs');
rejected(fn () => $provider->poll('microsoft_graph_new_mail', ['mail_folder_id' => 'inbox', 'token_mode' => 'application'], $secret, $active, $now), 'user_id');

// OneDrive latest baseline, then delta with file/removal metadata only.
$drivePath = 'https://graph.microsoft.com/v1.0/drives/drive1/root/delta';
$provider = adapter([
    response(['value' => [], '@odata.deltaLink' => $drivePath . '?token=start']),
    response(['value' => [['id' => 'i1', 'name' => 'File', 'eTag' => 'v1', '@microsoft.graph.downloadUrl' => 'https://private.invalid/signed'], ['id' => 'i2', 'deleted' => ['state' => 'deleted']]], '@odata.deltaLink' => $drivePath . '?token=done']),
], $history);
$config = ['drive_id' => 'drive1', 'token_mode' => 'application'];
$r0 = $provider->poll('onedrive_changes', $config, $secret, $active, $now);
check($r0['events'] === [], 'OneDrive latest baseline empty');
parse_str($history[0]['request']->getUri()->getQuery(), $query);
check($query['token'] === 'latest', 'OneDrive latest request');
$r1 = $provider->poll('onedrive_changes', $config, $secret, $r0['state'], $now + 1);
check(count($r1['events']) === 2 && !str_contains(json_encode($r1['events']), 'downloadUrl'), 'OneDrive safe changes/removal');
rejected(fn () => $provider->poll('onedrive_changes', ['token_mode' => 'application'], $secret, $active, $now), 'drive_id');
foreach (['https://evil.invalid/v1.0/drives/drive1/root/delta?token=stolen', 'https://graph.microsoft.com/v1.0/drives/other/root/delta?token=other', 'https://graph.microsoft.com:443/v1.0/drives/drive1/root/delta?token=port', 'https://user:pass@graph.microsoft.com/v1.0/drives/drive1/root/delta?token=user', $drivePath . '?$expand=permissions', $drivePath . '?token=ok#fragment'] as $unsafe) {
    $provider = adapter([response(['value' => [], '@odata.deltaLink' => $unsafe])], $history);
    rejected(fn () => $provider->poll('onedrive_changes', $config, $secret, $active, $now), 'Graph');
}

// Snapshot row position semantics include append, modification, deletion, and change back.
$config = ['spreadsheet_id' => 'sheet1', 'range' => 'Hoja1!A1:B3'];
$provider = adapter([
    response(['values' => [['First', 1]]]),
    response(['values' => [['First', 1], ['Second', 2]]]),
    response(['values' => [['Changed', 1], ['Second', 2]]]),
    response(['values' => [['First', 1]]]),
], $history);
$r0 = $provider->poll('google_sheets_row_changes', $config, $secret, $active, $now);
check($r0['events'] === [], 'Sheets initial snapshot silent');
$r1 = $provider->poll('google_sheets_row_changes', $config, $secret, $r0['state'], $now + 1);
check(count($r1['events']) === 1 && $r1['events'][0]['data']['row_number'] === 2, 'Sheets newly occupied row');
$r2 = $provider->poll('google_sheets_row_changes', $config, $secret, $r1['state'], $now + 2);
check(count($r2['events']) === 1, 'Sheets modification');
$r3 = $provider->poll('google_sheets_row_changes', $config, $secret, $r2['state'], $now + 3);
check(count($r3['events']) === 2 && $r3['events'][1]['data']['empty'], 'Sheets change back and emptied row');
check($r2['events'][0]['id'] !== $r3['events'][0]['id'], 'Sheets sequence prevents change-back dedupe loss');
$provider = adapter([response(['values' => [['A']]]), response(['values' => [['B'], [0]]])], $history);
$r0 = $provider->poll('google_sheets_new_rows', $config, $secret, $active, $now);
$r1 = $provider->poll('google_sheets_new_rows', $config, $secret, $r0['state'], $now + 1);
check(count($r1['events']) === 1 && $r1['events'][0]['data']['values'][0] === 0, 'Sheets new rows ignores edits and treats 0 as occupied');
foreach (['A:A', 'A1:B101', 'A1:ZZ1', 'A100:B1', 'https://evil.invalid/'] as $range) {
    rejected(fn () => $provider->poll('google_sheets_new_rows', ['spreadsheet_id' => 's', 'range' => $range], $secret, $active, $now), 'range');
}

// Docs deliberately observes Drive file version, including metadata changes, not individual edits.
$document = ['id' => 'doc1', 'mimeType' => 'application/vnd.google-apps.document', 'name' => 'Document', 'version' => '10'];
$provider = adapter([response($document), response($document), response(array_replace($document, ['version' => '11', 'modifiedTime' => '2026-10-25T12:02:00Z']))], $history);
$r0 = $provider->poll('google_docs_changed', ['document_id' => 'doc1'], $secret, $active, $now);
$r1 = $provider->poll('google_docs_changed', ['document_id' => 'doc1'], $secret, $r0['state'], $now + 60);
$r2 = $provider->poll('google_docs_changed', ['document_id' => 'doc1'], $secret, $r1['state'], $now + 120);
check($r0['events'] === [] && $r1['events'] === [] && $r2['events'][0]['id'] === 'doc1:11', 'Docs version baseline and change');

// RetryAfter, transient failures, expiry/auth safe errors, invalid JSON and bounded response.
foreach ([response([], 429, ['Retry-After' => '120']), response([], 503, ['Retry-After' => 'Sun, 25 Oct 2026 12:03:00 GMT']), response([], 403, []), response([], 410, []), new Response(200, [], 'not json SENSITIVE_TEST_BEARER'), new Response(200, [], str_repeat('x', 4194305)), new ConnectException('SENSITIVE_TEST_BEARER secret URL', new Request('GET', 'https://private.invalid/'))] as $index => $response) {
    $provider = adapter([$response], $history);
    $error = rejected(fn () => $provider->poll('google_drive_changes', [], $secret, $active, $now));
    if ($index === 0) {
        check($error instanceof TriggerRetryException && $error->retryAfter === 120, '429 Retry-After seconds');
    } elseif ($index === 1) {
        check($error instanceof TriggerRetryException && $error->retryAfter === 180, '503 Retry-After date injected clock');
    } elseif ($index === 6) {
        check($error instanceof TriggerRetryException, 'Transport failure retry read only');
    }
    check(count($history) === 1, 'No inline retry or effects');
}
$provider = adapter([response(['items' => array_fill(0, 101, [])])], $history);
rejected(fn () => $provider->poll('youtube_comments', ['video_id' => 'v'], $secret, $active, $now), '100');
$provider = adapter([response(['items' => [], 'nextPageToken' => 'same'])], $history);
$state = $active;
$r0 = $provider->poll('google_calendar_changes', ['calendar_id' => 'primary'], $secret, $state, $now);
$provider = adapter([response(['items' => [], 'nextPageToken' => 'same'])], $history);
rejected(fn () => $provider->poll('google_calendar_changes', ['calendar_id' => 'primary'], $secret, $r0['state'], $now), 'avanzó');
foreach ($history as $entry) {
    check($entry['options']['verify'] === true && $entry['options']['allow_redirects'] === false, 'TLS and redirects');
    check($entry['request']->getMethod() === 'GET', 'No mutating HTTP operation');
}

echo "Cloud polling: {$assertions} assertions passed (offline HTTP/clock mocks).\n";
