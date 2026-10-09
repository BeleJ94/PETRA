-- Petra — développé par BELEJ-CONSULTING.
-- Postes, tranches de poste et relevés d'index.
-- Un poste = un pompiste, sur une station, de l'ouverture à la remise de caisse.
CREATE TABLE shifts (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attendant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    business_date DATE NOT NULL,
    status ENUM('open', 'closed', 'validated') NOT NULL DEFAULT 'open',
    opened_at DATETIME NOT NULL,
    opened_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    closed_at DATETIME NULL,
    closed_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    validated_at DATETIME NULL,
    validated_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    -- Chiffres figés à la clôture, en CDF : attendu (ventes par index), remis, écart = remis - attendu.
    expected_amount BIGINT NULL,
    received_amount BIGINT NULL,
    variance_amount BIGINT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'CDF',
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Un seul poste ouvert par pompiste : la colonne vaut NULL dès que le poste est clôturé.
    open_attendant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin AS (IF(status = 'open', attendant_id, NULL)) PERSISTENT,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shf_one_open (open_attendant_id),
    KEY idx_shf_station_date (station_id, business_date),
    KEY idx_shf_station_status (station_id, status),
    KEY idx_shf_attendant_date (attendant_id, business_date),
    CONSTRAINT fk_shf_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_shf_attendant FOREIGN KEY (attendant_id) REFERENCES users (id),
    CONSTRAINT fk_shf_opened_by FOREIGN KEY (opened_by) REFERENCES users (id),
    CONSTRAINT fk_shf_closed_by FOREIGN KEY (closed_by) REFERENCES users (id),
    CONSTRAINT fk_shf_validated_by FOREIGN KEY (validated_by) REFERENCES users (id),
    CONSTRAINT fk_shf_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_shf_closed CHECK (status = 'open' OR (closed_at IS NOT NULL AND closed_by IS NOT NULL AND variance_amount IS NOT NULL)),
    CONSTRAINT chk_shf_validated CHECK (status <> 'validated' OR (validated_at IS NOT NULL AND validated_by IS NOT NULL)),
    CONSTRAINT chk_shf_currency CHECK (currency = 'CDF')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tranche de poste : période à prix et taux constants. Tout changement de prix ou de taux
-- pendant un poste ouvre une nouvelle tranche, avec relevé intermédiaire des index.
-- La fin d'une tranche est le début de la suivante, ou la clôture du poste.
CREATE TABLE shift_segments (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shift_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL,
    reason ENUM('shift_open', 'price_change', 'rate_change', 'price_and_rate_change') NOT NULL,
    started_at DATETIME NOT NULL,
    -- Taux USD/CDF en vigueur pendant la tranche.
    exchange_rate_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seg_shift_sequence (shift_id, sequence),
    KEY idx_seg_station_started (station_id, started_at),
    CONSTRAINT fk_seg_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_seg_shift FOREIGN KEY (shift_id) REFERENCES shifts (id),
    CONSTRAINT fk_seg_exchange_rate FOREIGN KEY (exchange_rate_id) REFERENCES exchange_rates (id),
    CONSTRAINT fk_seg_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_seg_sequence CHECK (sequence >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relevé d'index d'un pistolet : un relevé d'ouverture et un de fermeture par tranche.
-- Volume vendu de la tranche = index de fermeture - index d'ouverture - sorties hors vente.
CREATE TABLE meter_readings (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shift_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    segment_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nozzle_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reading_type ENUM('opening', 'closing') NOT NULL,
    index_value DECIMAL(12,2) NOT NULL,
    -- Prix du produit du pistolet en vigueur pendant la tranche.
    price_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    read_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Correction : nouvelle ligne liée à l'originale, motivée et approuvée par un second utilisateur.
    corrects_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    correction_reason VARCHAR(255) NULL,
    approved_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    approved_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_mr_segment_nozzle (segment_id, nozzle_id, reading_type),
    KEY idx_mr_station_read (station_id, read_at),
    KEY idx_mr_shift (shift_id),
    KEY idx_mr_nozzle_read (nozzle_id, read_at),
    CONSTRAINT fk_mr_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_mr_shift FOREIGN KEY (shift_id) REFERENCES shifts (id),
    CONSTRAINT fk_mr_segment FOREIGN KEY (segment_id) REFERENCES shift_segments (id),
    CONSTRAINT fk_mr_nozzle FOREIGN KEY (nozzle_id) REFERENCES nozzles (id),
    CONSTRAINT fk_mr_price FOREIGN KEY (price_id) REFERENCES prices (id),
    CONSTRAINT chk_mr_index CHECK (index_value >= 0),
    CONSTRAINT fk_mr_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_mr_corrects FOREIGN KEY (corrects_id) REFERENCES meter_readings (id),
    CONSTRAINT fk_mr_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT chk_mr_correction CHECK (corrects_id IS NULL OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND correction_reason IS NOT NULL)),
    CONSTRAINT chk_mr_four_eyes CHECK (approved_by IS NULL OR approved_by <> created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
