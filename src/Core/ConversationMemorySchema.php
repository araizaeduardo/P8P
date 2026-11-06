<?php

namespace P8P\Core;

use PDO;
use RuntimeException;

class ConversationMemorySchema
{
    public static function install(PDO $db): void
    {
        foreach (self::statements($db->getAttribute(PDO::ATTR_DRIVER_NAME)) as $statement) {
            $db->exec($statement);
        }
    }

    public static function statements(string $driver = 'mysql'): array
    {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Simple Memory requiere MySQL/MariaDB; SQLite se admite solo para pruebas.');
        }
        $index = $driver === 'mysql' ? ', KEY idx_memory_expiry (expires_at)' : '';
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $table = "CREATE TABLE IF NOT EXISTS conversation_memory (
            scope_hash VARCHAR(64) NOT NULL,
            session_hash VARCHAR(64) NOT NULL,
            history_ciphertext LONGTEXT NULL,
            version BIGINT NOT NULL DEFAULT 0,
            expires_at BIGINT NOT NULL,
            lease_token VARCHAR(64) NULL,
            lease_until BIGINT NULL,
            PRIMARY KEY (scope_hash, session_hash){$index}
        ){$suffix}";
        return $driver === 'mysql' ? [$table] : [$table, 'CREATE INDEX IF NOT EXISTS idx_memory_expiry ON conversation_memory (expires_at)'];
    }
}
