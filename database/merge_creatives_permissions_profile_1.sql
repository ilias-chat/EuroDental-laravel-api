-- Si vous aviez déjà exécuté l'ancien script (3 permissions créatifs),
-- exécutez ceci pour ajouter la permission unique + profil 1

START TRANSACTION;

INSERT INTO permissions (code, label, `group`, created_at, updated_at)
SELECT 'website_creatives_manage', 'Gérer les créatifs du site (accueil, contact, à propos)', 'Site Web', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'website_creatives_manage');

INSERT INTO profile_permission (profile_id, permission_id, created_at, updated_at)
SELECT 1, p.id, NOW(), NOW()
FROM permissions p
WHERE p.code = 'website_creatives_manage'
AND NOT EXISTS (
    SELECT 1 FROM profile_permission pp
    WHERE pp.profile_id = 1 AND pp.permission_id = p.id
);

COMMIT;
