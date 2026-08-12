-- Allow promocodes to target a business's Bookboll Premium plan upgrade ($29/mo),
-- in addition to bookings ('facility') and memberships ('program').
-- These are super-admin-only, always Bookboll-fee codes; validated at upgrade time
-- with ctx.applicableTo === 'provider_plan' (see src/lib/promocodes.js).
--
-- The applicable_to CHECK constraint was created by an earlier migration; its name
-- may vary, so drop whatever check references applicable_to, then re-add it widened.

DO $$
DECLARE cname text;
BEGIN
  SELECT conname INTO cname
    FROM pg_constraint
   WHERE conrelid = 'promocodes'::regclass
     AND contype = 'c'
     AND pg_get_constraintdef(oid) ILIKE '%applicable_to%'
   LIMIT 1;
  IF cname IS NOT NULL THEN
    EXECUTE format('ALTER TABLE promocodes DROP CONSTRAINT %I', cname);
  END IF;
END $$;

ALTER TABLE promocodes
  ADD CONSTRAINT promocodes_applicable_to_check
  CHECK (applicable_to IN ('all', 'facility', 'program', 'provider_plan'));

-- promocode_redemptions.source_type is free text (default ''), so 'provider_plan'
-- redemptions need no schema change.
