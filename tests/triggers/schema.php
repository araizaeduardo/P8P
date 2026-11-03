<?php

require __DIR__ . '/../../vendor/autoload.php';

use P8P\Core\TriggerSchema;

$count = 0;
$assert = function (bool $value, string $label) use (&$count): void {
    $count++;
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $label);
    }
};
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE workflows (id TEXT PRIMARY KEY, structure TEXT)');
$db->exec("INSERT INTO workflows VALUES ('legacy', '{\"nodes\":[]}')");
$db->exec('CREATE TABLE credentials (id TEXT PRIMARY KEY, data TEXT)');
$db->exec("INSERT INTO credentials VALUES ('credential', 'legacy-ciphertext')");
TriggerSchema::install($db);
$expectedConfigs = ['id', 'workflow_id', 'node_id', 'provider_key', 'credential_id', 'config_json', 'callback_url', 'enabled', 'status', 'interval_seconds', 'state_ciphertext', 'version', 'next_poll_at', 'lease_token', 'lease_until', 'last_received_at', 'last_attempt_at', 'last_error', 'created_at', 'updated_at'];
$columns = $db->query('PRAGMA table_info(trigger_configs)')->fetchAll(PDO::FETCH_ASSOC);
$assert(array_column($columns, 'name') === $expectedConfigs, 'complete config columns');
$expectedEvents = ['id', 'trigger_id', 'event_key', 'job_id', 'status', 'payload_ciphertext', 'received_at'];
$columns = $db->query('PRAGMA table_info(trigger_events)')->fetchAll(PDO::FETCH_ASSOC);
$assert(array_column($columns, 'name') === $expectedEvents, 'complete event columns');
$stmt = $db->prepare('INSERT INTO trigger_configs (id, workflow_id, node_id, provider_key, credential_id, config_json, state_ciphertext) VALUES (?, ?, ?, ?, ?, ?, ?)');
$stmt->execute(['config1', 'legacy', 'start1', 'github_push', 'credential', '{}', 'state-ciphertext']);
$stmt->execute(['config2', 'legacy', 'start2', 'github_push', 'credential', '{}', 'state-ciphertext']);
$config = $db->query("SELECT * FROM trigger_configs WHERE id='config1'")->fetch(PDO::FETCH_ASSOC);
$assert((int) $config['enabled'] === 0, 'disabled by default');
$assert($config['status'] === 'draft', 'draft by default');
$assert((int) $config['interval_seconds'] === 60, 'polling default interval');
$assert((int) $config['version'] === 1, 'optimistic version default');
$assert($config['next_poll_at'] === null && $config['lease_token'] === null, 'unclaimed by default');
$insert = $db->prepare('INSERT INTO trigger_events (id, trigger_id, event_key, job_id, payload_ciphertext, received_at) VALUES (?, ?, ?, ?, ?, ?)');
$insert->execute(['event1', 'config1', str_repeat('a', 64), 'job1', 'event-ciphertext', 1800000000]);
try {
    $insert->execute(['event2', 'config1', str_repeat('a', 64), 'job2', 'duplicate', 1800000001]);
    $assert(false, 'duplicate event rejected');
} catch (PDOException $e) {
    $assert(true, 'duplicate event rejected');
}
$insert->execute(['event3', 'config2', str_repeat('a', 64), 'job3', 'other-receiver', 1800000001]);
$assert((int) $db->query('SELECT COUNT(*) FROM trigger_events')->fetchColumn() === 2, 'dedup isolated per receiver');
$db->beginTransaction();
$insert->execute(['rolledback', 'config1', str_repeat('b', 64), 'job4', 'transaction', 1800000002]);
$db->rollBack();
$assert((int) $db->query('SELECT COUNT(*) FROM trigger_events')->fetchColumn() === 2, 'transactional event rollback');
TriggerSchema::install($db);
$assert((int) $db->query('SELECT COUNT(*) FROM trigger_configs')->fetchColumn() === 2, 'repeated migration keeps configs');
$assert((int) $db->query('SELECT COUNT(*) FROM trigger_events')->fetchColumn() === 2, 'repeated migration keeps events');
$assert($db->query("SELECT structure FROM workflows WHERE id='legacy'")->fetchColumn() === '{"nodes":[]}', 'legacy workflow preserved');
$assert($db->query("SELECT data FROM credentials WHERE id='credential'")->fetchColumn() === 'legacy-ciphertext', 'legacy credentials preserved');
$indexes = $db->query('PRAGMA index_list(trigger_configs)')->fetchAll(PDO::FETCH_ASSOC);
$assert(in_array('idx_trigger_due', array_column($indexes, 'name'), true), 'poll lease lookup indexed');
$assert(in_array('idx_trigger_workflow', array_column($indexes, 'name'), true), 'workflow lookup indexed');
$assert(count(TriggerSchema::statements('mysql')) === 2, 'MySQL canonical tables');
try {
    TriggerSchema::statements('other');
    $assert(false, 'unknown dialect rejected');
} catch (RuntimeException $e) {
    $assert(true, 'unknown dialect rejected');
}
$source = file_get_contents(__DIR__ . '/../../database/migrations/20261025_triggers.php');
$assert(strpos($source, "if (!in_array('--apply'") < strpos($source, 'Database::getConnection'), 'preview guarded before connection');
$assert(str_contains($source, 'GET_LOCK') && str_contains($source, 'RELEASE_LOCK'), 'production advisory lock');
echo "PASS trigger schema: {$count} assertions, SQLite memory only; production migration not applied.\n";
