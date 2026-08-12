/*
  # Add billing_interval to class_programs

  New column:
    - `billing_interval` text NOT NULL DEFAULT 'monthly'
      Allowed values: 'weekly' | 'biweekly' | 'monthly' | 'quarterly' | 'yearly'

  Also renames the column alias in the app — the DB column stays `monthly_price`
  but represents the "price per billing interval" going forward.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'class_programs' AND column_name = 'billing_interval'
  ) THEN
    ALTER TABLE class_programs ADD COLUMN billing_interval text NOT NULL DEFAULT 'monthly';
  END IF;
END $$;
