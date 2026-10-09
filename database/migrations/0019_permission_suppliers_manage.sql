-- Petra — développé par BELEJ-CONSULTING.
-- Tenue du répertoire des fournisseurs : créer une fiche, la corriger, la désactiver.
-- Donnée au Propriétaire et au Gérant : un nouveau fournisseur doit pouvoir être ajouté sur place, le jour où
-- son camion se présente. Chaque création ou modification est inscrite au journal d'audit.
INSERT INTO permissions (id, code, description) VALUES
    ('00000000-0000-4000-8000-000000000127', 'suppliers.manage', 'Gérer le répertoire des fournisseurs');

INSERT INTO role_permissions (id, role_id, permission_id)
SELECT CONCAT('00000000-0000-4000-8001-', RIGHT(r.id, 6), RIGHT(p.id, 6)), r.id, p.id
FROM roles r JOIN permissions p WHERE r.code IN ('owner', 'manager') AND p.code = 'suppliers.manage';
