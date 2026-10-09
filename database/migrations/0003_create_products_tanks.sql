-- Petra — développé par BELEJ-CONSULTING.
-- Installation d'une station : produits, cuves, barèmes de jaugeage, pistolets.
CREATE TABLE products (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pro_station_code (station_id, code),
    CONSTRAINT fk_pro_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_pro_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tanks (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(80) NOT NULL,
    capacity_l DECIMAL(12,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tnk_station_code (station_id, code),
    CONSTRAINT fk_tnk_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_tnk_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_tnk_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_tnk_capacity CHECK (capacity_l > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Barème de jaugeage : hauteur mesurée à la jauge (cm) → volume en cuve (litres).
CREATE TABLE tank_calibrations (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tank_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    depth_cm DECIMAL(6,1) NOT NULL,
    volume_l DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tc_tank_depth (tank_id, depth_cm),
    KEY idx_tc_station (station_id),
    CONSTRAINT fk_tc_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_tc_tank FOREIGN KEY (tank_id) REFERENCES tanks (id),
    CONSTRAINT fk_tc_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_tc_values CHECK (depth_cm >= 0 AND volume_l >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pistolet : point de vente relié à une cuve (donc à un produit), porteur d'un index totalisateur.
CREATE TABLE nozzles (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tank_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(80) NOT NULL,
    pump_label VARCHAR(40) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_noz_station_code (station_id, code),
    CONSTRAINT fk_noz_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_noz_tank FOREIGN KEY (tank_id) REFERENCES tanks (id),
    CONSTRAINT fk_noz_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
