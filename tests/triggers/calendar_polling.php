<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use P8P\Core\TriggerProviders\CalendarPollingProviders;
use P8P\Core\TriggerRetryException;

$checks = 0;
$now = strtotime('2026-10-25T12:00:00Z');
$secret = ['access_token' => 'CALENDAR_TEST_SECRET_TOKEN'];

function check(bool $ok, string $label): void
{
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($label);
}

function response(array $body, int $status = 200, array $headers = []): Response
{
    return new Response($status, $headers, json_encode($body, JSON_THROW_ON_ERROR));
}

function provider(array $queue, array &$history): CalendarPollingProviders
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));
    return new CalendarPollingProviders(new Client(['handler' => $stack]));
}

function rejects(callable $call, string $label): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        check(!str_contains($e->getMessage(), 'CALENDAR_TEST_SECRET_TOKEN') && $e->getPrevious() === null, 'Safe error: ' . $label);
        return $e;
    }
    throw new RuntimeException('Expected rejection: ' . $label);
}

function graphEvent(string $id, string $start, array $extra = []): array
{
    return $extra + ['id' => $id, 'subject' => 'Meeting', 'start' => ['dateTime' => $start, 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-25T13:00:00', 'timeZone' => 'UTC']];
}

$history = [];
$definitions = (new CalendarPollingProviders())->definitions();
check(count($definitions) === 2, 'Both calendars supported');
foreach ($definitions as $key => $definition) {
    check($definition['event'] === 'calendar.event.upcoming' && $definition['transport'] === 'polling', 'Upcoming definition');
    rejects(fn () => (new CalendarPollingProviders())->verify($key, [], [], [], $now), 'No public webhook');
}
$graphPath = 'https://graph.microsoft.com/v1.0/me/calendar/calendarView';
$provider = provider([
    response(['value' => [graphEvent('historic-reminder', '2026-10-25T12:15:00')]]),
    response(['value' => [graphEvent('event1', '2026-10-25T12:15:30.0000000'), graphEvent('all-day', '2026-10-25T12:15:30', ['isAllDay' => true]), graphEvent('cancelled', '2026-10-25T12:15:30', ['isCancelled' => true]), graphEvent('upper-boundary', '2026-10-25T12:16:00')], '@odata.nextLink' => $graphPath . '?$skiptoken=page2']),
    response(['value' => [graphEvent('event2', '2026-10-25T12:15:40'), graphEvent('outside', '2026-10-25T12:14:00')]]),
    response(['value' => [graphEvent('upper-boundary', '2026-10-25T12:16:00'), graphEvent('overlap-repeat', '2026-10-25T12:15:59')]]),
], $history);
$config = ['lead_minutes' => '15'];
$r0 = $provider->poll('microsoft_graph_event_upcoming', $config, $secret, ['activated_at' => '2026-10-24T00:00:00Z'], $now);
check($r0['events'] === [] && $r0['state']['watermark'] === $now && !$r0['has_more'], 'Silent initial baseline at current poll time');
$r1 = $provider->poll('microsoft_graph_event_upcoming', $config, $secret, $r0['state'], $now + 60);
check(count($r1['events']) === 1 && $r1['events'][0]['data']['id'] === 'event1', 'Only due timed future event; half-open upper boundary');
check($r1['has_more'] && $r1['state']['watermark'] === $now, 'Watermark not advanced during paging');
check($r1['events'][0]['occurred_at'] === '2026-10-25T12:00:30Z' && $r1['events'][0]['data']['start'] === '2026-10-25T12:15:30Z', 'UTC canonical start and due_at');
$r2 = $provider->poll('microsoft_graph_event_upcoming', $config, $secret, $r1['state'], $now + 120);
check(count($r2['events']) === 1 && $r2['events'][0]['data']['id'] === 'event2' && !$r2['has_more'], 'Bounded continuation uses frozen due window');
check($r2['state']['watermark'] === $now + 60, 'Only complete window advances watermark');
check((string) $history[2]['request']->getUri() === $graphPath . '?$skiptoken=page2', 'Exact nextLink retained, query not replaced');
$r3 = $provider->poll('microsoft_graph_event_upcoming', $config, $secret, $r2['state'], $now + 180);
check(count($r3['events']) === 2 && $r3['events'][0]['data']['id'] === 'upper-boundary', 'Boundary deferred to next cycle with two-second overlap');
parse_str($history[1]['request']->getUri()->getQuery(), $query);
check($query['startDateTime'] === '2026-10-25T12:14:58Z' && $query['endDateTime'] === '2026-10-25T12:16:00Z' && $query['$top'] === '100', 'Lead translated into provider start range');
check(str_contains($history[1]['request']->getHeaderLine('Prefer'), 'outlook.timezone="UTC"'), 'Explicit UTC Graph response');
foreach ($history as $entry) {
    check($entry['request']->getMethod() === 'GET' && $entry['options']['verify'] === true && $entry['options']['allow_redirects'] === false, 'Read-only TLS request');
    check(!str_contains((string) $entry['request']->getUri(), 'CALENDAR_TEST_SECRET_TOKEN'), 'No bearer in request URL');
}

// Same occurrence and instant dedupes across scans and timezone formatting; changed start creates a new reminder.
$provider = provider([
    response(['value' => [graphEvent('same', '2026-10-25T12:15:30')]]),
    response(['value' => [graphEvent('same', '2026-10-25T06:15:30-06:00')]]),
    response(['value' => [graphEvent('same', '2026-10-25T12:15:31')]]),
], $history);
$state = $r0['state'];
$one = $provider->poll('microsoft_graph_event_upcoming', $config, $secret, $state, $now + 60);
$two = $provider->poll('microsoft_graph_event_upcoming', $config, $secret, $state, $now + 60);
$three = $provider->poll('microsoft_graph_event_upcoming', $config, $secret, $state, $now + 60);
check($one['events'][0]['id'] === $two['events'][0]['id'], 'Stable reminder identity for same UTC instant');
check($one['events'][0]['id'] !== $three['events'][0]['id'], 'Rescheduled start creates distinct reminder');
$beforeRequests = count($history);
$back = $provider->poll('microsoft_graph_event_upcoming', $config, $secret, $r0['state'], $now - 1);
check($back['events'] === [] && count($history) === $beforeRequests, 'Clock reversal never rewinds watermark');

// Google expands recurring instances and retains same frozen query for pageToken.
$googleConfig = ['lead_minutes' => 15, 'calendar_id' => 'primary'];
$event = ['id' => 'instance1', 'summary' => 'Recurring meeting', 'start' => ['dateTime' => '2026-10-25T06:15:30-06:00'], 'end' => ['dateTime' => '2026-10-25T13:00:00Z'], 'recurringEventId' => 'series1', 'htmlLink' => 'https://private.invalid/?token=signed'];
$provider = provider([
    response(['items' => []]),
    response(['items' => [$event, ['id' => 'all', 'start' => ['date' => '2026-10-25']], ['id' => 'cancelled', 'status' => 'cancelled']], 'nextPageToken' => 'g2']),
    response(['items' => []]),
], $history);
$g0 = $provider->poll('google_calendar_event_upcoming', $googleConfig, $secret, [], $now);
$g1 = $provider->poll('google_calendar_event_upcoming', $googleConfig, $secret, $g0['state'], $now + 60);
$g2 = $provider->poll('google_calendar_event_upcoming', $googleConfig, $secret, $g1['state'], $now + 120);
check(count($g1['events']) === 1 && $g1['events'][0]['data']['series_id'] === 'series1', 'Google recurrence instance and all-day/cancelled filtering');
check(!str_contains(json_encode($g1['events']), 'htmlLink'), 'No provider links in event data');
parse_str($history[1]['request']->getUri()->getQuery(), $q1);
parse_str($history[2]['request']->getUri()->getQuery(), $q2);
check($q1['singleEvents'] === 'true' && $q1['timeZone'] === 'UTC' && $q2['pageToken'] === 'g2', 'Google recurring expansion and pageToken');
unset($q2['pageToken']);
check($q1 === $q2 && $g2['state']['watermark'] === $now + 60, 'Frozen Google bounds across restart/continuation');

$provider = provider([response(['value' => []])], $history);
$provider->poll('microsoft_graph_event_upcoming', ['lead_minutes' => '1', 'token_mode' => 'application', 'user_id' => 'user@example.com', 'calendar_id' => 'calendar+/='], $secret, [], $now);
check(str_contains((string) $history[0]['request']->getUri(), '/users/user%40example.com/calendars/calendar%2B%2F%3D/calendarView'), 'Application mailbox and encoded calendar segment');
rejects(fn () => $provider->poll('microsoft_graph_event_upcoming', ['lead_minutes' => 1, 'token_mode' => 'application'], $secret, [], $now), 'Application without mailbox');
foreach ([0, '0', '-1', '1.5', '10081', [], true, null] as $lead) {
    rejects(fn () => $provider->poll('microsoft_graph_event_upcoming', ['lead_minutes' => $lead], $secret, [], $now), 'Invalid anticipation');
}
rejects(fn () => $provider->poll('google_calendar_event_upcoming', ['lead_minutes' => 1], $secret, [], $now), 'Google calendar required');
rejects(fn () => $provider->poll('microsoft_graph_event_upcoming', $config, ['access_token' => "bad\r\n"], [], $now), 'Invalid bearer');
rejects(fn () => $provider->poll('microsoft_graph_event_upcoming', ['lead_minutes' => 20], $secret, $state, $now), 'Cursor binding configuration');

foreach (['https://evil.invalid/v1.0/me/calendar/calendarView?$skiptoken=x', 'https://graph.microsoft.com/v1.0/users/other/calendar/calendarView?$skiptoken=x', 'https://graph.microsoft.com:443/v1.0/me/calendar/calendarView?$skiptoken=x', $graphPath . '?$expand=attachments', $graphPath . '?$skiptoken=x#fragment'] as $unsafe) {
    $provider = provider([response(['value' => [], '@odata.nextLink' => $unsafe])], $history);
    rejects(fn () => $provider->poll('microsoft_graph_event_upcoming', $config, $secret, $state, $now + 60), 'Unsafe Graph continuation');
}
// No expired reminder after prolonged downtime; no history replay despite old activated_at.
$provider = provider([response(['value' => [graphEvent('already-started', '2026-10-25T12:15:30')]])], $history);
$past = $provider->poll('microsoft_graph_event_upcoming', $config, $secret, $state, $now + 3600);
check($past['events'] === [] && $past['state']['watermark'] === $now + 3600, 'Already started events skipped after downtime');

foreach ([response([], 429, ['Retry-After' => '123']), response([], 503, ['Retry-After' => 'Sun, 25 Oct 2026 12:03:00 GMT']), response([], 401), response([], 403), new Response(200, [], 'broken JSON'), response(['value' => array_fill(0, 101, [])])] as $i => $reply) {
    $provider = provider([$reply], $history);
    $error = rejects(fn () => $provider->poll('microsoft_graph_event_upcoming', $config, $secret, [], $now), 'HTTP/shape failure');
    if ($i === 0) check($error instanceof TriggerRetryException && $error->retryAfter === 123, 'Retry-After seconds');
    if ($i === 1) check($error instanceof TriggerRetryException && $error->retryAfter === 180, 'Retry-After HTTP date and injected clock');
    check(count($history) === 1, 'No inline retries');
}
echo "Calendar polling: {$checks} assertions passed (offline HTTP, scheduling windows, UTC clock fixtures).\n";
