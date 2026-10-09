-- Petra — développé par BELEJ-CONSULTING.
-- Rôles, permissions, utilisateurs et stations attribuées.
CREATE TABLE roles (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(80) NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rol_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(60) NOT NULL,
    description VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_per_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    organization_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Identifiant de connexion : tous les pompistes n'ont pas d'adresse e-mail.
    username VARCHAR(60) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    locale CHAR(2) NOT NULL DEFAULT 'fr',
    -- 1 = accès à toutes les stations de l'organisation (propriétaire) ; sinon voir user_stations.
    all_stations TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usr_username (username),
    UNIQUE KEY uq_usr_email (email),
    CONSTRAINT fk_usr_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
    CONSTRAINT fk_usr_role FOREIGN KEY (role_id) REFERENCES roles (id),
    CONSTRAINT fk_usr_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    permission_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rp_role_permission (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id),
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id),
    CONSTRAINT fk_rp_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_stations (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    station_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_us_user_station (user_id, station_id),
    KEY idx_us_station (station_id),
    CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_us_station FOREIGN KEY (station_id) REFERENCES stations (id),
    CONSTRAINT fk_us_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE organizations ADD CONSTRAINT fk_org_created_by FOREIGN KEY (created_by) REFERENCES users (id);
ALTER TABLE stations ADD CONSTRAINT fk_sta_created_by FOREIGN KEY (created_by) REFERENCES users (id);
ALTER TABLE roles ADD CONSTRAINT fk_rol_created_by FOREIGN KEY (created_by) REFERENCES users (id);
ALTER TABLE permissions ADD CONSTRAINT fk_per_created_by FOREIGN KEY (created_by) REFERENCES users (id);
