<?php

namespace P8P\Core;

use PDO;
use RuntimeException;

/** Additive schema for trigger configuration and durable event identities. */
class TriggerSchema
{
    public static function install(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('El esquema de triggers requiere MySQL/MariaDB; SQLite solo se utiliza en pruebas.');
        }
        foreach (self::statements($driver) as $sql) {
            $db->exec($sql);
        }
    }

    /** Exposed to keep canonical SQL reviewable alongside fresh-install DDL. */
    public static function statements(string $driver = 'mysql'): array
    {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Dialecto de esquema no admitido.');
        }
        $mysql = $driver === 'mysql';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $updated = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $configs = "CREATE TABLE IF NOT EXISTS trigger_configs (
            id VARCHAR(32) NOT NULL,
            workflow_id VARCHAR(255) NOT NULL,
            node_id VARCHAR(255) NOT NULL,
            provider_key VARCHAR(128) NOT NULL,
            credential_id VARCHAR(255) NULL,
            config_json LONGTEXT NOT NULL,
            callback_url TEXT NULL,
            enabled INT NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            interval_seconds INT NOT NULL DEFAULT 60,
            state_ciphertext LONGTEXT NULL,
            version INT NOT NULL DEFAULT 1,
            next_poll_at BIGINT NULL,
            lease_token VARCHAR(64) NULL,
            lease_until BIGINT NULL,
            last_received_at BIGINT NULL,
            last_attempt_at BIGINT NULL,
            last_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP{$updated},
            PRIMARY KEY (id)
        ){$suffix}";
        $events = "CREATE TABLE IF NOT EXISTS trigger_events (
            id VARCHAR(32) NOT NULL,
            trigger_id VARCHAR(32) NOT NULL,
            event_key VARCHAR(64) NOT NULL,
            job_id VARCHAR(255) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'received',
            payload_ciphertext LONGTEXT NOT NULL,
            received_at BIGINT NOT NULL,
            PRIMARY KEY (id),
            CONSTRAINT uq_trigger_event UNIQUE (trigger_id, event_key)
        ){$suffix}";
        if ($mysql) {
            // Inline indexes make repeated CREATE TABLE idempotent on all supported MariaDB versions.
            $configs = str_replace('PRIMARY KEY (id)', 'PRIMARY KEY (id),
            KEY idx_trigger_workflow (workflow_id),
            KEY idx_trigger_due (enabled, next_poll_at, lease_until)', $configs);
            $events = str_replace('PRIMARY KEY (id),', 'PRIMARY KEY (id),
            KEY idx_trigger_events_job (job_id),
            KEY idx_trigger_events_received (trigger_id, received_at),', $events);
            return [$configs, $events];
        }
        return [$configs, $events,
            'CREATE INDEX IF NOT EXISTS idx_trigger_workflow ON trigger_configs (workflow_id)',
            'CREATE INDEX IF NOT EXISTS idx_trigger_due ON trigger_configs (enabled, next_poll_at, lease_until)',
            'CREATE INDEX IF NOT EXISTS idx_trigger_events_job ON trigger_events (job_id)',
            'CREATE INDEX IF NOT EXISTS idx_trigger_events_received ON trigger_events (trigger_id, received_at)'];
    }
}
