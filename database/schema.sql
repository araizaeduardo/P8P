-- ===================================================
-- Esquema de Base de Datos para P8P Workflow Engine
-- Archivo: database/schema.sql
-- Compatible con: MariaDB 10.2+ / MySQL 8.0+
-- ===================================================

-- 1. Tabla para almacenar la definición y el lienzo de los Workflows
CREATE TABLE IF NOT EXISTS workflows (
                                         id VARCHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    structure JSON NOT NULL, -- Matriz de nodos y conexiones enviadas desde React Flow
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla para la cola de ejecuciones asíncronas en segundo plano
CREATE TABLE IF NOT EXISTS queue_jobs (
    id VARCHAR(255) NOT NULL,
    workflow_id VARCHAR(255) NULL,
    payload LONGTEXT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    available_at DATETIME NULL,
    claim_token VARCHAR(64) NULL,
    error_message TEXT NULL,
    result LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status),
    KEY idx_queue_available (status, available_at, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para almacenar credenciales encriptadas (API Keys, OAuth Tokens, Basic Auth)
CREATE TABLE IF NOT EXISTS credentials (
                                           id VARCHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(100) NOT NULL,    -- ej: 'openai_api', 'bearer_token', 'header_auth'
    data TEXT NOT NULL,             -- JSON encriptado en AES-256-GCM
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Durable verified triggers
CREATE TABLE IF NOT EXISTS trigger_configs (
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
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_trigger_workflow (workflow_id),
            KEY idx_trigger_due (enabled, next_poll_at, lease_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trigger_events (
            id VARCHAR(32) NOT NULL,
            trigger_id VARCHAR(32) NOT NULL,
            event_key VARCHAR(64) NOT NULL,
            job_id VARCHAR(255) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'received',
            payload_ciphertext LONGTEXT NOT NULL,
            received_at BIGINT NOT NULL,
            PRIMARY KEY (id),
            KEY idx_trigger_events_job (job_id),
            KEY idx_trigger_events_received (trigger_id, received_at),
            CONSTRAINT uq_trigger_event UNIQUE (trigger_id, event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
