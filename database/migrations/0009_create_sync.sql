-- Petra — développé par BELEJ-CONSULTING.
-- Synchronisation station → central (bin/sync.php).

-- File d'attente locale : une entrée par ligne à transmettre, rejouée dans l'ordre de seq.
CREATE TABLE sync_queue (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation ENUM('insert', 'update') NOT NULL,
    payload LONGTEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sq_seq (seq),
    KEY idx_sq_station_status (station_id, status, seq),
    KEY idx_sq_entity (entity_type, entity_id),
    CONSTRAINT fk_sq_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_sq_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_sq_payload CHECK (JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des passes de synchronisation (côté station et côté central).
CREATE TABLE sync_log (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    direction ENUM('push', 'pull') NOT NULL,
    status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    items_sent INT UNSIGNED NOT NULL DEFAULT 0,
    items_accepted INT UNSIGNED NOT NULL DEFAULT 0,
    items_rejected INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    KEY idx_sl_station_started (station_id, started_at),
    CONSTRAINT fk_sl_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_sl_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
