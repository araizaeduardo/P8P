<?php

namespace P8P\Core;

use PDO;
use RuntimeException;

class QueueManager
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection(require __DIR__ . '/../../config/database.php');
    }

    public function assertDelaySchema(): void
    {
        try {
            $this->db->query('SELECT available_at, claim_token FROM queue_jobs LIMIT 0');
        } catch (\Throwable $e) {
            throw new RuntimeException('La cola requiere la actualización database/migrations/20261020_delay_queue.php antes de usar Delay.');
        }
    }

    public function enqueue(?string $workflowId, array $payload, ?int $availableAt = null): string
    {
        $jobId = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("INSERT INTO queue_jobs (id, workflow_id, payload, status, available_at)
            VALUES (:id, :workflow_id, :payload, 'pending', :available_at)");
        $stmt->execute([':id' => $jobId, ':workflow_id' => $workflowId,
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR, 2048),
            ':available_at' => $availableAt === null ? null : gmdate('Y-m-d H:i:s', $availableAt)]);
        return $jobId;
    }

    public function enqueueContinuation(array $workflow, array $result, ?string $workflowId = null): string
    {
        if (($result['status'] ?? '') !== 'suspended' || !isset($result['checkpoint'], $result['resume_at'])) {
            throw new RuntimeException('No existe una continuación suspendida para encolar.');
        }
        return $this->enqueue($workflowId, ['continuation' => ['workflow' => $workflow,
            'checkpoint' => $result['checkpoint']]], $result['resume_at']);
    }

    /**
     * FOR UPDATE serializa reclamaciones concurrentes. No se reclaman trabajos processing
     * automáticamente: un proceso muerto requiere revisión manual antes de reintentar efectos.
     */
    public function getPendingJobs(int $limit = 5): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('El límite de trabajos debe estar entre 1 y 100.');
        }
        $this->db->beginTransaction();
        try {
            $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->db->prepare("SELECT id, workflow_id, payload, attempts FROM queue_jobs
                WHERE status = 'pending' AND (available_at IS NULL OR available_at <= :now)
                ORDER BY created_at ASC, id ASC LIMIT :limit" . $lock);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':now', gmdate('Y-m-d H:i:s'));
            $stmt->execute();
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $claim = $this->db->prepare("UPDATE queue_jobs SET status = 'processing', claim_token = :claim,
                attempts = attempts + 1, updated_at = :updated_at WHERE id = :id AND status = 'pending'");
            foreach ($jobs as &$job) {
                $job['claim_token'] = bin2hex(random_bytes(32));
                $claim->execute([':updated_at' => gmdate('Y-m-d H:i:s'), ':claim' => $job['claim_token'], ':id' => $job['id']]);
                if ($claim->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo reclamar el trabajo de forma exclusiva.');
                }
            }
            unset($job);
            $this->db->commit();
            return $jobs;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function markCompleted(string $jobId, array $result, string $claimToken): void
    {
        $this->finish($jobId, $claimToken, 'completed', $result);
    }

    public function markFailed(string $jobId, string $errorMessage, string $claimToken, array $result = []): void
    {
        $this->finish($jobId, $claimToken, 'failed', $result, $errorMessage);
    }

    private function finish(string $id, string $token, string $status, array $result, ?string $error = null): void
    {
        unset($result['checkpoint']);
        $stmt = $this->db->prepare("UPDATE queue_jobs SET status = :status, result = :result,
            error_message = :error, claim_token = NULL, updated_at = :updated_at
            WHERE id = :id AND status = 'processing' AND claim_token = :claim");
        $stmt->execute([':updated_at' => gmdate('Y-m-d H:i:s'), ':status' => $status, ':result' => json_encode($result, JSON_THROW_ON_ERROR, 2048),
            ':error' => $error, ':id' => $id, ':claim' => $token]);
        $this->requireClaim($stmt->rowCount());
    }

    public function reschedule(string $jobId, string $claimToken, array $workflow, array $result, array $originalPayload = []): void
    {
        if (($result['status'] ?? '') !== 'suspended' || !isset($result['checkpoint'], $result['resume_at'])) {
            throw new RuntimeException('No existe una continuación suspendida para guardar.');
        }
        $payload = ['continuation' => ['workflow' => $workflow, 'checkpoint' => $result['checkpoint']]];
        // El chat público vincula la consulta del resultado con la sesión del payload original.
        if (is_string($originalPayload['session_id'] ?? null)) {
            $payload['session_id'] = $originalPayload['session_id'];
        }
        $publicResult = $result;
        unset($publicResult['checkpoint']);
        $stmt = $this->db->prepare("UPDATE queue_jobs SET status = 'pending', payload = :payload,
            available_at = :available_at, result = :result, error_message = NULL, claim_token = NULL,
            updated_at = :updated_at WHERE id = :id AND status = 'processing' AND claim_token = :claim");
        $stmt->execute([':updated_at' => gmdate('Y-m-d H:i:s'), ':payload' => json_encode($payload, JSON_THROW_ON_ERROR, 2048),
            ':available_at' => gmdate('Y-m-d H:i:s', $result['resume_at']),
            ':result' => json_encode($publicResult, JSON_THROW_ON_ERROR, 2048), ':id' => $jobId, ':claim' => $claimToken]);
        $this->requireClaim($stmt->rowCount());
    }

    /** Trigger continuations contain only encrypted checkpoint material in queue storage. */
    public function rescheduleEncrypted(string $jobId, string $claimToken, string $eventId, string $ciphertext, int $resumeAt, array $summary): void
    {
        $stmt = $this->db->prepare("UPDATE queue_jobs SET status = 'pending', payload = :payload,
            available_at = :available_at, result = :result, error_message = NULL, claim_token = NULL,
            updated_at = :updated_at WHERE id = :id AND status = 'processing' AND claim_token = :claim");
        $stmt->execute([':payload' => json_encode(['trigger_event_id' => $eventId,
                'trigger_continuation' => $ciphertext], JSON_THROW_ON_ERROR),
            ':available_at' => gmdate('Y-m-d H:i:s', $resumeAt),
            ':result' => json_encode($summary, JSON_THROW_ON_ERROR), ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $jobId, ':claim' => $claimToken]);
        $this->requireClaim($stmt->rowCount());
    }

    private function requireClaim(int $changed): void
    {
        if ($changed !== 1) {
            throw new RuntimeException('El trabajo ya no pertenece a esta reclamación.');
        }
    }

    public function getJobStatus(string $jobId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, workflow_id, status, attempts, error_message, result,
            available_at, created_at, updated_at FROM queue_jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (!empty($row['result'])) {
            $row['result'] = json_decode($row['result'], true, 2048);
        }
        return $row;
    }
}
