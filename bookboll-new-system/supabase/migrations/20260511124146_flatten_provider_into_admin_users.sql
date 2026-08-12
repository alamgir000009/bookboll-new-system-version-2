/*
  # Flatten facility_providers into admin_users

  1. Purpose
    - Eliminate the `facility_providers` table. The provider_admin user IS the business.
    - Every row that previously linked to `facility_providers(id)` now links to the
      provider_admin user's row in `admin_users(id)` via `owner_user_id`.

  2. admin_users changes
    - New columns (all nullable, relevant only for provider_admin users):
      - business_name      text
      - commission_rate    numeric(5,2) default 20
      - license_number     text
      - description        text
      - logo_url           text
      - business_status    text default 'approved'  (pending|approved|rejected)
      - phone              text
      - city_id            uuid references cities(id)
      - owner_user_id      uuid references admin_users(id) on delete set null
        (for employees: points to their provider_admin owner)

  3. Per-table changes (locations, addons, payment_accounts, payouts, provider_wallets,
     wallet_transactions, withdrawal_requests, class_programs, program_approvals,
     provider_trial_agreements)
    - Add `owner_user_id uuid references admin_users(id)`
    - Backfill from provider_id -> provider_admin.id mapping
    - Drop `provider_id` column

  4. Data backfill
    - Copy facility_providers fields onto the matching provider_admin user
    - Set owner_user_id on every employee user to their business's provider_admin.id
    - For every linked table, set owner_user_id using the same mapping

  5. Final
    - Drop `facility_providers` table.

  6. Security
    - RLS policies on the affected tables continue to rely on owner_user_id =
      auth.uid(). Since this app currently uses no auth.uid session in policies,
      existing policies remain unchanged; new column is additive.
*/

-- 1. Extend admin_users ------------------------------------------------------
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='business_name') THEN
    ALTER TABLE admin_users ADD COLUMN business_name text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='commission_rate') THEN
    ALTER TABLE admin_users ADD COLUMN commission_rate numeric(5,2) DEFAULT 20;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='license_number') THEN
    ALTER TABLE admin_users ADD COLUMN license_number text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='description') THEN
    ALTER TABLE admin_users ADD COLUMN description text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='logo_url') THEN
    ALTER TABLE admin_users ADD COLUMN logo_url text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='business_status') THEN
    ALTER TABLE admin_users ADD COLUMN business_status text DEFAULT 'approved';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='phone') THEN
    ALTER TABLE admin_users ADD COLUMN phone text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='city_id') THEN
    ALTER TABLE admin_users ADD COLUMN city_id uuid REFERENCES cities(id) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='owner_user_id') THEN
    ALTER TABLE admin_users ADD COLUMN owner_user_id uuid REFERENCES admin_users(id) ON DELETE SET NULL;
  END IF;
END $$;

-- 2. Backfill admin_users business fields from facility_providers ------------
UPDATE admin_users au
SET business_name    = fp.business_name,
    commission_rate  = fp.commission_rate,
    license_number   = fp.license_number,
    description      = fp.description,
    logo_url         = fp.logo_url,
    business_status  = fp.status,
    phone            = fp.phone,
    city_id          = fp.city_id
FROM facility_providers fp
WHERE au.provider_id = fp.id AND au.role = 'provider_admin';

-- Set owner_user_id on employees: use the provider_admin user with same provider_id
UPDATE admin_users emp
SET owner_user_id = owner.id
FROM admin_users owner
WHERE emp.provider_id = owner.provider_id
  AND owner.role = 'provider_admin'
  AND emp.role IN ('facility_admin','senior_employee','junior_employee','guard','staff')
  AND emp.provider_id IS NOT NULL;

-- 3. Add owner_user_id to all related tables & backfill ----------------------
DO $$
DECLARE
  t text;
  tbls text[] := ARRAY['locations','addons','payment_accounts','payouts',
                       'provider_wallets','wallet_transactions','withdrawal_requests',
                       'class_programs','program_approvals','provider_trial_agreements'];
BEGIN
  FOREACH t IN ARRAY tbls LOOP
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name=t) THEN
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name=t AND column_name='owner_user_id') THEN
        EXECUTE format('ALTER TABLE %I ADD COLUMN owner_user_id uuid REFERENCES admin_users(id) ON DELETE CASCADE', t);
      END IF;
      EXECUTE format('UPDATE %I tgt SET owner_user_id = owner.id FROM admin_users owner WHERE owner.role = ''provider_admin'' AND owner.provider_id = tgt.provider_id AND tgt.owner_user_id IS NULL', t);
    END IF;
  END LOOP;
END $$;

-- 4. Drop old provider_id columns -------------------------------------------
DO $$
DECLARE
  t text;
  tbls text[] := ARRAY['locations','addons','payment_accounts','payouts',
                       'provider_wallets','wallet_transactions','withdrawal_requests',
                       'class_programs','program_approvals','provider_trial_agreements',
                       'admin_users'];
BEGIN
  FOREACH t IN ARRAY tbls LOOP
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name=t AND column_name='provider_id') THEN
      EXECUTE format('ALTER TABLE %I DROP COLUMN provider_id', t);
    END IF;
  END LOOP;
END $$;

-- 5. Drop facility_providers -------------------------------------------------
DROP TABLE IF EXISTS facility_providers CASCADE;

-- 6. Helpful indexes ---------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_admin_users_owner_user_id ON admin_users(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_locations_owner_user_id ON locations(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_payment_accounts_owner_user_id ON payment_accounts(owner_user_id);
