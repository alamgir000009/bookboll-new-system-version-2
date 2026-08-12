/*
  # Add approval, eligibility, trial agreement to class_programs and trial lifecycle to class_subscriptions

  ## Summary
  Brings class_programs into the same approval/edit/pause flow used by locations
  and listings, and adds fields to support the mandatory 14-day free trial for
  subscribers. Also introduces a provider_trial_agreements audit table to record
  each time a provider accepts the trial terms.

  ## New Columns

  ### class_programs
  - `approval_status` (text, default 'approved') — 'pending' | 'approved'
  - `eligibility_status` (text, default 'eligible') — 'eligible' | 'paused'
  - `admin_note` (text) — reason written by admin when pausing
  - `previous_snapshot` (jsonb) — snapshot of row values before the latest edit
  - `free_trial_days` (int, default 14)
  - `free_trial_enabled` (bool, default true)
  - `trial_terms_accepted_at` (timestamptz)
  - `trial_terms_version` (text)

  ### class_subscriptions
  - `trial_ends_at` (date)
  - `trial_status` (text, default 'in_trial') — 'in_trial' | 'converted' | 'cancelled_in_trial'
  - `card_on_file_id` (text)
  - `first_charge_at` (timestamptz)
  - `cancelled_during_trial_at` (timestamptz)

  ## New Tables
  - `provider_trial_agreements` — audit of every provider acceptance of trial terms

  ## Security
  - RLS enabled on provider_trial_agreements with authenticated-only access
*/

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='approval_status') THEN
    ALTER TABLE class_programs ADD COLUMN approval_status text NOT NULL DEFAULT 'approved';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='eligibility_status') THEN
    ALTER TABLE class_programs ADD COLUMN eligibility_status text NOT NULL DEFAULT 'eligible';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='admin_note') THEN
    ALTER TABLE class_programs ADD COLUMN admin_note text NOT NULL DEFAULT '';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='previous_snapshot') THEN
    ALTER TABLE class_programs ADD COLUMN previous_snapshot jsonb;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='free_trial_days') THEN
    ALTER TABLE class_programs ADD COLUMN free_trial_days integer NOT NULL DEFAULT 14;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='free_trial_enabled') THEN
    ALTER TABLE class_programs ADD COLUMN free_trial_enabled boolean NOT NULL DEFAULT true;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='trial_terms_accepted_at') THEN
    ALTER TABLE class_programs ADD COLUMN trial_terms_accepted_at timestamptz;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='trial_terms_version') THEN
    ALTER TABLE class_programs ADD COLUMN trial_terms_version text DEFAULT '';
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='trial_ends_at') THEN
    ALTER TABLE class_subscriptions ADD COLUMN trial_ends_at date;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='trial_status') THEN
    ALTER TABLE class_subscriptions ADD COLUMN trial_status text NOT NULL DEFAULT 'in_trial';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='card_on_file_id') THEN
    ALTER TABLE class_subscriptions ADD COLUMN card_on_file_id text DEFAULT '';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='first_charge_at') THEN
    ALTER TABLE class_subscriptions ADD COLUMN first_charge_at timestamptz;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='cancelled_during_trial_at') THEN
    ALTER TABLE class_subscriptions ADD COLUMN cancelled_during_trial_at timestamptz;
  END IF;
END $$;

CREATE TABLE IF NOT EXISTS provider_trial_agreements (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  program_id uuid REFERENCES class_programs(id) ON DELETE CASCADE,
  provider_id uuid REFERENCES facility_providers(id) ON DELETE CASCADE,
  accepted_at timestamptz NOT NULL DEFAULT now(),
  terms_version text NOT NULL DEFAULT '2026-05-11-v1',
  commission_at_acceptance numeric(5,2) NOT NULL DEFAULT 20,
  trial_days_at_acceptance integer NOT NULL DEFAULT 14,
  accepted_by uuid
);

ALTER TABLE provider_trial_agreements ENABLE ROW LEVEL SECURITY;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename='provider_trial_agreements' AND policyname='auth can read agreements') THEN
    CREATE POLICY "auth can read agreements" ON provider_trial_agreements FOR SELECT TO authenticated USING (true);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename='provider_trial_agreements' AND policyname='auth can insert agreements') THEN
    CREATE POLICY "auth can insert agreements" ON provider_trial_agreements FOR INSERT TO authenticated WITH CHECK (true);
  END IF;
END $$;
