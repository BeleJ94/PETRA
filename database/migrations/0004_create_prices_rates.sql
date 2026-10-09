-- Petra — développé par BELEJ-CONSULTING.
-- Prix et taux de change versionnés : une ligne par version, jamais modifiée.
-- La version en vigueur à l'instant T est celle dont effective_at est le plus récent ≤ T.
CREATE TABLE prices (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Prix du litre dans la plus petite unité de la devise (CDF entier, USD en cents).
    unit_price BIGINT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'CDF',
    effective_at DATETIME NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    approved_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pri_product_effective (product_id, effective_at),
    KEY idx_pri_station_effective (station_id, effective_at),
    CONSTRAINT fk_pri_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_pri_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_pri_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_pri_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT chk_pri_price CHECK (unit_price > 0),
    CONSTRAINT chk_pri_currency CHECK (currency IN ('CDF', 'USD'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exchange_rates (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    from_currency CHAR(3) NOT NULL DEFAULT 'USD',
    to_currency CHAR(3) NOT NULL DEFAULT 'CDF',
    -- Nombre d'unités de to_currency (CDF entiers) pour 1 unité entière de from_currency (1 USD).
    rate BIGINT NOT NULL,
    effective_at DATETIME NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    approved_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_xr_station_pair_effective (station_id, from_currency, to_currency, effective_at),
    CONSTRAINT fk_xr_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_xr_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_xr_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT chk_xr_rate CHECK (rate > 0),
    CONSTRAINT chk_xr_pair CHECK (from_currency = 'USD' AND to_currency = 'CDF')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
