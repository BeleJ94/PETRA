-- Petra — développé par BELEJ-CONSULTING.
-- Référentiel des rôles et permissions. Identifiants fixes : identiques sur toutes les stations et au central.
INSERT INTO roles (id, code, name, is_system) VALUES
    ('00000000-0000-4000-8000-000000000001', 'owner', 'Propriétaire', 1),
    ('00000000-0000-4000-8000-000000000002', 'manager', 'Gérant', 1),
    ('00000000-0000-4000-8000-000000000003', 'attendant', 'Pompiste', 1);

INSERT INTO permissions (id, code, description) VALUES
    ('00000000-0000-4000-8000-000000000101', 'dashboard.view', 'Voir le tableau de bord'),
    ('00000000-0000-4000-8000-000000000102', 'organization.manage', 'Gérer l''organisation'),
    ('00000000-0000-4000-8000-000000000103', 'stations.manage', 'Gérer les stations'),
    ('00000000-0000-4000-8000-000000000104', 'catalog.manage', 'Gérer produits, cuves, barèmes et pistolets'),
    ('00000000-0000-4000-8000-000000000105', 'users.manage', 'Gérer les utilisateurs et leurs stations'),
    ('00000000-0000-4000-8000-000000000106', 'prices.view', 'Consulter les prix et les taux'),
    ('00000000-0000-4000-8000-000000000107', 'prices.manage', 'Publier une nouvelle version de prix'),
    ('00000000-0000-4000-8000-000000000108', 'rates.manage', 'Publier un nouveau taux USD/CDF'),
    ('00000000-0000-4000-8000-000000000109', 'shifts.view', 'Consulter les postes'),
    ('00000000-0000-4000-8000-000000000110', 'shifts.open', 'Ouvrir un poste'),
    ('00000000-0000-4000-8000-000000000111', 'shifts.record', 'Saisir les relevés et opérations de son poste'),
    ('00000000-0000-4000-8000-000000000112', 'shifts.close', 'Clôturer un poste et recevoir la caisse'),
    ('00000000-0000-4000-8000-000000000113', 'shifts.validate', 'Valider un poste clôturé'),
    ('00000000-0000-4000-8000-000000000114', 'deliveries.record', 'Enregistrer une livraison'),
    ('00000000-0000-4000-8000-000000000115', 'dips.record', 'Enregistrer un jaugeage de cuve'),
    ('00000000-0000-4000-8000-000000000116', 'expenses.record', 'Enregistrer une dépense de caisse'),
    ('00000000-0000-4000-8000-000000000117', 'credit.record', 'Enregistrer une vente à crédit'),
    ('00000000-0000-4000-8000-000000000118', 'corrections.request', 'Saisir la correction d''une opération'),
    ('00000000-0000-4000-8000-000000000119', 'corrections.approve', 'Approuver une correction'),
    ('00000000-0000-4000-8000-000000000120', 'alerts.view', 'Consulter les alertes'),
    ('00000000-0000-4000-8000-000000000121', 'alerts.resolve', 'Traiter une alerte'),
    ('00000000-0000-4000-8000-000000000122', 'reports.view', 'Consulter les rapports'),
    ('00000000-0000-4000-8000-000000000123', 'audit.view', 'Consulter le journal d''audit'),
    ('00000000-0000-4000-8000-000000000124', 'settings.manage', 'Régler les seuils d''une station'),
    ('00000000-0000-4000-8000-000000000125', 'sync.manage', 'Suivre et relancer la synchronisation');

-- Propriétaire : toutes les permissions.
INSERT INTO role_permissions (id, role_id, permission_id)
SELECT CONCAT('00000000-0000-4000-8001-', RIGHT(r.id, 6), RIGHT(p.id, 6)), r.id, p.id
FROM roles r JOIN permissions p WHERE r.code = 'owner';

-- Gérant : exploitation quotidienne de ses stations. Ne fixe ni prix ni taux, n'approuve pas les corrections.
INSERT INTO role_permissions (id, role_id, permission_id)
SELECT CONCAT('00000000-0000-4000-8001-', RIGHT(r.id, 6), RIGHT(p.id, 6)), r.id, p.id
FROM roles r JOIN permissions p WHERE r.code = 'manager' AND p.code IN (
    'dashboard.view', 'prices.view', 'shifts.view', 'shifts.open', 'shifts.record', 'shifts.close', 'shifts.validate',
    'deliveries.record', 'dips.record', 'expenses.record', 'credit.record', 'corrections.request',
    'alerts.view', 'alerts.resolve', 'reports.view'
);

-- Pompiste : son poste uniquement.
INSERT INTO role_permissions (id, role_id, permission_id)
SELECT CONCAT('00000000-0000-4000-8001-', RIGHT(r.id, 6), RIGHT(p.id, 6)), r.id, p.id
FROM roles r JOIN permissions p WHERE r.code = 'attendant' AND p.code IN ('prices.view', 'shifts.view', 'shifts.record');
