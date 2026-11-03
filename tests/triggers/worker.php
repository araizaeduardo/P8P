<?php

require __DIR__ . '/../../vendor/autoload.php';

use P8P\Core\CredentialManager;
use P8P\Core\Engine;
use P8P\Core\QueueManager;
use P8P\Core\TriggerSchema;
use P8P\Core\WorkflowRuntime;
use P8P\Core\WorkflowWorker;
use P8P\Interfaces\NodeInterface;
use P8P\Nodes\Core\ChatTriggerNode;
use P8P\Nodes\Core\DelayNode;
use P8P\Nodes\Core\ServiceTriggerNode;
use P8P\Nodes\Core\StartNode;

class WorkerProbeNode implements NodeInterface
{
    public array $calls = [];
    public function getSchema(): array { return ['type' => 'worker_probe']; }
    public function validate(array $parameters): bool { return true; }
    public function execute(array $parameters, array $context): array
    {
        $this->calls[] = ['parameters' => $parameters, 'context' => $context];
        if (($parameters['fail'] ?? false) === true) {
            throw new RuntimeException('private-event-secret remote-token');
        }
        return ['success' => true, 'value' => $parameters['value'] ?? null];
    }
}
class WorkerLegacyFailureEngine extends Engine
{
    public function executeWorkflow(array $workflowData, array $initialContext = []): array
    {
        return ['status' => 'completed', 'history' => ['n' => ['node_id' => 'n', 'type' => 'test', 'status' => 'failed', 'error' => 'private-event-secret']]];
    }
}
$count = 0;
$assert = function (bool $value, string $label) use (&$count): void {
    $count++;
    if (!$value) { throw new RuntimeException('FAIL: ' . $label); }
};
$_ENV['APP_SECRET'] = 'worker-fixture-secret';
$clock = time();
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE workflows (id TEXT PRIMARY KEY, structure TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
$db->exec("CREATE TABLE queue_jobs (id TEXT PRIMARY KEY, workflow_id TEXT, payload TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, available_at TEXT, claim_token TEXT, error_message TEXT, result TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
TriggerSchema::install($db);
$cm = new CredentialManager($db);
$queue = new QueueManager($db);
$engine = new Engine(function () use (&$clock): int { return $clock; });
$probe = new WorkerProbeNode();
foreach ([new StartNode(), new ChatTriggerNode(), new ServiceTriggerNode(), new DelayNode(), new \P8P\Nodes\Core\LoopNode(), new \P8P\Nodes\Core\SplitInBatchesNode(), new \P8P\Nodes\Core\MergeNode(), $probe] as $node) {
    $engine->registerNode($node);
}
$worker = new WorkflowWorker($db, $queue, $cm, $engine);
$node = fn (string $id, string $type, array $parameters = [], bool $start = false): array => ['id' => $id, 'type' => 'customNode', 'data' => ['type' => $type, 'isStart' => $start, 'parameters' => $parameters]];
$edge = fn (string $source, string $target): array => ['source' => $source, 'target' => $target, 'sourceHandle' => 'main_output', 'targetHandle' => 'main_input'];
$base = ['nodes' => [$node('trigger_node', 'service_trigger', [], true), $node('action', 'worker_probe', ['value' => '{{trigger.data.value}}', 'from_start' => '{{trigger_node.data.value}}', 'original' => '{{webhook.payload.value}}'])], 'edges' => [$edge('trigger_node', 'action')]];
$make = function (array $workflow, int $enabled = 1, int $active = 1, int $eventVersion = 1) use ($db, $queue, $cm): array {
    $workflowId = bin2hex(random_bytes(16));
    $configId = bin2hex(random_bytes(16));
    $eventId = bin2hex(random_bytes(16));
    $stmt = $db->prepare('INSERT INTO workflows (id, structure, is_active) VALUES (?, ?, ?)');
    $stmt->execute([$workflowId, json_encode($workflow), $active]);
    $stmt = $db->prepare('INSERT INTO trigger_configs (id, workflow_id, node_id, provider_key, config_json, enabled, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$configId, $workflowId, 'trigger_node', 'github_push', '{}', $enabled, 'awaiting_event']);
    $jobId = $queue->enqueue($workflowId, ['trigger_event_id' => $eventId]);
    $event = ['id' => 'remote1', 'type' => 'push', 'data' => ['value' => 'private-event-secret'], 'received_at' => time(), 'trigger_id' => $configId, 'trigger_version' => $eventVersion];
    $ciphertext = $cm->encrypt(['workflow' => WorkflowRuntime::normalize($workflow), 'event' => $event]);
    $stmt = $db->prepare('INSERT INTO trigger_events (id, trigger_id, event_key, job_id, payload_ciphertext, received_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$eventId, $configId, hash('sha256', 'remote1'), $jobId, $ciphertext, time()]);
    return [$jobId, $configId, $eventId, $workflowId];
};
$eventStatus = function (string $eventId) use ($db): string {
    $stmt = $db->prepare('SELECT status FROM trigger_events WHERE id=?');
    $stmt->execute([$eventId]);
    return $stmt->fetchColumn();
};
[$job, $config, $event] = $make($base);
$raw = $db->query("SELECT payload FROM queue_jobs WHERE id='{$job}'")->fetchColumn();
$assert(!str_contains($raw, 'private-event-secret') && !str_contains($raw, 'nodes'), 'queued trigger only event reference');
$r = $worker->run();
$assert($r['completed'] === 1 && count($probe->calls) === 1, 'trigger executes downstream');
$call = $probe->calls[0];
$assert($call['parameters']['value'] === 'private-event-secret' && $call['parameters']['from_start'] === 'private-event-secret' && $call['parameters']['original'] === 'private-event-secret', 'expressions initial event and start and webhook alias');
$status = $queue->getJobStatus($job);
$assert($status['status'] === 'completed' && $eventStatus($event) === 'completed', 'durable event and job complete');
$assert(!str_contains(json_encode($status), 'private-event-secret') && !isset($status['result']['final_context']), 'trigger history summary excludes sensitive outputs');
$assert(array_keys($status['result']['history'][0]) === ['node_id', 'type', 'status', 'duration'], 'strict public history fields');
$assert($worker->run()['claimed'] === 0 && count($probe->calls) === 1, 'completed action never replayed');
foreach ([[0, 1, 1], [1, 0, 1], [1, 1, 99]] as [$enabled, $active, $version]) {
    [$job, $config, $event] = $make($base, $enabled, $active, $version);
    $before = count($probe->calls);
    $r = $worker->run();
    $assert($r['cancelled'] === 1 && count($probe->calls) === $before, 'disabled inactive or obsolete version cancels before effect');
    $assert($eventStatus($event) === 'cancelled', 'cancelled event explicit status');
}
[$job, $config, $event, $workflowId] = $make($base);
$db->prepare('DELETE FROM workflows WHERE id=?')->execute([$workflowId]);
$assert($worker->run()['cancelled'] === 1, 'deleted workflow cancelled');
$assert($eventStatus($event) === 'cancelled', 'deleted workflow event status');
$fail = $base;
$fail['nodes'][1]['data']['parameters']['fail'] = true;
[$job, $config, $event] = $make($fail);
$assert($worker->run()['failed'] === 1 && $eventStatus($event) === 'failed', 'node exception marks both failed');
$assert(!str_contains(json_encode($queue->getJobStatus($job)), 'private-event-secret'), 'failed trigger exception text stripped');
$assert($worker->run()['claimed'] === 0, 'no automatic failed action retry');
[$job, $config, $event] = $make($base);
$legacyWorker = new WorkflowWorker($db, $queue, $cm, new WorkerLegacyFailureEngine());
$assert($legacyWorker->run()['failed'] === 1 && $eventStatus($event) === 'failed', 'completed upper status with failed history still fails');
$delay = ['nodes' => [$node('trigger_node', 'service_trigger', [], true), $node('before', 'worker_probe', ['value' => 'before']), $node('wait', 'delay', ['amount' => '1', 'unit' => 'seconds']), $node('after', 'worker_probe', ['value' => '{{trigger_node.data.value}}'])], 'edges' => [$edge('trigger_node', 'before'), $edge('before', 'wait'), $edge('wait', 'after')]];
[$job, $config, $event] = $make($delay);
$before = count($probe->calls);
$assert($worker->run()['suspended'] === 1 && count($probe->calls) === $before + 1, 'Delay suspends after preceding action');
$assert($eventStatus($event) === 'suspended' && $queue->getJobStatus($job)['status'] === 'pending', 'suspended event pending queue');
$raw = $db->query("SELECT payload FROM queue_jobs WHERE id='{$job}'")->fetchColumn();
$decoded = json_decode($raw, true);
$assert(isset($decoded['trigger_event_id'], $decoded['trigger_continuation']) && !str_contains($raw, 'private-event-secret') && !str_contains($raw, 'checkpoint'), 'continuation encrypted at rest');
$assert(!str_contains(json_encode($queue->getJobStatus($job)), 'private-event-secret'), 'suspended result summary safe');
$assert($worker->run()['claimed'] === 0, 'future available_at not claimed');
$clock += 2;
$db->prepare('UPDATE queue_jobs SET available_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $job]);
$restarted = new WorkflowWorker($db, new QueueManager($db), new CredentialManager($db), $engine);
$assert($restarted->run()['completed'] === 1 && count($probe->calls) === $before + 2, 'restart resumes only remaining action');
$assert(end($probe->calls)['parameters']['value'] === 'private-event-secret', 'resume retains event context');
[$job, $config, $event] = $make($delay);
$assert($worker->run()['suspended'] === 1, 'second Delay suspension');
$db->prepare('UPDATE trigger_configs SET enabled=0, version=version+1 WHERE id=?')->execute([$config]);
$db->prepare('UPDATE queue_jobs SET available_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $job]);
$before = count($probe->calls);
$assert($restarted->run()['cancelled'] === 1 && count($probe->calls) === $before, 'pause cancels continuation before more effects');
// Legacy chat job preserves public session association and full result contract.
$chat = ['nodes' => [$node('chat', 'chat_trigger', [], true), $node('reply', 'worker_probe', ['value' => '{{chat.user_message}}'])], 'edges' => [$edge('chat', 'reply')]];
$workflowId = 'chat-workflow';
$db->prepare('INSERT INTO workflows (id, structure) VALUES (?, ?)')->execute([$workflowId, json_encode($chat)]);
$job = $queue->enqueue($workflowId, ['user_message' => 'hello legacy chat', 'session_id' => 'session-test']);
$assert($worker->run()['completed'] === 1, 'legacy chat completes');
$assert(end($probe->calls)['parameters']['value'] === 'hello legacy chat', 'legacy chat parameter injection');
$assert($queue->getJobStatus($job)['result']['final_context']['chat']['session_id'] === 'session-test', 'chat session preserved');
$manual = ['nodes' => [$node('start', 'start', [], true), $node('step', 'worker_probe', ['value' => 'manual'])], 'edges' => [$edge('start', 'step')]];
$job = $queue->enqueue(null, $manual);
$assert($worker->run()['completed'] === 1 && end($probe->calls)['parameters']['value'] === 'manual', 'legacy manual snapshot completes');
// Exclusive claim and stale-token rejection do not repeat actions.
$job = $queue->enqueue(null, $manual);
$claims = $queue->getPendingJobs(1);
$assert(count($claims) === 1 && $claims[0]['id'] === $job, 'exclusive claim obtained');
$assert((new QueueManager($db))->getPendingJobs(1) === [], 'second worker cannot claim processing');
try { $queue->markCompleted($job, [], 'wrong-token'); $assert(false, 'wrong token'); } catch (RuntimeException $e) { $assert(true, 'stale claim cannot finish'); }
$queue->markCompleted($job, ['status' => 'completed'], $claims[0]['claim_token']);
$assert((int) $queue->getJobStatus($job)['attempts'] === 1, 'single claim attempt counted');
// Corrupted encrypted events fail safely without leaking their ciphertext or attempting effects.
[$job, $config, $event] = $make($base);
$db->prepare('UPDATE trigger_events SET payload_ciphertext=? WHERE id=?')->execute(['invalid-ciphertext', $event]);
$before = count($probe->calls);
$assert($worker->run()['failed'] === 1 && count($probe->calls) === $before, 'corrupt event rejected before execution');
$assert($eventStatus($event) === 'failed', 'corrupt event failure persisted');
$assert(!str_contains(json_encode($queue->getJobStatus($job)), 'invalid-ciphertext'), 'ciphertext absent from errors');
// Legacy Delay continuation preserves the chat session and only resumes unfinished work.
$legacyDelay = ['nodes' => [$node('chat', 'chat_trigger', [], true), $node('wait', 'delay', ['amount' => '1']), $node('after_chat', 'worker_probe', ['value' => '{{chat.user_message}}'])], 'edges' => [$edge('chat', 'wait'), $edge('wait', 'after_chat')]];
$job = $queue->enqueue(null, $legacyDelay + ['user_message' => 'chat-resume', 'session_id' => 'same-session']);
$assert($worker->run()['suspended'] === 1, 'legacy chat Delay suspension');
$raw = json_decode($db->query("SELECT payload FROM queue_jobs WHERE id='{$job}'")->fetchColumn(), true);
$assert($raw['session_id'] === 'same-session' && isset($raw['continuation']['checkpoint']), 'legacy continuation session association');
$clock += 2;
$db->prepare('UPDATE queue_jobs SET available_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $job]);
$assert($restarted->run()['completed'] === 1 && end($probe->calls)['parameters']['value'] === 'chat-resume', 'legacy chat resumes retained inputs');
// Loop and SplitInBatches use the existing block scheduler through the same worker.
foreach (['loop', 'split_in_batches'] as $loopType) {
    $params = $loopType === 'loop' ? ['mode' => 'count', 'count' => '2'] : ['collection' => '[1,2,3]', 'batch_size' => '2'];
    $loopFlow = ['nodes' => [$node('trigger_node', 'service_trigger', [], true), $node('loop', $loopType, $params), $node('body', 'worker_probe', ['value' => '{{trigger.data.value}}']), $node('done', 'worker_probe', ['value' => 'finished'])], 'edges' => [$edge('trigger_node', 'loop'), array_replace($edge('loop', 'body'), ['sourceHandle' => 'body']), array_replace($edge('loop', 'done'), ['sourceHandle' => 'done'])]];
    [$job, $config, $event] = $make($loopFlow);
    $before = count($probe->calls);
    $assert($worker->run()['completed'] === 1 && count($probe->calls) === $before + 3, $loopType . ' worker block compatibility');
    $assert(!str_contains(json_encode($queue->getJobStatus($job)), 'private-event-secret'), $loopType . ' nested history summary filtered');
}
$mergeFlow = ['nodes' => [$node('trigger_node', 'service_trigger', [], true), $node('a', 'worker_probe', ['value' => [1]]), $node('b', 'worker_probe', ['value' => [2]]), $node('merge', 'merge', ['mode' => 'concat', 'input_field' => 'value']), $node('merged', 'worker_probe', ['value' => '{{merge.data}}'])], 'edges' => [$edge('trigger_node', 'a'), $edge('trigger_node', 'b'), $edge('a', 'merge'), $edge('b', 'merge'), $edge('merge', 'merged')]];
[$job, $config, $event] = $make($mergeFlow);
$assert($worker->run()['completed'] === 1 && end($probe->calls)['parameters']['value'] === [1, 2], 'parallel branches and Merge preserved');
// Delay inside a Loop retains the block checkpoint without repeating a prior iteration action.
$loopDelay = ['nodes' => [$node('trigger_node', 'service_trigger', [], true), $node('loop', 'loop', ['mode' => 'count', 'count' => '2']), $node('body', 'worker_probe', ['value' => '{{loop.iteration}}']), $node('wait', 'delay', ['amount' => '1']), $node('done', 'worker_probe', ['value' => 'done'])], 'edges' => [$edge('trigger_node', 'loop'), array_replace($edge('loop', 'body'), ['sourceHandle' => 'body']), $edge('body', 'wait'), array_replace($edge('loop', 'done'), ['sourceHandle' => 'done'])]];
[$job, $config, $event] = $make($loopDelay);
$before = count($probe->calls);
$assert($worker->run()['suspended'] === 1 && count($probe->calls) === $before + 1, 'Loop first iteration suspends');
for ($iteration = 0; $iteration < 2; $iteration++) {
    $clock += 2;
    $db->prepare('UPDATE queue_jobs SET available_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $job]);
    $r = $restarted->run();
    $assert($r[$iteration === 0 ? 'suspended' : 'completed'] === 1, 'Loop Delay continuation stage');
}
$assert(count($probe->calls) === $before + 3, 'Loop Delay never repeats effects');
$assert($eventStatus($event) === 'completed', 'Loop Delay final event status');
echo "PASS workflow worker: {$count} assertions; SQLite memory, simulated clock, no provider/real queue effects.\n";
