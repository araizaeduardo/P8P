<?php

namespace P8P\Core;

use PDO;

/** Durable receiver configuration, polling leases and transactional event admission. */
class TriggerManager
{
    private TriggerRegistry $registry;
    private \Closure $clock;

    public function __construct(private PDO $db, private CredentialManager $credentials, ?TriggerRegistry $registry = null, ?callable $clock = null)
    {
        $this->registry = $registry ?? new TriggerRegistry();
        $this->clock = \Closure::fromCallable($clock ?? 'time');
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public function get(string $id): array
    {
        $row = $this->query('SELECT * FROM trigger_configs WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        return $row ?: throw new \InvalidArgumentException('Configuración receptora inexistente.');
    }

    private function workflow(string $id, bool $active = false): array
    {
        $row = $this->query('SELECT structure, is_active FROM workflows WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($active && !(bool)$row['is_active'])) {
            throw new \InvalidArgumentException('Workflow inexistente o desactivado.');
        }
        return json_decode($row['structure'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function boundWorkflow(array $row): array
    {
        $workflow = WorkflowRuntime::normalize($this->workflow($row['workflow_id'], true));
        $start = WorkflowRuntime::start($workflow);
        if ($start['id'] !== $row['node_id'] || !in_array($start['type'], ['start', 'service_trigger'], true)) {
            throw new \InvalidArgumentException('El inicio guardado no coincide con el receptor.');
        }
        return $workflow;
    }

    public function list(string $workflowId): array
    {
        $this->workflow($workflowId);
        $rows = $this->query('SELECT * FROM trigger_configs WHERE workflow_id = ? ORDER BY created_at, id', [$workflowId])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['setup_available'] = false;
            if (str_starts_with($row['provider_key'], 'notion_webhook_') && !$row['enabled'] && $row['state_ciphertext']) {
                $state = $this->credentials->decrypt($row['state_ciphertext']);
                $row['setup_available'] = isset($state['setup_secret']['verification_token']);
            }
            unset($row['state_ciphertext'], $row['lease_token'], $row['lease_until']);
            $row['config'] = json_decode($row['config_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($row['config_json']);
            $row['enabled'] = (bool)$row['enabled'];
            $row['public_url'] = $row['callback_url'] ? substr($row['callback_url'], 0, strpos($row['callback_url'], '/webhook.php?trigger_id=')) : '';
            $row['events'] = $this->query('SELECT status, COUNT(*) AS count FROM trigger_events WHERE trigger_id = ? GROUP BY status', [$row['id']])->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    public function catalog(): array
    {
        return array_values($this->registry->definitions());
    }

    private function secrets(array $row): array
    {
        if ($this->registry->definition($row['provider_key'])['secret_fields'] === []) {
            return [];
        }
        $credential = $this->credentials->getCredentialDecrypted($row['credential_id'] ?? '');
        if (!$credential) {
            throw new \InvalidArgumentException('Selecciona una credencial existente.');
        }
        $data = $credential['data'];
        if (is_string($data['api_key'] ?? null)) {
            $decoded = json_decode($data['api_key'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                $data['access_token'] ??= $data['api_key'];
            }
        }
        foreach ($this->registry->definition($row['provider_key'])['secret_fields'] as $key => $field) {
            if (($field['required'] ?? false) && (!is_string($data[$key] ?? null) || trim($data[$key]) === '')) {
                throw new \InvalidArgumentException('La credencial no contiene todos los campos secretos requeridos.');
            }
        }
        return $data;
    }

    public function save(array $input): string
    {
        $id = $input['id'] ?? bin2hex(random_bytes(16));
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Identificador de receptor inválido.');
        }
        $previous = isset($input['id']) ? $this->get($id) : null;
        $workflowId = $input['workflow_id'] ?? '';
        $start = WorkflowRuntime::start($this->workflow($workflowId));
        if (($input['node_id'] ?? '') !== $start['id'] || !in_array($start['type'], ['start', 'service_trigger'], true)) {
            throw new \InvalidArgumentException('Selecciona el único inicio Start o Evento de servicio del workflow guardado.');
        }
        if ($previous && $previous['workflow_id'] !== $workflowId) {
            throw new \InvalidArgumentException('No se puede trasladar un receptor entre workflows.');
        }
        $definition = $this->registry->definition($input['provider_key'] ?? '');
        $config = $input['config'] ?? [];
        if (!is_array($config) || array_is_list($config) && $config !== []) {
            throw new \InvalidArgumentException('Configuración inválida.');
        }
        foreach ($config as $key => $value) {
            if (!isset($definition['config_fields'][$key]) || (!is_scalar($value) && !is_array($value)) || strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 8192) {
                throw new \InvalidArgumentException('Campo de configuración no permitido.');
            }
        }
        foreach ($definition['config_fields'] as $key => $field) {
            if (($field['required'] ?? false) && (!isset($config[$key]) || $config[$key] === '' || $config[$key] === [])) {
                throw new \InvalidArgumentException('Falta un campo público obligatorio: ' . $key);
            }
            if (isset($config[$key])) {
                $type = $field['type'] ?? 'string';
                if (($type === 'string' && !is_string($config[$key])) || ($type === 'number' && !is_numeric($config[$key])) || ($type === 'boolean' && !is_bool($config[$key]))) {
                    throw new \InvalidArgumentException('Tipo de campo público inválido: ' . $key);
                }
                if (isset($field['options'])) {
                    $allowed = array_map(static fn ($option) => is_array($option) ? $option['value'] : $option, $field['options']);
                    if (!in_array($config[$key], $allowed, true)) {
                        throw new \InvalidArgumentException('Opción de campo público inválida: ' . $key);
                    }
                }
            }
        }
        $interval = filter_var($input['interval_seconds'] ?? 60, FILTER_VALIDATE_INT);
        if ($interval === false || $interval < 60 || $interval > 86400) {
            throw new \InvalidArgumentException('El intervalo debe estar entre 60 y 86400 segundos.');
        }
        $callback = null;
        if ($definition['transport'] === 'webhook') {
            $base = rtrim($input['public_url'] ?? '', '/');
            $parts = parse_url($base);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $base)) {
                throw new \InvalidArgumentException('Indica la URL pública HTTPS de la carpeta public, sin credenciales ni query.');
            }
            $callback = $base . '/webhook.php?trigger_id=' . $id;
        }
        $row = ['provider_key' => $input['provider_key'], 'credential_id' => $input['credential_id'] ?? ''];
        $secrets = str_starts_with($row['provider_key'], 'notion_webhook_') && !$row['credential_id'] ? [] : $this->secrets($row);
        $serializedConfig = json_encode($config, JSON_THROW_ON_ERROR);
        foreach ($this->registry->definition($row['provider_key'])['secret_fields'] as $key => $field) {
            $secret = $secrets[$key] ?? null;
            if (is_string($secret) && strlen($secret) >= 8 && str_contains($serializedConfig, $secret)) {
                throw new \InvalidArgumentException('Los secretos deben permanecer exclusivamente en la credencial.');
            }
        }
        $values = [$workflowId, $start['id'], $input['provider_key'], $row['credential_id'], $serializedConfig, $callback, $interval];
        if ($previous) {
            $this->query("UPDATE trigger_configs SET workflow_id=?, node_id=?, provider_key=?, credential_id=?, config_json=?, callback_url=?, interval_seconds=?, enabled=0, status='draft', state_ciphertext=NULL, version=version+1, lease_token=NULL, lease_until=NULL, next_poll_at=NULL, last_error=NULL WHERE id=?", [...$values, $id]);
        } else {
            $this->query('INSERT INTO trigger_configs (workflow_id,node_id,provider_key,credential_id,config_json,callback_url,interval_seconds,id) VALUES (?,?,?,?,?,?,?,?)', [...$values, $id]);
        }
        return $id;
    }

    public function activate(string $id): void
    {
        $row = $this->get($id);
        $this->db->beginTransaction();
        try {
            $this->query('UPDATE workflows SET is_active=is_active WHERE id=?', [$row['workflow_id']]);
            // Shopify signs the body, not routing headers. A secret belongs to one active
            // receiver/app/shop/topic; serialize activation before comparing credentials.
            if (str_starts_with($row['provider_key'], 'shopify_')) {
                $this->query("UPDATE trigger_configs SET version=version WHERE provider_key LIKE 'shopify_%'");
            }
            $this->query('UPDATE trigger_configs SET version=version WHERE id=?', [$id]);
            $row = $this->get($id);
            if (!(bool)$row['enabled']) {
                $this->boundWorkflow($row);
                $secrets = $this->secrets($row);
                $definition = $this->registry->definition($row['provider_key']);
                if ($definition['transport'] === 'webhook' && empty($row['callback_url'])) {
                    throw new \InvalidArgumentException('Falta la URL pública HTTPS.');
                }
                if (str_starts_with($row['provider_key'], 'shopify_')) {
                    $others = $this->query("SELECT * FROM trigger_configs WHERE enabled=1 AND provider_key LIKE 'shopify_%' AND id<>?", [$id])->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($others as $other) {
                        $otherSecrets = $this->secrets($other);
                        if (hash_equals(hash('sha256', $secrets['app_secret']), hash('sha256', $otherSecrets['app_secret']))) {
                            throw new \InvalidArgumentException('Shopify requiere una app/secreto exclusivo por tienda y receptor activo.');
                        }
                    }
                }
                $state = $this->credentials->encrypt(['activated_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now())]);
                $status = $definition['transport'] === 'webhook' ? 'awaiting_event' : 'awaiting_baseline';
                $this->query('UPDATE trigger_configs SET enabled=1,status=?,state_ciphertext=?,next_poll_at=?,version=version+1,lease_token=NULL,lease_until=NULL,last_error=NULL WHERE id=? AND enabled=0', [$status,$state,$definition['transport'] === 'polling' ? $this->now() : null,$id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function pause(string $id): void
    {
        $this->get($id);
        $this->query("UPDATE trigger_configs SET enabled=0,status='paused',version=version+1,lease_token=NULL,lease_until=NULL WHERE id=?", [$id]);
    }

    public function delete(string $id): void
    {
        $this->pause($id);
        $this->query('DELETE FROM trigger_configs WHERE id=?', [$id]);
        // Event identities remain to prevent an accidental replay; no credential is removed.
    }

    public function workflowState(string $workflowId, bool $enabled): void
    {
        $this->workflow($workflowId);
        $this->db->beginTransaction();
        try {
            $this->query('UPDATE workflows SET is_active=? WHERE id=?', [(int)$enabled,$workflowId]);
            if (!$enabled) {
                $this->pauseWorkflow($workflowId);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function pauseWorkflow(string $workflowId): void
    {
        $this->query("UPDATE trigger_configs SET enabled=0,status='paused',version=version+1,lease_token=NULL,lease_until=NULL WHERE workflow_id=?", [$workflowId]);
    }

    public function receive(string $id, array $request): array
    {
        if (strlen($request['raw_body'] ?? '') > 1048576) {
            throw new \LengthException('Cuerpo demasiado grande.');
        }
        $row = $this->get($id);
        if (!(bool)$row['enabled'] && str_starts_with($row['provider_key'], 'notion_webhook_') && in_array($row['status'], ['draft', 'awaiting_verification'], true)) {
            return $this->notionSetup($row, $request);
        }
        if (!(bool)$row['enabled']) {
            throw new \InvalidArgumentException('Receptor desactivado.');
        }
        $this->boundWorkflow($row);
        if ($this->registry->definition($row['provider_key'])['transport'] !== 'webhook') {
            throw new \InvalidArgumentException('Este receptor utiliza polling.');
        }
        $request['callback_url'] = $row['callback_url'];
        $result = $this->registry->provider($row['provider_key'])->verify($row['provider_key'], json_decode($row['config_json'], true), $this->secrets($row), $request, $this->now());
        if (!is_array($result['events'] ?? null) || !array_is_list($result['events'])) {
            throw new \RuntimeException('Respuesta de webhook inválida.');
        }
        $this->commitEvents($row, $result['events'], null, null);
        unset($result['events']);
        return $result;
    }

    private function notionSetup(array $row, array $request): array
    {
        $this->boundWorkflow($row);
        $request['setup_allowed'] = true;
        $result = $this->registry->provider($row['provider_key'])->verify($row['provider_key'], json_decode($row['config_json'], true), [], $request, $this->now());
        $setup = $result['setup_secret'] ?? null;
        if (!is_array($setup) || !is_string($setup['verification_token'] ?? null) || ($result['events'] ?? null) !== []) {
            throw new \InvalidArgumentException('Desafío Notion inválido.');
        }
        $this->db->beginTransaction();
        try {
            $this->query('UPDATE trigger_configs SET version=version WHERE id=?', [$row['id']]);
            $current = $this->get($row['id']);
            if ($current['enabled'] || (int)$current['version'] !== (int)$row['version']) {
                throw new \InvalidArgumentException('El receptor cambió durante la verificación.');
            }
            if ($current['state_ciphertext']) {
                $previous = $this->credentials->decrypt($current['state_ciphertext']);
                if (!isset($previous['setup_secret']['verification_token']) || !hash_equals($previous['setup_secret']['verification_token'], $setup['verification_token'])) {
                    throw new \InvalidArgumentException('Ya se recibió un desafío. Guarda de nuevo el borrador para reiniciarlo.');
                }
            }
            $this->query("UPDATE trigger_configs SET state_ciphertext=?,status='awaiting_verification' WHERE id=?", [$this->credentials->encrypt(['setup_secret' => $setup]),$row['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        unset($result['setup_secret'], $result['events']);
        return $result;
    }

    /** Explicit authenticated setup operation; never included in catalog/list or public ACKs. */
    public function setupSecret(string $id): array
    {
        $row = $this->get($id);
        if ($row['enabled'] || !str_starts_with($row['provider_key'], 'notion_webhook_') || !$row['state_ciphertext']) {
            throw new \InvalidArgumentException('No hay un desafío pendiente.');
        }
        $state = $this->credentials->decrypt($row['state_ciphertext']);
        return $state['setup_secret'] ?? throw new \InvalidArgumentException('No hay un desafío pendiente.');
    }

    private function clean(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('Evento demasiado profundo.');
        }
        if (is_object($value)) {
            $value = (array)$value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            if (preg_match('/(?:password|passwd|secret|token|authorization|api[_-]?key|order_key|download_url)/i', (string)$key)) {
                continue;
            }
            $clean[$key] = $this->clean($item, $depth + 1);
        }
        return $clean;
    }

    private function commitEvents(array $claimed, array $events, ?array $pollState, ?bool $hasMore): int
    {
        if (count($events) > ($pollState === null ? 1000 : 100)) {
            throw new \InvalidArgumentException('Demasiados eventos por turno.');
        }
        $this->db->beginTransaction();
        try {
            // All mutation paths lock workflow before receiver to avoid lock inversion.
            $this->query('UPDATE workflows SET is_active=is_active WHERE id=?', [$claimed['workflow_id']]);
            // A write lock also serializes SQLite fixtures. InnoDB holds this row lock through ACK.
            $this->query('UPDATE trigger_configs SET version=version WHERE id=?', [$claimed['id']]);
            $row = $this->get($claimed['id']);
            if (!(bool)$row['enabled'] || (int)$row['version'] !== (int)$claimed['version'] || ($pollState !== null && !hash_equals($row['lease_token'] ?? '', $claimed['lease_token'] ?? ''))) {
                throw new \InvalidArgumentException('La configuración cambió durante la recepción.');
            }
            // Lock activation while admitting events; disabled workflows cannot enqueue new work.
            $workflow = $this->boundWorkflow($row);
            $queue = new QueueManager($this->db);
            $count = 0;
            foreach ($events as $event) {
                if (!is_array($event) || !is_string($event['id'] ?? null) || $event['id'] === '' || strlen($event['id']) > 512 || !is_string($event['type'] ?? null) || strlen($event['type']) > 128 || !is_array($event['data'] ?? null)) {
                    throw new \InvalidArgumentException('Evento normalizado inválido.');
                }
                $eventKey = hash('sha256', $event['type'] . "\0" . $event['id']);
                if ($this->query('SELECT id FROM trigger_events WHERE trigger_id=? AND event_key=?', [$row['id'],$eventKey])->fetchColumn()) {
                    continue;
                }
                $id = bin2hex(random_bytes(16));
                $normalized = ['id' => $event['id'], 'type' => $event['type'], 'data' => $this->clean($event['data']), 'trigger_id' => $row['id'], 'trigger_version' => (int)$row['version'], 'received_at' => $this->now()];
                if (strlen(json_encode($normalized, JSON_THROW_ON_ERROR)) > 1048576) {
                    throw new \LengthException('Evento normalizado demasiado grande.');
                }
                $cipher = $this->credentials->encrypt(['workflow' => $workflow, 'event' => $normalized]);
                $job = $queue->enqueue($row['workflow_id'], ['trigger_event_id' => $id]);
                $this->query("INSERT INTO trigger_events (id,trigger_id,event_key,job_id,status,payload_ciphertext,received_at) VALUES (?,?,?,?,'enqueued',?,?)", [$id,$row['id'],$eventKey,$job,$cipher,$this->now()]);
                $count++;
            }
            if ($pollState !== null) {
                if (strlen(json_encode($pollState, JSON_THROW_ON_ERROR)) > 4194304) {
                    throw new \LengthException('Estado de polling demasiado grande.');
                }
                $this->query("UPDATE trigger_configs SET state_ciphertext=?,status='active',last_error=NULL,next_poll_at=?,last_attempt_at=?,lease_token=NULL,lease_until=NULL WHERE id=?", [$this->credentials->encrypt($pollState),$this->now()+($hasMore ? 60 : (int)$row['interval_seconds']),$this->now(),$row['id']]);
            }
            if ($events !== []) {
                $this->query("UPDATE trigger_configs SET last_received_at=?,status='active',last_error=NULL WHERE id=?", [$this->now(),$row['id']]);
            }
            $this->db->commit();
            return $count;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function pollDue(int $limit = 5): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Límite de polling inválido.');
        }
        $rows = $this->query('SELECT * FROM trigger_configs WHERE enabled=1 AND next_poll_at<=? AND (lease_until IS NULL OR lease_until<=?) ORDER BY next_poll_at,id LIMIT ' . $limit, [$this->now(),$this->now()])->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['polled' => 0, 'enqueued' => 0, 'deferred' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            if ($this->registry->definition($row['provider_key'])['transport'] !== 'polling') {
                continue;
            }
            $lease = bin2hex(random_bytes(32));
            $changed = $this->query('UPDATE trigger_configs SET lease_token=?,lease_until=?,last_attempt_at=? WHERE id=? AND enabled=1 AND version=? AND next_poll_at<=? AND (lease_until IS NULL OR lease_until<=?)', [$lease,$this->now()+120,$this->now(),$row['id'],$row['version'],$this->now(),$this->now()])->rowCount();
            if ($changed !== 1) {
                continue;
            }
            $row['lease_token'] = $lease;
            try {
                $this->boundWorkflow($row);
                $state = $row['state_ciphertext'] ? $this->credentials->decrypt($row['state_ciphertext']) : [];
                $result = $this->registry->provider($row['provider_key'])->poll($row['provider_key'], json_decode($row['config_json'], true), $this->secrets($row), $state, $this->now());
                if (!is_array($result['state'] ?? null) || !is_bool($result['has_more'] ?? null) || !is_array($result['events'] ?? null) || !array_is_list($result['events'])) {
                    throw new \RuntimeException('Respuesta de polling inválida.');
                }
                $summary['enqueued'] += $this->commitEvents($row, $result['events'] ?? [], $result['state'], $result['has_more']);
                $summary['polled']++;
            } catch (\Throwable $e) {
                $retry = $e instanceof TriggerRetryException;
                $this->query('UPDATE trigger_configs SET status=?,enabled=?,last_error=?,next_poll_at=?,lease_token=NULL,lease_until=NULL WHERE id=? AND lease_token=?', [$retry ? 'retry_wait' : 'error',$retry ? 1 : 0,$retry ? 'Proveedor temporalmente no disponible; lectura reprogramada.' : 'Lectura rechazada. Revisa permisos, credencial, configuración y documentación; vuelve a activar para nueva línea base.',$this->now()+($retry ? max(60,min(86400,$e->retryAfter)) : 60),$row['id'],$lease]);
                $summary[$retry ? 'deferred' : 'failed']++;
            }
        }
        return $summary;
    }
}
