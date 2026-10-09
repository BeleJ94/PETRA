-- Petra — développé par BELEJ-CONSULTING.
-- Jaugeage : le stock théorique et l'écart constaté sont figés sur la ligne, comme le volume lu au barème.
-- NULL pour le premier jaugeage d'une cuve : il sert de stock initial, sans écart.
ALTER TABLE tank_dips
    ADD COLUMN theoretical_l DECIMAL(12,2) NULL AFTER water_cm,
    ADD COLUMN variance_l DECIMAL(12,2) NULL AFTER theoretical_l,
    -- Valeur de l'écart en CDF, au prix en vigueur à l'instant du jaugeage (négatif : manquant).
    ADD COLUMN variance_amount BIGINT NULL AFTER variance_l,
    ADD COLUMN currency CHAR(3) NULL AFTER variance_amount;
ALTER TABLE tank_dips ADD CONSTRAINT chk_td_variance CHECK ((variance_amount IS NULL AND currency IS NULL) OR (variance_amount IS NOT NULL AND currency = 'CDF'));
