-- Petra — développé par BELEJ-CONSULTING.
-- Réception des livraisons : quantité commandée, écarts figés à la saisie, photo du bon.
-- invoiced_volume_l reste la quantité annoncée par le bon de livraison.
ALTER TABLE deliveries
    ADD COLUMN ordered_volume_l DECIMAL(12,2) NULL AFTER driver_name,
    -- Écart annoncé / reçu = reçu − annoncé (négatif : livré moins qu'annoncé).
    ADD COLUMN announced_variance_l DECIMAL(12,2) NULL AFTER received_volume_l,
    -- Écart reçu / cuve = (jaugeage après − jaugeage avant) − reçu (négatif : la cuve a moins monté que le reçu déclaré).
    ADD COLUMN tank_variance_l DECIMAL(12,2) NULL AFTER announced_variance_l,
    -- Chemin relatif sous storage/uploads/{station_id}/ ; jamais servi directement par le serveur web.
    ADD COLUMN photo_path VARCHAR(255) NULL AFTER note;
ALTER TABLE deliveries ADD CONSTRAINT chk_del_ordered CHECK (ordered_volume_l IS NULL OR ordered_volume_l > 0);
