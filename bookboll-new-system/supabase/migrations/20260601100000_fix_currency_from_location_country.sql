-- ── Fix currencies to follow each provider's actual country ──────────────────
-- Root cause: provider admin_users had NULL country_id, and several flows derived
-- currency from that (falling back to 'AED'). The reliable source of truth is
-- location → city → country → currency_code. This migration backfills country_id
-- and re-derives every stored currency from the owning location's country.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Backfill provider country_id from the country of any location they own
UPDATE admin_users au
SET country_id = sub.country_id
FROM (
  SELECT DISTINCT ON (l.owner_user_id) l.owner_user_id, ci.country_id
  FROM locations l
  JOIN cities ci ON ci.id = l.city_id
  WHERE l.owner_user_id IS NOT NULL AND ci.country_id IS NOT NULL
  ORDER BY l.owner_user_id, l.created_at
) sub
WHERE au.id = sub.owner_user_id
  AND au.country_id IS NULL;

-- 1b. Backfill scoped staff (facility_admin/employees/guard) from their provider
UPDATE admin_users emp
SET country_id = owner.country_id
FROM admin_users owner
WHERE emp.owner_user_id = owner.id
  AND owner.country_id IS NOT NULL
  AND emp.country_id IS NULL;

-- 2. Re-derive class program currency from its location's country
UPDATE class_programs cp
SET currency = co.currency_code
FROM locations l
JOIN cities ci   ON ci.id = l.city_id
JOIN countries co ON co.id = ci.country_id
WHERE cp.location_id = l.id
  AND co.currency_code IS NOT NULL
  AND cp.currency IS DISTINCT FROM co.currency_code;

-- 3. Re-derive provider wallet currency from the owner's location country
UPDATE provider_wallets pw
SET currency = sub.currency_code
FROM (
  SELECT DISTINCT ON (l.owner_user_id) l.owner_user_id, co.currency_code
  FROM locations l
  JOIN cities ci   ON ci.id = l.city_id
  JOIN countries co ON co.id = ci.country_id
  WHERE l.owner_user_id IS NOT NULL
  ORDER BY l.owner_user_id, l.created_at
) sub
WHERE pw.owner_user_id = sub.owner_user_id
  AND pw.currency IS DISTINCT FROM sub.currency_code;

-- 4. Re-derive wallet transaction currency from the owner's location country
UPDATE wallet_transactions wt
SET currency = sub.currency_code
FROM (
  SELECT DISTINCT ON (l.owner_user_id) l.owner_user_id, co.currency_code
  FROM locations l
  JOIN cities ci   ON ci.id = l.city_id
  JOIN countries co ON co.id = ci.country_id
  WHERE l.owner_user_id IS NOT NULL
  ORDER BY l.owner_user_id, l.created_at
) sub
WHERE wt.owner_user_id = sub.owner_user_id
  AND wt.currency IS DISTINCT FROM sub.currency_code;
