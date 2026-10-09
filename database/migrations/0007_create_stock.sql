-- Petra — développé par BELEJ-CONSULTING.
-- Stock physique : jaugeages de cuve et livraisons.

-- Jaugeage : hauteur lue à la jauge et volume correspondant d'après le barème (figé à la saisie).
CREATE TABLE tank_dips (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tank_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shift_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    dip_type ENUM('opening', 'closing', 'before_delivery', 'after_delivery', 'control') NOT NULL,
    depth_cm DECIMAL(6,1) NOT NULL,
    volume_l DECIMAL(12,2) NOT NULL,
    water_cm DECIMAL(6,1) NULL,
    measured_at DATETIME NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Correction : nouvelle ligne liée à l'originale, motivée et approuvée par un second utilisateur.
    corrects_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    correction_reason VARCHAR(255) NULL,
    approved_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    approved_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_td_station_measured (station_id, measured_at),
    KEY idx_td_tank_measured (tank_id, measured_at),
    CONSTRAINT fk_td_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_td_tank FOREIGN KEY (tank_id) REFERENCES tanks (id),
    CONSTRAINT fk_td_shift FOREIGN KEY (shift_id) REFERENCES shifts (id),
    CONSTRAINT chk_td_values CHECK (depth_cm >= 0 AND volume_l >= 0 AND (water_cm IS NULL OR water_cm >= 0)),
    CONSTRAINT fk_td_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_td_corrects FOREIGN KEY (corrects_id) REFERENCES tank_dips (id),
    CONSTRAINT fk_td_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT chk_td_correction CHECK (corrects_id IS NULL OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND correction_reason IS NOT NULL)),
    CONSTRAINT chk_td_four_eyes CHECK (approved_by IS NULL OR approved_by <> created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Livraison : volume facturé (bon de livraison) contre volume reçu (jaugeages avant / après).
CREATE TABLE deliveries (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tank_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    supplier_name VARCHAR(120) NOT NULL,
    delivery_note_no VARCHAR(60) NULL,
    truck_plate VARCHAR(30) NULL,
    driver_name VARCHAR(120) NULL,
    invoiced_volume_l DECIMAL(12,2) NOT NULL,
    received_volume_l DECIMAL(12,2) NOT NULL,
    dip_before_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    dip_after_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    -- Coût d'achat du litre, dans la plus petite unité de la devise.
    unit_cost BIGINT NULL,
    currency CHAR(3) NULL,
    delivered_at DATETIME NOT NULL,
    received_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Correction : nouvelle ligne liée à l'originale, motivée et approuvée par un second utilisateur.
    corrects_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    correction_reason VARCHAR(255) NULL,
    approved_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    approved_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_del_station_delivered (station_id, delivered_at),
    KEY idx_del_tank_delivered (tank_id, delivered_at),
    CONSTRAINT fk_del_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_del_tank FOREIGN KEY (tank_id) REFERENCES tanks (id),
    CONSTRAINT fk_del_dip_before FOREIGN KEY (dip_before_id) REFERENCES tank_dips (id),
    CONSTRAINT fk_del_dip_after FOREIGN KEY (dip_after_id) REFERENCES tank_dips (id),
    CONSTRAINT fk_del_received_by FOREIGN KEY (received_by) REFERENCES users (id),
    CONSTRAINT chk_del_volumes CHECK (invoiced_volume_l > 0 AND received_volume_l >= 0),
    CONSTRAINT chk_del_cost CHECK ((unit_cost IS NULL AND currency IS NULL) OR (unit_cost >= 0 AND currency IN ('CDF', 'USD'))),
    CONSTRAINT fk_del_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_del_corrects FOREIGN KEY (corrects_id) REFERENCES deliveries (id),
    CONSTRAINT fk_del_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT chk_del_correction CHECK (corrects_id IS NULL OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND correction_reason IS NOT NULL)),
    CONSTRAINT chk_del_four_eyes CHECK (approved_by IS NULL OR approved_by <> created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
