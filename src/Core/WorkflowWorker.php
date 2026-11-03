<?php

namespace P8P\Core;

use PDO;
use RuntimeException;

/** Bounded cron runner. Claimed jobs are never automatically retried after a process failure. */
class WorkflowWorker
{
    public function __construct(
        private PDO $db,
        private QueueManager $queue,
        private CredentialManager $credentials,
        private Engine $engine
    ) {
    }

    public function run(int $limit = 5): array
    {
        $counts = ['claimed' => 0, 'completed' => 0, 'failed' => 0, 'suspended' => 0, 'cancelled' => 0];
        foreach ($this->queue->getPendingJobs($limit) as $job) {
            $counts['claimed']++;
            $eventId = null;
            try {
                $payload = json_decode($job['payload'], true, 2048, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new RuntimeException('Payload de cola inválido.');
                }
                if (array_key_exists('trigger_event_id', $payload)) {
                    if (!is_string($payload['trigger_event_id']) || $payload['trigger_event_id'] === '') {
                        throw new RuntimeException('Referencia de evento inválida.');
                    }
                    $eventId = $payload['trigger_event_id'];
                    $status = $this->triggerJob($job, $payload, $eventId);
                } else {
                    if (isset($payload['trigger_continuation'])) {
                        throw new RuntimeException('Continuación sin evento.');
                    }
                    $status = $this->regularJob($job, $payload);
                }
                $counts[$status]++;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                // No original exception text: providers may embed credentials or private payloads.
                $this->db->beginTransaction();
                try {
                    $this->queue->markFailed($job['id'], 'No se pudo procesar el workflow.', $job['claim_token'], ['status' => 'failed']);
                    if ($eventId !== null) {
                        $this->eventStatus($eventId, $job['id'], 'failed');
                    }
                    $this->db->commit();
                } catch (\Throwable $storageError) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    // Leave processing; manual reconciliation is required before replaying effects.
                    throw new RuntimeException('No se pudo guardar el estado del trabajo; requiere revisión manual.');
                }
                $counts['failed']++;
            }
        }
        return $counts;
    }

    private function triggerJob(array $job, array $payload, string $eventId): string
    {
        $query = $this->db->prepare('SELECT e.trigger_id, e.job_id, e.status AS event_status, e.payload_ciphertext,
            c.enabled, c.workflow_id, c.node_id, c.version, w.is_active
            FROM trigger_events e
            LEFT JOIN trigger_configs c ON c.id = e.trigger_id
            LEFT JOIN workflows w ON w.id = c.workflow_id
            WHERE e.id = :id');
        $query->execute([':id' => $eventId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['job_id'] !== $job['id']) {
            throw new RuntimeException('Evento no vinculado al trabajo.');
        }
        // Re-check before EVERY execution, including encrypted Delay continuations.
        if ((int) $row['enabled'] !== 1 || (int) $row['is_active'] !== 1) {
            $this->finishTrigger($job, $eventId, 'cancelled', ['status' => 'cancelled', 'history' => []]);
            return 'cancelled';
        }
        if (in_array($row['event_status'], ['completed', 'cancelled', 'failed'], true)) {
            // A queue reference cannot replay a finished event, even if manually duplicated.
            $this->queue->markCompleted($job['id'], ['status' => 'cancelled', 'history' => []], $job['claim_token']);
            return 'cancelled';
        }
        $stored = $this->credentials->decrypt($row['payload_ciphertext']);
        if (!is_array($stored) || !is_array($stored['workflow'] ?? null) || !is_array($stored['event'] ?? null)
            || ($stored['event']['trigger_id'] ?? null) !== $row['trigger_id'] || !is_array($stored['event']['data'] ?? null)) {
            throw new RuntimeException('Evento cifrado inválido.');
        }
        if ($row['workflow_id'] !== $job['workflow_id']) {
            throw new RuntimeException('Workflow de evento no vinculado al trabajo.');
        }
        if ((int) ($stored['event']['trigger_version'] ?? 0) !== (int) $row['version']) {
            $this->finishTrigger($job, $eventId, 'cancelled', ['status' => 'cancelled', 'history' => []]);
            return 'cancelled';
        }
        $workflow = WorkflowRuntime::normalize($stored['workflow']);
        $start = WorkflowRuntime::start($workflow);
        if ($start['id'] !== $row['node_id'] || !in_array($start['type'], ['start', 'service_trigger'], true)) {
            throw new RuntimeException('Nodo receptor incompatible.');
        }
        $checkpoint = null;
        if (isset($payload['trigger_continuation'])) {
            if (!is_string($payload['trigger_continuation'])) {
                throw new RuntimeException('Continuación cifrada inválida.');
            }
            $continuation = $this->credentials->decrypt($payload['trigger_continuation']);
            if (!is_array($continuation) || !is_array($continuation['workflow'] ?? null)
                || !is_array($continuation['checkpoint'] ?? null)
                || WorkflowRuntime::hash($continuation['workflow']) !== WorkflowRuntime::hash($workflow)) {
                throw new RuntimeException('Continuación ajena al workflow.');
            }
            $checkpoint = $continuation['checkpoint'];
        }
        $this->eventStatus($eventId, $job['id'], 'processing');
        $event = $stored['event'];
        $result = $checkpoint === null
            ? $this->engine->executeWorkflow($workflow, ['_trigger' => $event, 'trigger' => $event,
                'webhook' => ['payload' => $event['data']]])
            : $this->engine->resumeWorkflow($workflow, $checkpoint);
        $failed = $this->hasFailure($result);
        $summary = $this->summary($result);
        if (!$failed && ($result['status'] ?? '') === 'suspended') {
            if (!is_array($result['checkpoint'] ?? null) || !is_int($result['resume_at'] ?? null)) {
                throw new RuntimeException('Checkpoint suspendido inválido.');
            }
            $ciphertext = $this->credentials->encrypt(['workflow' => $workflow, 'checkpoint' => $result['checkpoint']]);
            $this->db->beginTransaction();
            try {
                $this->queue->rescheduleEncrypted($job['id'], $job['claim_token'], $eventId, $ciphertext, $result['resume_at'], $summary);
                $this->eventStatus($eventId, $job['id'], 'suspended');
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
            return 'suspended';
        }
        $status = $failed ? 'failed' : 'completed';
        $summary['status'] = $status;
        $this->finishTrigger($job, $eventId, $status, $summary);
        return $status;
    }

    private function finishTrigger(array $job, string $eventId, string $status, array $summary): void
    {
        $this->db->beginTransaction();
        try {
            if ($status === 'failed') {
                $this->queue->markFailed($job['id'], 'Falló la ejecución del workflow.', $job['claim_token'], $summary);
            } else {
                // queue_jobs existing ENUM remains compatible; cancellation is explicit in result/event.
                $this->queue->markCompleted($job['id'], $summary, $job['claim_token']);
            }
            $this->eventStatus($eventId, $job['id'], $status);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function eventStatus(string $eventId, string $jobId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE trigger_events SET status = :status WHERE id = :id AND job_id = :job_id');
        $stmt->execute([':status' => $status, ':id' => $eventId, ':job_id' => $jobId]);
    }

    private function regularJob(array $job, array $payload): string
    {
        $checkpoint = null;
        if (isset($payload['continuation'])) {
            $workflow = $payload['continuation']['workflow'] ?? null;
            $checkpoint = $payload['continuation']['checkpoint'] ?? null;
            if (!is_array($workflow) || !is_array($checkpoint)) {
                throw new RuntimeException('Continuación de cola inválida.');
            }
            // Trust persisted snapshot as before; do not reinject chat inputs when resuming.
        } else {
            $workflow = $payload;
            if (empty($payload['nodes']) && is_string($job['workflow_id'] ?? null)) {
                $stmt = $this->db->prepare('SELECT structure FROM workflows WHERE id = :id');
                $stmt->execute([':id' => $job['workflow_id']]);
                $structure = $stmt->fetchColumn();
                $workflow = is_string($structure) ? json_decode($structure, true, 512, JSON_THROW_ON_ERROR) : [];
            }
            if (!is_array($workflow) || empty($workflow['nodes'])) {
                throw new RuntimeException('No se encontró una estructura de nodos válida.');
            }
            $workflow = WorkflowRuntime::normalize($workflow);
            WorkflowRuntime::start($workflow);
            foreach ($workflow['nodes'] as &$node) {
                if ($node['data']['is_start'] || in_array($node['type'], ['chat_trigger', 'start'], true)) {
                    $node['data']['parameters']['user_message'] = $payload['user_message'] ?? $payload['message'] ?? '';
                    $node['data']['parameters']['session_id'] = $payload['session_id'] ?? ('session_' . time());
                }
            }
            unset($node);
        }
        $result = $checkpoint === null
            ? $this->engine->executeWorkflow($workflow)
            : $this->engine->resumeWorkflow($workflow, $checkpoint);
        if ($this->hasFailure($result)) {
            $this->queue->markFailed($job['id'], 'Falló la ejecución del workflow.', $job['claim_token'], $result);
            return 'failed';
        }
        if (($result['status'] ?? '') === 'suspended') {
            $this->queue->reschedule($job['id'], $job['claim_token'], $workflow, $result, $payload);
            return 'suspended';
        }
        $this->queue->markCompleted($job['id'], $result, $job['claim_token']);
        return 'completed';
    }

    private function hasFailure(array $result): bool
    {
        if (($result['status'] ?? '') === 'failed') {
            return true;
        }
        return $this->historyFailed($result['history'] ?? []);
    }

    private function historyFailed(array $history): bool
    {
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['status'] ?? '') === 'failed') {
                return true;
            }
            foreach ($entry['iterations'] ?? [] as $iteration) {
                if ($this->historyFailed($iteration['history'] ?? [])) {
                    return true;
                }
            }
        }
        return false;
    }

    private function summary(array $result): array
    {
        $history = [];
        $append = function (array $entries) use (&$append, &$history): void {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $history[] = ['node_id' => $entry['node_id'] ?? '', 'type' => $entry['type'] ?? '',
                    'status' => $entry['status'] ?? '', 'duration' => $entry['execution_time_ms'] ?? 0];
                foreach ($entry['iterations'] ?? [] as $iteration) {
                    $append($iteration['history'] ?? []);
                }
            }
        };
        $append($result['history'] ?? []);
        return ['status' => $result['status'] ?? 'failed', 'history' => $history];
    }
}
