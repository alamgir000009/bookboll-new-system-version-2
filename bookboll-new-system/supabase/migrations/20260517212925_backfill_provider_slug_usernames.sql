/*
  # Backfill slug usernames for provider admins and super admin

  - Replaces dot-format usernames (e.g. ahmed.mansouri) with short company slugs
  - Assigns @bookboll username to the super admin row
  - Sets username on all team members to match their provider's username
*/

-- Update provider admins with short company slugs
UPDATE admin_users SET username = 'dsc'        WHERE email = 'ahmed@dsc.ae'         AND role = 'provider_admin';
UPDATE admin_users SET username = 'cfa'        WHERE email = 'mahmoud@cfa.eg'        AND role = 'provider_admin';
UPDATE admin_users SET username = 'rpc'        WHERE email = 'khalid@rpc.sa'         AND role = 'provider_admin';
UPDATE admin_users SET username = 'alwasl'     WHERE email = 'fatima@alwasl.ae'      AND role = 'provider_admin';
UPDATE admin_users SET username = 'dohaarena'  WHERE email = 'yusuf@dohaarena.qa'    AND role = 'provider_admin';
UPDATE admin_users SET username = 'cairoelite' WHERE email = 'nadia@cairoelite.eg'   AND role = 'provider_admin';

-- Assign bookboll username to super admin
UPDATE admin_users SET username = 'bookboll' WHERE email = 'admin@bookboll.com' AND role = 'super_admin';

-- Propagate provider username to all their team members
UPDATE admin_users emp
SET username = owner.username
FROM admin_users owner
WHERE emp.owner_user_id = owner.id
  AND owner.role = 'provider_admin'
  AND owner.username IS NOT NULL;
