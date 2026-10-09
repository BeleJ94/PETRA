-- Petra — développé par BELEJ-CONSULTING.
-- Justification d'un écart de caisse par le gérant : une nouvelle ligne liée au poste (et à l'alerte),
-- jamais une modification ni une suppression. L'écart du poste reste tel qu'il a été constaté.
CREATE TABLE variance_justifications (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shift_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    alert_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    -- Part de l'écart expliquée, en CDF, du même signe que l'écart (négatif : déficit).
    amount BIGINT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'CDF',
    reason VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Correction : nouvelle ligne liée à l'originale, motivée et approuvée par un second utilisateur.
    corrects_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    correction_reason VARCHAR(255) NULL,
    approved_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    approved_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_vj_station_created (station_id, created_at),
    KEY idx_vj_shift (shift_id),
    CONSTRAINT fk_vj_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_vj_shift FOREIGN KEY (shift_id) REFERENCES shifts (id),
    CONSTRAINT fk_vj_alert FOREIGN KEY (alert_id) REFERENCES alerts (id),
    CONSTRAINT chk_vj_amount CHECK (amount <> 0),
    CONSTRAINT chk_vj_currency CHECK (currency = 'CDF'),
    CONSTRAINT fk_vj_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_vj_corrects FOREIGN KEY (corrects_id) REFERENCES variance_justifications (id),
    CONSTRAINT fk_vj_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT chk_vj_correction CHECK (corrects_id IS NULL OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND correction_reason IS NOT NULL)),
    CONSTRAINT chk_vj_four_eyes CHECK (approved_by IS NULL OR approved_by <> created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_variance_justifications_no_update BEFORE UPDATE ON variance_justifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'variance_justifications: ajout seul, modification interdite';
CREATE TRIGGER trg_variance_justifications_no_delete BEFORE DELETE ON variance_justifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'variance_justifications: ajout seul, suppression interdite';
