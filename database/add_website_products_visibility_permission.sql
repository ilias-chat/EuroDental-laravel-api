-- Permission visibilité produits site web + profil id = 1
-- MySQL / MariaDB

START TRANSACTION;

INSERT INTO permissions (code, label, `group`, created_at, updated_at)
SELECT 'website_products_visibility', 'Gérer la visibilité et l''affichage du prix des produits', 'Le site', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'website_products_visibility');

INSERT INTO profile_permission (profile_id, permission_id, created_at, updated_at)
SELECT 1, p.id, NOW(), NOW()
FROM permissions p
WHERE p.code = 'website_products_visibility'
AND NOT EXISTS (
    SELECT 1
    FROM profile_permission pp
    WHERE pp.profile_id = 1
      AND pp.permission_id = p.id
);

COMMIT;
