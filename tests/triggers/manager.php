<?php

require __DIR__ . '/../../vendor/autoload.php';

use P8P\Core\CredentialManager;
use P8P\Core\Engine;
use P8P\Core\NodeRegistry;
use P8P\Core\QueueManager;
use P8P\Core\TriggerManager;
use P8P\Core\TriggerRegistry;
use P8P\Core\TriggerRetryException;
use P8P\Core\TriggerSchema;
use P8P\Core\WorkflowWorker;
use P8P\Interfaces\NodeInterface;
use P8P\Interfaces\TriggerProviderInterface;
use P8P\Nodes\Core\ServiceTriggerNode;

class ManagerFixtureProvider implements TriggerProviderInterface
{
    public array $pages = [];
    public array $pollCalls = [];
    public array $callbackUrls = [];
    public $duringVerify = null;
    public function definitions(): array
    {
        $base = ['name' => 'Fixture', 'node_type' => 'fixture', 'event' => 'changed',
            'config_fields' => ['tenant' => ['type' => 'string', 'label' => 'Tenant', 'required' => true]],
            'secret_fields' => ['webhook_secret' => ['type' => 'string', 'label' => 'Secret', 'required' => true]],
            'help' => 'Offline fixture', 'docs' => []];
        return ['fixture_webhook' => $base + ['transport' => 'webhook'], 'fixture_polling' => $base + ['transport' => 'polling']];
    }
    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        $this->callbackUrls[] = $request['callback_url'] ?? '';
        $raw = $request['raw_body'] ?? '';
        $expected = hash_hmac('sha256', $raw, $secrets['webhook_secret']);
        if (!hash_equals($expected, $request['headers']['x-fixture-signature'] ?? '')) {
            throw new RuntimeException('Fixture authentication failed.');
        }
        $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (($payload['tenant'] ?? '') !== $config['tenant']) {
            throw new RuntimeException('Fixture tenant mismatch.');
        }
        if ($this->duringVerify) { ($this->duringVerify)(); }
        if (isset($payload['challenge'])) {
            return ['status' => 200, 'body' => 'challenge', 'content_type' => 'text/plain', 'events' => [], 'challenge' => true];
        }
        return ['status' => 200, 'body' => '', 'content_type' => 'text/plain', 'events' => $payload['events'] ?? null];
    }
    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        $this->pollCalls[] = ['state' => $state, 'now' => $now];
        $page = array_shift($this->pages);
        if ($page instanceof Throwable) { throw $page; }
        if (is_callable($page)) { return $page($state, $now); }
        if (!is_array($page)) { throw new RuntimeException('Unexpected fixture polling.'); }
        return $page;
    }
}
class ManagerProbeNode implements NodeInterface
{
    public array $values = [];
    public function getSchema(): array { return ['type' => 'manager_probe']; }
    public function validate(array $parameters): bool { return true; }
    public function execute(array $parameters, array $context): array
    {
        $this->values[] = $parameters['value'];
        return ['value' => $parameters['value']];
    }
}
$count = 0;
$assert = function (bool $condition, string $label) use (&$count): void {
    $count++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
};
$reject = function (callable $call, string $label) use ($assert): void {
    try { $call(); } catch (Throwable $error) { $assert(true, $label); return; }
    $assert(false, $label);
};
$_ENV['APP_SECRET'] = 'manager-fixture-app-secret';
$now = time();
$secret = 'manager-signature-fixture';
$fixture = function () use (&$now, $secret): array {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE workflows (id TEXT PRIMARY KEY, structure TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
    $db->exec('CREATE TABLE credentials (id TEXT PRIMARY KEY, type TEXT NOT NULL, data TEXT NOT NULL)');
    $db->exec("CREATE TABLE queue_jobs (id TEXT PRIMARY KEY, workflow_id TEXT, payload TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, available_at TEXT, claim_token TEXT, error_message TEXT, result TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    TriggerSchema::install($db);
    $cm = new CredentialManager($db);
    $credential = $cm->encrypt(['api_key' => json_encode(['webhook_secret' => $secret])]);
    $db->prepare('INSERT INTO credentials VALUES (?, ?, ?)')->execute(['credential', 'generic_bearer', $credential]);
    $workflow = ['nodes' => [['id' => 'start', 'type' => 'customNode', 'data' => ['type' => 'service_trigger', 'isStart' => true, 'parameters' => []]], ['id' => 'action', 'type' => 'customNode', 'data' => ['type' => 'manager_probe', 'parameters' => ['value' => '{{trigger.data.value}}']]]], 'edges' => [['source' => 'start', 'target' => 'action', 'sourceHandle' => 'main_output', 'targetHandle' => 'main_input']]];
    $db->prepare('INSERT INTO workflows (id, structure) VALUES (?, ?)')->execute(['workflow', json_encode($workflow)]);
    $db->prepare('INSERT INTO workflows (id, structure) VALUES (?, ?)')->execute(['another-workflow', json_encode($workflow)]);
    $provider = new ManagerFixtureProvider();
    $registry = new TriggerRegistry([$provider]);
    $manager = new TriggerManager($db, $cm, $registry, function () use (&$now): int { return $now; });
    return [$db, $cm, $provider, $registry, $manager];
};
$input = ['workflow_id' => 'workflow', 'node_id' => 'start', 'provider_key' => 'fixture_webhook', 'credential_id' => 'credential', 'config' => ['tenant' => 'tenant-1'], 'public_url' => 'https://example.test/p8p/public', 'interval_seconds' => 300];
$event = fn (string $id, string $value = 'private-event-data'): array => ['id' => $id, 'type' => 'changed', 'data' => ['value' => $value, 'access_token' => 'private-token', 'nested' => ['password' => 'private-password', 'keep' => 1]]];
$request = function (array $events = [], string $tenant = 'tenant-1', bool $challenge = false) use ($secret): array {
    $body = ['tenant' => $tenant, 'events' => $events];
    if ($challenge) { $body['challenge'] = true; }
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    return ['method' => 'POST', 'raw_body' => $raw, 'headers' => ['x-fixture-signature' => hash_hmac('sha256', $raw, $secret)], 'query' => [], 'callback_url' => 'https://attacker.example/ignored'];
};
[$db, $cm, $provider, $registry, $manager] = $fixture();
$id = $manager->save($input);
$row = $manager->get($id);
$assert(strlen($id) === 32 && $row['enabled'] == 0 && $row['status'] === 'draft', 'save creates disabled draft');
$assert($row['callback_url'] === 'https://example.test/p8p/public/webhook.php?trigger_id=' . $id, 'canonical callback contains only receiver ID');
$assert(!str_contains($row['config_json'], $secret), 'public config excludes secrets');
$assert(count($manager->catalog()) === 2, 'catalog through provider registry');
foreach (['unknown' => 'field', 'webhook_secret' => $secret, 'access_token' => $secret] as $key => $value) {
    $reject(fn () => $manager->save(array_replace($input, ['config' => ['tenant' => 'tenant-1', $key => $value]])), 'unrecognized public config key rejected');
}
$reject(fn () => $manager->save(array_replace($input, ['config' => ['tenant' => $secret]])), 'secret content not copied to allowed config');
$reject(fn () => $manager->save(array_replace($input, ['config' => []])), 'required config');
$reject(fn () => $manager->save(array_replace($input, ['node_id' => 'action'])), 'wrong start binding rejected');
$reject(fn () => $manager->save(array_replace($input, ['workflow_id' => 'missing'])), 'missing workflow rejected');
$reject(fn () => $manager->save(array_replace($input, ['credential_id' => 'missing'])), 'missing credential rejected');
$reject(fn () => $manager->save(array_replace($input, ['provider_key' => 'unknown'])), 'unknown provider rejected');
$reject(fn () => $manager->save(array_replace($input, ['interval_seconds' => 30])), 'minimum poll interval');
$reject(fn () => $manager->save(array_replace($input, ['interval_seconds' => 86401])), 'maximum poll interval');
foreach (['http://example.test', 'https://user:password@example.test', 'https://example.test?token=secret', "https://example.test/\n"] as $url) {
    $reject(fn () => $manager->save(array_replace($input, ['public_url' => $url])), 'unsafe public URL rejected');
}
$reject(fn () => $manager->save(array_replace($input, ['id' => $id, 'workflow_id' => 'another-workflow'])), 'existing receiver cannot move between workflows');
$second = $manager->save($input);
$assert($second !== $id && count($manager->list('workflow')) === 2, 'multiple events may share same start');
$reject(fn () => $manager->receive($id, $request([$event('e0')])) , 'disabled receiver rejects');
$reject(fn () => $manager->receive(str_repeat('f', 32), $request([$event('e0')])) , 'nonexistent receiver rejects');
$manager->activate($id);
$row = $manager->get($id);
$assert($row['status'] === 'awaiting_event' && $row['enabled'] == 1, 'activate awaits external event');
$state = $row['state_ciphertext'];
$version = $row['version'];
$manager->activate($id);
$assert($manager->get($id)['state_ciphertext'] === $state && $manager->get($id)['version'] === $version, 'repeated activate preserves state and version');
$ack = $manager->receive($id, $request([], 'tenant-1', true));
$assert(end($provider->callbackUrls) === $row['callback_url'], 'receiver uses canonical callback not untrusted request URL');
$assert($ack['body'] === 'challenge' && !isset($ack['events']), 'handshake response excludes normalized events');
$assert($manager->get($id)['status'] === 'awaiting_event' && $db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 0, 'challenge cannot enqueue or mark active');
$bad = $request([$event('forged')]);
$bad['headers']['x-fixture-signature'] = 'forged';
$reject(fn () => $manager->receive($id, $bad), 'forged signature rejected');
$reject(fn () => $manager->receive($id, $request([$event('tenant')], 'other-tenant')), 'wrong tenant rejected');
$reject(fn () => $manager->receive($id, $request([['id' => 'bad', 'type' => 'changed', 'data' => 'not-array']])), 'bad event shape rejects');
$assert($db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 0, 'invalid webhook leaves no queue work');
$manager->receive($id, $request([$event('e1'), $event('e1')]));
$assert($db->query('SELECT COUNT(*) FROM trigger_events')->fetchColumn() == 1 && $db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 1, 'duplicates inside delivery create one job');
$manager->receive($id, $request([$event('e1')]));
$assert($db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 1, 'redelivery dedup durable');
$manager->receive($id, $request([$event('e3'), $event('e2')]));
$assert($db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 3, 'out of order distinct IDs admitted');
$manager->activate($second);
$manager->receive($second, $request([$event('e1')]));
$assert($db->query('SELECT COUNT(*) FROM trigger_events')->fetchColumn() == 4, 'dedup isolated per receiver');
$assert($manager->get($id)['status'] === 'active' && (int)$manager->get($id)['last_received_at'] === $now, 'verified events mark receipt timestamp');
$stored = $db->query('SELECT * FROM trigger_events ORDER BY received_at,id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$assert(!str_contains($stored['payload_ciphertext'], 'private-event-data'), 'event body encrypted at rest');
$plain = $cm->decrypt($stored['payload_ciphertext']);
$assert($plain['event']['data']['value'] === 'private-event-data' && $plain['workflow']['nodes'][0]['type'] === 'service_trigger', 'encrypted event contains snapshot and normalized event');
$assert(!isset($plain['event']['data']['access_token'], $plain['event']['data']['nested']['password']) && $plain['event']['data']['nested']['keep'] === 1, 'recursive sensitive keys removed');
$assert($plain['event']['trigger_version'] >= 2 && isset($plain['event']['trigger_id'], $plain['event']['received_at']), 'envelope carries config version and identity');
$queued = $db->query('SELECT payload FROM queue_jobs')->fetchAll(PDO::FETCH_COLUMN);
foreach ($queued as $payload) { $assert(array_keys(json_decode($payload, true)) === ['trigger_event_id'] && !str_contains($payload, 'private-event-data'), 'queue only references encrypted event'); }
$listed = json_encode($manager->list('workflow'));
$assert(!str_contains($listed, $secret) && !str_contains($listed, 'private-event-data') && !str_contains($listed, 'state_ciphertext') && !str_contains($listed, 'lease_token'), 'admin list excludes sensitive storage');
$beforeJobs = (int)$db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn();
$reject(fn () => $manager->receive($id, $request([$event('rollback-valid'), ['id' => 'rollback-bad', 'type' => 'changed', 'data' => null]])), 'later malformed event rejects whole batch');
$assert((int)$db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() === $beforeJobs, 'batch failure rolls back queued first event');
$assert($db->query("SELECT COUNT(*) FROM trigger_events WHERE event_key='" . hash('sha256', "changed\0rollback-valid") . "'")->fetchColumn() == 0, 'batch failure rolls back event identity');
$provider->duringVerify = function () use ($manager, $id): void { $manager->pause($id); };
$reject(fn () => $manager->receive($id, $request([$event('stale')])), 'configuration change during verification blocks stale commit');
$assert((int)$db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() === $beforeJobs && $manager->get($id)['status'] === 'paused', 'stale webhook leaves pause and queue intact');
$provider->duringVerify = null;
$manager->activate($id);
$changed = $manager->save(array_replace($input, ['id' => $id]));
$assert($changed === $id && $manager->get($id)['enabled'] == 0 && $manager->get($id)['state_ciphertext'] === null, 'reconfiguration pauses and clears cursor');
$manager->workflowState('workflow', false);
$assert($db->query("SELECT is_active FROM workflows WHERE id='workflow'")->fetchColumn() == 0 && $manager->get($second)['enabled'] == 0, 'workflow disable pauses all receivers atomically');
$reject(fn () => $manager->activate($id), 'inactive workflow cannot activate');
$manager->workflowState('workflow', true);
$assert($manager->get($id)['enabled'] == 0 && $manager->get($second)['enabled'] == 0, 'workflow enable does not silently reenable receivers');
$manager->delete($second);
$reject(fn () => $manager->get($second), 'delete removes receiver');
$assert($db->query('SELECT COUNT(*) FROM trigger_events')->fetchColumn() == 4 && $db->query('SELECT COUNT(*) FROM credentials')->fetchColumn() == 1, 'delete retains identities and credentials');
// New environment: polling baseline, pages and encrypted state are coupled to durable admission.
[$db, $cm, $provider, $registry, $manager] = $fixture();
$pollInput = array_replace($input, ['provider_key' => 'fixture_polling']);
$id = $manager->save($pollInput);
$manager->activate($id);
$assert($manager->get($id)['status'] === 'awaiting_baseline', 'poll activation not prematurely active');
$provider->pages[] = ['events' => [], 'state' => ['cursor' => 'page0', 'baseline' => true], 'has_more' => false];
$r = $manager->pollDue();
$assert($r['polled'] === 1 && $r['enqueued'] === 0, 'baseline does not emit historical events');
$assert(isset($provider->pollCalls[0]['state']['activated_at']), 'baseline receives activation timestamp');
$state = $cm->decrypt($manager->get($id)['state_ciphertext']);
$assert($state['cursor'] === 'page0' && $manager->get($id)['status'] === 'active', 'baseline cursor encrypted and active');
$version = $manager->get($id)['version'];
$manager->activate($id);
$assert($cm->decrypt($manager->get($id)['state_ciphertext']) === $state && $manager->get($id)['version'] === $version, 'repeated polling activation does not reset baseline');
$assert($manager->pollDue()['polled'] === 0, 'interval not due skipped');
$now += 300;
$provider->pages[] = ['events' => [$event('p1')], 'state' => ['cursor' => 'page1'], 'has_more' => true];
$r = $manager->pollDue();
$assert($r['enqueued'] === 1 && $cm->decrypt($manager->get($id)['state_ciphertext'])['cursor'] === 'page1', 'page cursor commits with queue work');
$assert((int)$manager->get($id)['next_poll_at'] === $now + 60, 'bounded page continuation scheduled');
$now += 60;
$provider->pages[] = ['events' => [$event('p1'), $event('p2')], 'state' => ['cursor' => 'page2'], 'has_more' => false];
$r = $manager->pollDue();
$assert($r['enqueued'] === 1 && (int)$db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() === 2, 'overlap page dedup keeps new event');
$assert($provider->pollCalls[2]['state']['cursor'] === 'page1', 'next turn receives persisted cursor');
$now += 300;
$oldCipher = $manager->get($id)['state_ciphertext'];
$provider->pages[] = ['events' => [$event('rollback-poll'), ['id' => 'bad', 'type' => 'changed', 'data' => null]], 'state' => ['cursor' => 'lost-page'], 'has_more' => false];
$r = $manager->pollDue();
$assert($r['failed'] === 1 && (int)$db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() === 2, 'bad page rolls back admission');
$assert($manager->get($id)['state_ciphertext'] === $oldCipher && $manager->get($id)['enabled'] == 0, 'bad page keeps cursor and disables receiver');
$assert(!str_contains($manager->get($id)['last_error'], 'private-event-data'), 'poll error is safe');
$manager->activate($id);
$provider->pages[] = new TriggerRetryException(120);
$r = $manager->pollDue();
$assert($r['deferred'] === 1 && $manager->get($id)['enabled'] == 1 && $manager->get($id)['status'] === 'retry_wait', '429 preserves active receiver and schedules read retry');
$assert((int)$manager->get($id)['next_poll_at'] === $now + 120, 'Retry-After respected');
$oldCipher = $manager->get($id)['state_ciphertext'];
$assert($manager->pollDue()['deferred'] === 0, 'retry wait does not busy poll');
$now += 120;
$provider->pages[] = ['events' => [], 'state' => ['cursor' => 'after-retry'], 'has_more' => false];
$assert($manager->pollDue()['polled'] === 1 && $provider->pollCalls[count($provider->pollCalls) - 1]['state'] === $cm->decrypt($oldCipher), 'retry reads unchanged cursor');
$now += 300;
$db->prepare('UPDATE trigger_configs SET lease_token=?, lease_until=? WHERE id=?')->execute(['other-worker', $now + 120, $id]);
$calls = count($provider->pollCalls);
$assert($manager->pollDue()['polled'] === 0 && count($provider->pollCalls) === $calls, 'active lease prevents concurrent provider read');
$now += 121;
$provider->pages[] = ['events' => [$event('recovered')], 'state' => ['cursor' => 'recovered'], 'has_more' => false];
$restart = new TriggerManager($db, new CredentialManager($db), $registry, function () use (&$now): int { return $now; });
$assert($restart->pollDue()['enqueued'] === 1 && $manager->get($id)['lease_token'] === null, 'expired lease recovered after restart');
$now += 300;
$provider->pages[] = function (array $state) use ($manager, $id, $event): array {
    $manager->pause($id);
    return ['events' => [$event('stale-poll')], 'state' => ['cursor' => 'should-not-commit'], 'has_more' => false];
};
$jobs = (int)$db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn();
$r = $manager->pollDue();
$assert($r['failed'] === 1 && (int)$db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() === $jobs, 'pause during polling blocks stale page');
$assert($manager->get($id)['status'] === 'paused' && $cm->decrypt($manager->get($id)['state_ciphertext'])['cursor'] === 'recovered', 'stale poll cannot overwrite paused state');
// Provider callback represents another cron runner while this lease is held.
$manager->activate($id);
$provider->pages[] = function (array $state) use ($manager, $assert): array {
    $assert($manager->pollDue()['polled'] === 0, 'reentrant worker cannot acquire active lease');
    return ['events' => [], 'state' => ['cursor' => 'exclusive'], 'has_more' => false];
};
$assert($manager->pollDue()['polled'] === 1, 'original leased worker completes');
$reject(fn () => $manager->receive($id, $request()), 'webhook cannot invoke polling receiver');
// End to end: receive -> encrypted storage -> claimed queue -> real engine -> action.
[$db, $cm, $provider, $registry, $manager] = $fixture();
$id = $manager->save($input);
$manager->activate($id);
$manager->receive($id, $request([$event('end-to-end', 'expected-expression-value')]));
$engine = new Engine();
$probe = new ManagerProbeNode();
$engine->registerNode(new ServiceTriggerNode());
$engine->registerNode($probe);
$worker = new WorkflowWorker($db, new QueueManager($db), $cm, $engine);
$assert($worker->run()['completed'] === 1 && $probe->values === ['expected-expression-value'], 'real manager to queue to engine expression');
$manager->receive($id, $request([$event('paused-queued')]));
$manager->pause($id);
$assert($worker->run()['cancelled'] === 1 && count($probe->values) === 1, 'queued event cancelled after pause without action');
$manager->activate($id);
$manager->receive($id, $request([$event('deleted-queued')]));
$manager->delete($id);
$assert($worker->run()['cancelled'] === 1 && count($probe->values) === 1, 'deleted receiver cancels pending job and preserves audit event');
$engine = new Engine();
$nodes = new NodeRegistry($engine);
$nodes->autoDiscover();
$types = array_column($nodes->getDiscoveredSchemas(), 'type');
$assert(in_array('service_trigger', $types, true) && in_array('woocommerce', $types, true) && count($types) === count(array_unique($types)), 'real node discovery includes trigger and existing connectors uniquely');
try { new TriggerRegistry([new ManagerFixtureProvider(), new ManagerFixtureProvider()]); $assert(false, 'duplicate provider key'); } catch (LogicException $e) { $assert(true, 'duplicate provider key rejected'); }
// Strict public configuration types must be enforced before a receiver can be activated.
[$db, $cm, $provider, $registry, $manager] = $fixture();
foreach ([false, 123, ['not' => 'string']] as $wrongType) {
    $reject(fn () => $manager->save(array_replace($input, ['config' => ['tenant' => $wrongType]])), 'declared string config type enforced');
}
$id = $manager->save($pollInput);
$manager->activate($id);
$oldCipher = $manager->get($id)['state_ciphertext'];
$provider->pages[] = ['state' => ['cursor' => 'missing-events'], 'has_more' => false];
$r = $manager->pollDue();
$assert($r['failed'] === 1 && $manager->get($id)['state_ciphertext'] === $oldCipher, 'provider page missing events cannot silently advance cursor');
// Credential changes are checked again on reception, not only when configuration is saved.
[$db, $cm, $provider, $registry, $manager] = $fixture();
$id = $manager->save($input);
$manager->activate($id);
$db->prepare('UPDATE credentials SET data=? WHERE id=?')->execute([$cm->encrypt(['api_key' => '{}']), 'credential']);
$reject(fn () => $manager->receive($id, $request([$event('bad-credential')])), 'missing secret after credential edit rejects reception');
$assert($db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 0, 'bad credential cannot enqueue');
// A real transactional write failure must not advance the cursor or retain a partial event.
[$db, $cm, $provider, $registry, $manager] = $fixture();
$id = $manager->save($pollInput);
$manager->activate($id);
$oldCipher = $manager->get($id)['state_ciphertext'];
$db->exec("CREATE TRIGGER queue_failure BEFORE INSERT ON queue_jobs BEGIN SELECT RAISE(ABORT, 'simulated storage failure'); END");
$provider->pages[] = ['events' => [$event('db-failure')], 'state' => ['cursor' => 'must-not-advance'], 'has_more' => false];
$r = $manager->pollDue();
$assert($r['failed'] === 1 && $manager->get($id)['state_ciphertext'] === $oldCipher, 'queue write failure rolls back polling cursor');
$assert($db->query('SELECT COUNT(*) FROM trigger_events')->fetchColumn() == 0 && $db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 0, 'queue write failure leaves no event identity or job');
$assert(!str_contains($manager->get($id)['last_error'], 'simulated storage'), 'database exception not exposed');
// A reclaimed lease invalidates the old holder even if the config version stayed the same.
[$db, $cm, $provider, $registry, $manager] = $fixture();
$id = $manager->save($pollInput);
$manager->activate($id);
$oldCipher = $manager->get($id)['state_ciphertext'];
$provider->pages[] = function (array $state) use ($db, $id, $event, &$now): array {
    $db->prepare('UPDATE trigger_configs SET lease_token=?, lease_until=? WHERE id=?')->execute(['replacement-worker-lease', $now + 120, $id]);
    return ['events' => [$event('lost-lease')], 'state' => ['cursor' => 'lost-lease'], 'has_more' => false];
};
$r = $manager->pollDue();
$assert($r['failed'] === 1 && $db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 0, 'lost lease prevents stale holder enqueue');
$assert($manager->get($id)['state_ciphertext'] === $oldCipher && $manager->get($id)['lease_token'] === 'replacement-worker-lease', 'old worker cannot overwrite cursor or newer lease');
// Notion's official unsigned bootstrap is captured privately while the receiver is disabled.
[$db, $cm, $provider, $registry, $manager] = $fixture();
$notionRegistry = new TriggerRegistry([new \P8P\Core\TriggerProviders\NotionWebhooks()]);
$manager = new TriggerManager($db, $cm, $notionRegistry, function () use (&$now): int { return $now; });
$token = 'manager_NOTION_fixture_verification_token';
$notionConfig = ['workspace_id' => '11111111-1111-1111-1111-111111111111', 'integration_id' => '22222222-2222-2222-2222-222222222222', 'subscription_id' => '33333333-3333-3333-3333-333333333333'];
$notionInput = array_replace($input, ['provider_key' => 'notion_webhook_page_created', 'credential_id' => '', 'config' => $notionConfig]);
$id = $manager->save($notionInput);
$assert($manager->get($id)['enabled'] == 0 && $manager->get($id)['credential_id'] === '', 'Notion draft can precede signing credential');
$reject(fn () => $manager->activate($id), 'Notion draft cannot activate without credential');
$setup = ['method' => 'POST', 'headers' => [], 'query' => [], 'raw_body' => json_encode(['verification_token' => $token])];
$ack = $manager->receive($id, $setup);
$assert(($ack['challenge'] ?? false) === true && $ack['body'] === '' && !isset($ack['setup_secret'], $ack['events']), 'Notion public bootstrap ACK excludes token and event');
$row = $manager->get($id);
$assert($row['enabled'] == 0 && $row['status'] === 'awaiting_verification', 'Notion bootstrap remains disabled awaiting verification');
$assert(!str_contains($row['state_ciphertext'], $token) && $cm->decrypt($row['state_ciphertext'])['setup_secret']['verification_token'] === $token, 'Notion candidate encrypted at rest');
$assert($db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 0 && $db->query('SELECT COUNT(*) FROM trigger_events')->fetchColumn() == 0, 'Notion setup cannot dispatch workflow');
$assert($manager->setupSecret($id) === ['verification_token' => $token], 'explicit admin setup operation retrieves candidate');
$listed = $manager->list('workflow');
$assert($listed[0]['setup_available'] === true && !str_contains(json_encode($listed), $token) && !str_contains(json_encode($listed), 'state_ciphertext'), 'Notion list only advertises private setup availability');
$manager->receive($id, $setup);
$assert($manager->setupSecret($id)['verification_token'] === $token, 'same Notion candidate retry idempotent');
$otherSetup = $setup;
$otherSetup['raw_body'] = json_encode(['verification_token' => 'attacker_different_candidate_token']);
$reject(fn () => $manager->receive($id, $otherSetup), 'different unsigned candidate cannot replace first');
$assert($manager->setupSecret($id)['verification_token'] === $token, 'rejected candidate leaves original encrypted token');
$notionEvent = [
    'id' => '44444444-4444-4444-4444-444444444444', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $now),
    'workspace_id' => $notionConfig['workspace_id'], 'integration_id' => $notionConfig['integration_id'], 'subscription_id' => $notionConfig['subscription_id'],
    'type' => 'page.created', 'attempt_number' => 1, 'authors' => [], 'data' => new stdClass(),
    'entity' => ['id' => '55555555-5555-5555-5555-555555555555', 'type' => 'page'],
];
$raw = json_encode($notionEvent, JSON_THROW_ON_ERROR);
$signedNotion = ['method' => 'POST', 'headers' => ['x-notion-signature' => 'sha256=' . hash_hmac('sha256', $raw, $token)], 'query' => [], 'raw_body' => $raw];
$reject(fn () => $manager->receive($id, $signedNotion), 'signed business event still cannot dispatch disabled Notion draft');
$assert($db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 0, 'signed draft request leaves queue empty');
$db->prepare('INSERT INTO credentials VALUES (?, ?, ?)')->execute(['notion-credential', 'generic_bearer', $cm->encrypt(['api_key' => json_encode(['verification_token' => $token])])]);
$manager->save(array_replace($notionInput, ['id' => $id, 'credential_id' => 'notion-credential']));
$assert($manager->get($id)['state_ciphertext'] === null && $manager->list('workflow')[0]['setup_available'] === false, 'saving Notion signing credential clears setup candidate');
$reject(fn () => $manager->receive($id, $signedNotion), 'credential alone cannot activate Notion');
$manager->receive($id, $setup);
$manager->activate($id);
$assert($manager->get($id)['status'] === 'awaiting_event' && !isset($cm->decrypt($manager->get($id)['state_ciphertext'])['setup_secret']), 'Notion activation replaces bootstrap state');
$reject(fn () => $manager->setupSecret($id), 'setup token no longer retrievable after activation');
$reject(fn () => $manager->receive($id, $setup), 'unsigned bootstrap prohibited once active');
$manager->receive($id, $signedNotion);
$assert($db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 1 && $manager->get($id)['status'] === 'active', 'properly configured active Notion signed event queues once');
$manager->receive($id, $signedNotion);
$assert($db->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() == 1, 'Notion stable native ID deduplicated end to end');
echo "PASS trigger manager: {$count} assertions; real PDO SQLite/crypto/queue/engine, simulated provider and clock, no network.\n";
