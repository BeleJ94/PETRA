-- Petra — développé par BELEJ-CONSULTING.
-- Organisations (propriétaires) et stations-service.
-- Les clés étrangères created_by → users sont ajoutées en 0002, une fois la table users créée.
CREATE TABLE organizations (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    legal_name VARCHAR(190) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_org_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stations (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    organization_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(120) NOT NULL,
    city VARCHAR(120) NULL,
    address VARCHAR(255) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Lubumbashi',
    -- Empreinte SHA-256 du jeton de synchronisation (vérifiée par le serveur central) ; jamais le jeton en clair.
    sync_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_sync_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sta_org_code (organization_id, code),
    CONSTRAINT fk_sta_organization FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
