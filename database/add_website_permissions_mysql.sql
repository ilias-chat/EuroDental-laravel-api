-- Permissions Site Web + profil id = 1
-- MySQL / MariaDB

START TRANSACTION;

INSERT INTO permissions (code, label, `group`, created_at, updated_at)
SELECT 'website_creatives_manage', 'Gérer les créatifs du site (accueil, contact, à propos)', 'Site Web', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'website_creatives_manage');

INSERT INTO permissions (code, label, `group`, created_at, updated_at)
SELECT 'website_products_manage', 'Gérer les produits du site web', 'Site Web', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'website_products_manage');

INSERT INTO permissions (code, label, `group`, created_at, updated_at)
SELECT 'website_flipbooks_manage', 'Gérer les publications du site', 'Site Web', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'website_flipbooks_manage');

INSERT INTO permissions (code, label, `group`, created_at, updated_at)
SELECT 'website_settings_manage', 'Gérer les paramètres du site web', 'Site Web', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'website_settings_manage');

INSERT INTO profile_permission (profile_id, permission_id, created_at, updated_at)
SELECT 1, p.id, NOW(), NOW()
FROM permissions p
WHERE p.code IN (
    'website_creatives_manage',
    'website_products_manage',
    'website_flipbooks_manage',
    'website_settings_manage'
)
AND NOT EXISTS (
    SELECT 1
    FROM profile_permission pp
    WHERE pp.profile_id = 1
      AND pp.permission_id = p.id
);

COMMIT;
