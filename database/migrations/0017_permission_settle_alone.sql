-- Petra — développé par BELEJ-CONSULTING.
-- Clôture totale d'un poste par une seule personne : index finaux, caisse et validation en une opération.
-- Cette permission lève, pour qui la détient, la règle des deux personnes (le poste est validé par une autre
-- personne que celle qui l'a tenu ou clôturé). Elle est donnée au seul Propriétaire ; l'opération est inscrite
-- au journal d'audit comme validation faite seul.
INSERT INTO permissions (id, code, description) VALUES
    ('00000000-0000-4000-8000-000000000126', 'shifts.settle_alone', 'Clôturer totalement un poste seul (index, caisse et validation)');

INSERT INTO role_permissions (id, role_id, permission_id)
SELECT CONCAT('00000000-0000-4000-8001-', RIGHT(r.id, 6), RIGHT(p.id, 6)), r.id, p.id
FROM roles r JOIN permissions p WHERE r.code = 'owner' AND p.code = 'shifts.settle_alone';
