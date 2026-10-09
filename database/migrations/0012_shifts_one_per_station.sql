-- Petra — développé par BELEJ-CONSULTING.
-- Postes : un seul poste ouvert par station (et non plus par pompiste), nom du poste (Matin, Après-midi…),
-- passage à zéro des compteurs.

-- Un seul poste ouvert par station : la colonne vaut NULL dès que le poste est clôturé.
ALTER TABLE shifts DROP INDEX uq_shf_one_open, DROP COLUMN open_attendant_id;
ALTER TABLE shifts
    ADD COLUMN label VARCHAR(40) NULL AFTER business_date,
    ADD COLUMN open_station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin AS (IF(status = 'open', station_id, NULL)) PERSISTENT,
    ADD UNIQUE KEY uq_shf_one_open (open_station_id);

-- La clôture d'un poste porte sur ses index ; l'argent remis et l'écart s'ajoutent avec la remise de caisse.
ALTER TABLE shifts DROP CONSTRAINT chk_shf_closed;
ALTER TABLE shifts ADD CONSTRAINT chk_shf_closed CHECK (status = 'open' OR (closed_at IS NOT NULL AND closed_by IS NOT NULL));

-- Index auquel le totalisateur du pistolet revient à zéro (1 000 000,00 : compteur à six chiffres).
ALTER TABLE nozzles ADD COLUMN rollover_index DECIMAL(12,2) NOT NULL DEFAULT 1000000.00 AFTER pump_label;
ALTER TABLE nozzles ADD CONSTRAINT chk_noz_rollover CHECK (rollover_index > 0);

-- Relevé de fermeture inférieur à l'ouverture parce que le compteur est repassé par zéro : déclaré à la saisie.
ALTER TABLE meter_readings ADD COLUMN is_rollover TINYINT(1) NOT NULL DEFAULT 0 AFTER index_value;
