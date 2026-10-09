-- Petra — développé par BELEJ-CONSULTING.
-- Distributeurs (pompes) et postes en parallèle.
-- 1. Un distributeur porte un code et regroupe ses pistolets ; chaque pistolet sert le produit de sa cuve :
--    un distributeur est donc mono-produit ou multi-produits selon ses pistolets.
-- 2. Un poste ne couvre plus toute la station mais les distributeurs confiés au pompiste : plusieurs postes
--    peuvent être ouverts en même temps, chacun avec ses index et sa caisse.
-- 3. Les horaires de poste deviennent facultatifs (réglage « shift_mode » de la station : fixed ou free) ;
--    l'heure d'ouverture et l'heure de clôture restent toujours enregistrées.
CREATE TABLE pumps (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pmp_station_code (station_id, code),
    CONSTRAINT fk_pmp_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_pmp_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_pumps_sync_insert AFTER INSERT ON pumps FOR EACH ROW IF @petra_sync_apply IS NULL AND COALESCE(NEW.station_id, @petra_station_id) IS NOT NULL THEN INSERT INTO sync_queue (id, station_id, entity_type, entity_id, operation, payload) VALUES (UUID(), COALESCE(NEW.station_id, @petra_station_id), 'pumps', NEW.id, 'insert', '{}'); END IF;
CREATE TRIGGER trg_pumps_sync_update AFTER UPDATE ON pumps FOR EACH ROW IF @petra_sync_apply IS NULL AND COALESCE(NEW.station_id, @petra_station_id) IS NOT NULL THEN INSERT INTO sync_queue (id, station_id, entity_type, entity_id, operation, payload) VALUES (UUID(), COALESCE(NEW.station_id, @petra_station_id), 'pumps', NEW.id, 'update', '{}'); END IF;

-- Le libellé libre « pompe » du pistolet (pump_label) est conservé pour mémoire ; le rattachement se fait par pump_id.
ALTER TABLE nozzles
    ADD COLUMN pump_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER tank_id,
    ADD KEY idx_noz_pump (pump_id),
    ADD CONSTRAINT fk_noz_pump FOREIGN KEY (pump_id) REFERENCES pumps (id);

-- Reprise : chaque libellé de pompe déjà saisi devient un distributeur (D1, D2…) de sa station ; les pistolets
-- sans libellé sont regroupés sur « Distributeur 1 ». Identifiant dérivé de la station et du libellé : la station
-- et le central, qui appliquent tous deux cette migration, créent le même distributeur.
INSERT IGNORE INTO pumps (id, station_id, code, name)
SELECT CONCAT(SUBSTR(x.h, 1, 8), '-', SUBSTR(x.h, 9, 4), '-4', SUBSTR(x.h, 14, 3), '-8', SUBSTR(x.h, 18, 3), '-', SUBSTR(x.h, 21, 12)), x.station_id,
       CONCAT('D', ROW_NUMBER() OVER (PARTITION BY x.station_id ORDER BY x.name)), x.name
FROM (
    SELECT n.station_id, MIN(COALESCE(NULLIF(TRIM(n.pump_label), ''), 'Distributeur 1')) AS name,
           MD5(CONCAT(n.station_id, ':pump:', LOWER(MIN(COALESCE(NULLIF(TRIM(n.pump_label), ''), 'Distributeur 1'))))) AS h
    FROM nozzles n
    GROUP BY n.station_id, LOWER(COALESCE(NULLIF(TRIM(n.pump_label), ''), 'Distributeur 1'))
) x;

UPDATE nozzles n
  JOIN pumps p ON p.station_id = n.station_id AND p.name = COALESCE(NULLIF(TRIM(n.pump_label), ''), 'Distributeur 1')
   SET n.pump_id = p.id
 WHERE n.pump_id IS NULL;

-- Postes en parallèle : la limite « un poste ouvert par station » devient « un poste ouvert par pompiste et par
-- station ». Qu'un distributeur ne soit tenu que par un seul poste ouvert est vérifié à l'ouverture, sous verrou.
ALTER TABLE shifts DROP INDEX uq_shf_one_open, DROP COLUMN open_station_id;
ALTER TABLE shifts
    ADD COLUMN open_key VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin AS (IF(status = 'open', CONCAT(station_id, ':', attendant_id), NULL)) PERSISTENT,
    ADD UNIQUE KEY uq_shf_one_open (open_key);
