-- Petra — développé par BELEJ-CONSULTING.
-- Répertoire des fournisseurs de carburant, par station (comme les produits et les cuves).
-- À la réception d'une livraison, le fournisseur se choisit dans ce répertoire : plus de nom saisi à la main,
-- donc plus de doublons « SEP Congo » / « Sep-Congo » dans les rapports.
-- La livraison garde le nom du fournisseur tel qu'il était à la réception (supplier_name) : renommer une fiche
-- ne réécrit pas l'historique (ajout seul).
CREATE TABLE suppliers (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(160) NULL,
    address VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sup_station_name (station_id, name),
    CONSTRAINT fk_sup_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_sup_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les livraisons d'avant le répertoire gardent supplier_id vide : elles sont en ajout seul et ne sont pas réécrites.
ALTER TABLE deliveries
    ADD COLUMN supplier_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER tank_id,
    ADD KEY idx_del_supplier (supplier_id),
    ADD CONSTRAINT fk_del_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id);

-- Synchronisation : le répertoire est un état de la station, renvoyé au central à chaque changement.
CREATE TRIGGER trg_suppliers_sync_insert AFTER INSERT ON suppliers FOR EACH ROW IF @petra_sync_apply IS NULL AND COALESCE(NEW.station_id, @petra_station_id) IS NOT NULL THEN INSERT INTO sync_queue (id, station_id, entity_type, entity_id, operation, payload) VALUES (UUID(), COALESCE(NEW.station_id, @petra_station_id), 'suppliers', NEW.id, 'insert', '{}'); END IF;
CREATE TRIGGER trg_suppliers_sync_update AFTER UPDATE ON suppliers FOR EACH ROW IF @petra_sync_apply IS NULL AND COALESCE(NEW.station_id, @petra_station_id) IS NOT NULL THEN INSERT INTO sync_queue (id, station_id, entity_type, entity_id, operation, payload) VALUES (UUID(), COALESCE(NEW.station_id, @petra_station_id), 'suppliers', NEW.id, 'update', '{}'); END IF;

-- Reprise : chaque nom déjà saisi sur une livraison devient une fiche du répertoire de sa station.
-- L'identifiant est dérivé de la station et du nom : la station et le central, qui appliquent tous deux cette
-- migration, créent ainsi la même fiche et non deux fiches concurrentes.
INSERT IGNORE INTO suppliers (id, station_id, name)
SELECT CONCAT(SUBSTR(x.h, 1, 8), '-', SUBSTR(x.h, 9, 4), '-4', SUBSTR(x.h, 14, 3), '-8', SUBSTR(x.h, 18, 3), '-', SUBSTR(x.h, 21, 12)), x.station_id, x.name
FROM (
    SELECT d.station_id, MIN(TRIM(d.supplier_name)) AS name, MD5(CONCAT(d.station_id, ':', LOWER(MIN(TRIM(d.supplier_name))))) AS h
    FROM deliveries d
    WHERE TRIM(d.supplier_name) <> ''
    GROUP BY d.station_id, LOWER(TRIM(d.supplier_name))
) x;
