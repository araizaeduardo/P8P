<?php

namespace P8P\Core;

use PDO;
use RuntimeException;

/** A short DB transaction claims a session; no transaction is held during model HTTP. */
class ConversationMemoryStore
{
    private \Closure $clock;

    public function __construct(private PDO $db, private CredentialManager $credentials, ?callable $clock = null)
    {
        $this->clock = \Closure::fromCallable($clock ?? 'time');
    }

    public static function connect(): self
    {
        try {
            $db = Database::getConnection(require __DIR__ . '/../../config/database.php');
            return new self($db, new CredentialManager($db));
        } catch (\Throwable $e) {
            throw new RuntimeException('Simple Memory: no se pudo abrir el almacenamiento cifrado.');
        }
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public function begin(array $config): array
    {
        $this->validateConfig($config);
        $now = ($this->clock)();
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Simple Memory: no puede iniciarse dentro de otra transacción.');
        }
        $busy = false;
        try {
            $this->db->beginTransaction();
            $keys = [$config['scope_hash'], $config['session_hash']];
            $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $this->query('INSERT INTO conversation_memory (scope_hash,session_hash,expires_at) VALUES (?,?,?) '
                . ($mysql ? 'ON DUPLICATE KEY UPDATE scope_hash=VALUES(scope_hash)' : 'ON CONFLICT(scope_hash,session_hash) DO NOTHING'),
                [...$keys, $now + $config['ttl_seconds']]);
            $row = $this->query('SELECT * FROM conversation_memory WHERE scope_hash=? AND session_hash=?'
                . ($mysql ? ' FOR UPDATE' : ''), $keys)->fetch(PDO::FETCH_ASSOC);
            if ($row['lease_token'] !== null && (int)$row['lease_until'] > $now) {
                $busy = true;
                throw new RuntimeException('Simple Memory: esta sesión tiene una respuesta en curso. Espera antes de enviar otro mensaje.');
            }
            $messages = [];
            if ((int)$row['expires_at'] > $now && $row['history_ciphertext'] !== null) {
                $stored = $this->credentials->decrypt($row['history_ciphertext']);
                if (!is_array($stored) || !is_array($stored['messages'] ?? null)
                    || ($stored['scope_hash'] ?? null) !== $config['scope_hash']
                    || ($stored['session_hash'] ?? null) !== $config['session_hash']) {
                    throw new RuntimeException('Simple Memory: historial cifrado inválido.');
                }
                $messages = $this->validateMessages($stored['messages']);
                $messages = array_slice($messages, -2 * $config['context_window']);
            }
            $token = bin2hex(random_bytes(32));
            $this->query('UPDATE conversation_memory SET lease_token=?,lease_until=? WHERE scope_hash=? AND session_hash=?',
                [$token, $now + 600, ...$keys]);
            $this->db->commit();
            return ['scope_hash' => $config['scope_hash'], 'session_hash' => $config['session_hash'],
                'lease_token' => $token, 'version' => (int)$row['version'], 'messages' => $messages,
                'context_window' => $config['context_window'], 'ttl_seconds' => $config['ttl_seconds']];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($busy) {
                throw new RuntimeException('Simple Memory: esta sesión tiene una respuesta en curso. Espera antes de enviar otro mensaje.');
            }
            $missing = $e instanceof \PDOException && (in_array((string)$e->getCode(), ['42S02', '42S22'], true)
                || ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($e->getMessage(), 'no such table')));
            throw new RuntimeException($missing
                ? 'Simple Memory: falta preparar las tablas. Aplica database/migrations/20261105_simple_memory.php --apply.'
                : 'Simple Memory: no se pudo leer el almacenamiento cifrado. Revisa conexión, permisos y APP_SECRET.');
        }
    }

    public function complete(array $turn, string $user, string $assistant): void
    {
        $messages = $this->validateMessages([...$turn['messages'],
            ['role' => 'user', 'content' => $user], ['role' => 'assistant', 'content' => $assistant]]);
        $messages = array_slice($messages, -2 * $turn['context_window']);
        // Bound storage and later prompts; always evict whole oldest turns.
        while (strlen(json_encode($messages, JSON_THROW_ON_ERROR)) > 65536 && count($messages) > 2) {
            $messages = array_slice($messages, 2);
        }
        if (strlen(json_encode($messages, JSON_THROW_ON_ERROR)) > 65536) {
            throw new RuntimeException('Simple Memory: el turno supera el límite de historial de 64 KiB.');
        }
        try {
            $cipher = $this->credentials->encrypt(['scope_hash' => $turn['scope_hash'], 'session_hash' => $turn['session_hash'], 'messages' => $messages]);
            $now = ($this->clock)();
            $changed = $this->query('UPDATE conversation_memory SET history_ciphertext=?,version=version+1,expires_at=?,lease_token=NULL,lease_until=NULL '
                . 'WHERE scope_hash=? AND session_hash=? AND lease_token=? AND version=? AND lease_until>?',
                [$cipher, $now + $turn['ttl_seconds'], $turn['scope_hash'], $turn['session_hash'],
                    $turn['lease_token'], $turn['version'], $now])->rowCount();
            if ($changed !== 1) {
                throw new RuntimeException('Simple Memory: la sesión cambió o venció mientras el modelo respondía; el turno no se guardó.');
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Simple Memory: no se pudo guardar el turno de forma segura. La respuesta del modelo pudo generarse; no se reintenta automáticamente.');
        }
    }

    public function release(array $turn): void
    {
        try {
            $this->query('UPDATE conversation_memory SET lease_token=NULL,lease_until=NULL WHERE scope_hash=? AND session_hash=? AND lease_token=?',
                [$turn['scope_hash'], $turn['session_hash'], $turn['lease_token']]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Simple Memory: no se pudo liberar la sesión; se desbloqueará al vencer la reserva de 10 minutos.');
        }
    }

    public function cleanup(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new RuntimeException('Simple Memory: límite de limpieza inválido.');
        }
        $now = ($this->clock)();
        try {
            $rows = $this->query('SELECT scope_hash,session_hash FROM conversation_memory WHERE expires_at<=? AND (lease_token IS NULL OR lease_until<=?) LIMIT ' . $limit, [$now, $now])->fetchAll(PDO::FETCH_ASSOC);
            $count = 0;
            foreach ($rows as $row) {
                $count += $this->query('DELETE FROM conversation_memory WHERE scope_hash=? AND session_hash=? AND expires_at<=? AND (lease_token IS NULL OR lease_until<=?)',
                    [$row['scope_hash'], $row['session_hash'], $now, $now])->rowCount();
            }
            return $count;
        } catch (\PDOException $e) {
            // Before migration, existing workflows and workers must remain usable.
            if (in_array((string)$e->getCode(), ['42S02', '42S22'], true)
                || ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($e->getMessage(), 'no such table'))) {
                return 0;
            }
            throw new RuntimeException('Simple Memory: no se pudo limpiar el historial caducado.');
        }
    }

    private function validateMessages(array $messages): array
    {
        if (!array_is_list($messages) || count($messages) > 42 || count($messages) % 2 !== 0) {
            throw new RuntimeException('Simple Memory: historial de turnos inválido.');
        }
        foreach ($messages as $index => $message) {
            if (!is_array($message) || ($message['role'] ?? null) !== ($index % 2 === 0 ? 'user' : 'assistant')
                || !is_string($message['content'] ?? null) || trim($message['content']) === ''
                || strlen($message['content']) > 65536 || !mb_check_encoding($message['content'], 'UTF-8')) {
                throw new RuntimeException('Simple Memory: historial textual inválido o demasiado grande.');
            }
        }
        return $messages;
    }

    private function validateConfig(array $config): void
    {
        foreach (['scope_hash', 'session_hash'] as $key) {
            if (!is_string($config[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $config[$key])) {
                throw new RuntimeException('Simple Memory: configuración de sesión inválida.');
            }
        }
        if (!is_int($config['context_window'] ?? null) || $config['context_window'] < 1 || $config['context_window'] > 20
            || !is_int($config['ttl_seconds'] ?? null) || $config['ttl_seconds'] < 60 || $config['ttl_seconds'] > 2592000) {
            throw new RuntimeException('Simple Memory: ventana o caducidad inválida.');
        }
    }
}
