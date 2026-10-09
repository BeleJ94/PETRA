-- Petra — développé par BELEJ-CONSULTING.
-- Contrôle : alertes d'écart, journal d'audit, seuils par station.

-- Alerte : écart chiffré, rattaché à une station, un poste et un utilisateur.
CREATE TABLE alerts (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shift_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    alert_type VARCHAR(40) NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
    entity_type VARCHAR(40) NULL,
    entity_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    message VARCHAR(255) NOT NULL,
    -- Écart en argent (CDF) et/ou en volume, et seuil de la station qui a été franchi.
    amount BIGINT NULL,
    currency CHAR(3) NULL,
    volume_l DECIMAL(12,2) NULL,
    threshold_value VARCHAR(40) NULL,
    status ENUM('open', 'acknowledged', 'resolved') NOT NULL DEFAULT 'open',
    acknowledged_at DATETIME NULL,
    acknowledged_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    resolved_at DATETIME NULL,
    resolved_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    resolution_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- NULL : alerte levée par le système.
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    KEY idx_alr_station_status (station_id, status, created_at),
    KEY idx_alr_entity (entity_type, entity_id),
    CONSTRAINT fk_alr_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_alr_shift FOREIGN KEY (shift_id) REFERENCES shifts (id),
    CONSTRAINT fk_alr_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_alr_acknowledged_by FOREIGN KEY (acknowledged_by) REFERENCES users (id),
    CONSTRAINT fk_alr_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (id),
    CONSTRAINT fk_alr_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_alr_currency CHECK ((amount IS NULL AND currency IS NULL) OR (amount IS NOT NULL AND currency IN ('CDF', 'USD'))),
    CONSTRAINT chk_alr_resolved CHECK (status <> 'resolved' OR (resolved_at IS NOT NULL AND resolved_by IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal d'audit : qui, quoi, quand, avant / après. Jamais modifié.
CREATE TABLE audit_log (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    user_name VARCHAR(170) NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    action VARCHAR(40) NOT NULL,
    summary VARCHAR(255) NULL,
    before_data LONGTEXT NULL,
    after_data LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- NULL : action du système ou tentative de connexion non identifiée.
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    KEY idx_aud_station_date (station_id, created_at),
    KEY idx_aud_entity (entity_type, entity_id),
    KEY idx_aud_user_date (created_by, created_at),
    CONSTRAINT fk_aud_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_aud_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_aud_before CHECK (before_data IS NULL OR JSON_VALID(before_data)),
    CONSTRAINT chk_aud_after CHECK (after_data IS NULL OR JSON_VALID(after_data))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seuils et réglages propres à une station (tolérances d'écart, niveaux bas…).
CREATE TABLE station_settings (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    setting_key VARCHAR(80) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_set_station_key (station_id, setting_key),
    CONSTRAINT fk_set_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_set_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_set_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
