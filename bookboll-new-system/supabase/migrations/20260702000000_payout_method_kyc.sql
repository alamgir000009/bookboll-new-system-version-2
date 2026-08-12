-- ════════════════════════════════════════════════════════════════════════════
-- Payout method KYC + approval lifecycle
-- ════════════════════════════════════════════════════════════════════════════
-- The MANUAL payout method (Bookboll Wallet) now requires the provider to submit
-- a KYC pack (business details + bank account) which Bookboll platform staff must
-- APPROVE before the method goes active and the listing/program restrictions lift.
--
-- Stripe Connect connects straight to active (Stripe runs its own KYC) and pays out
-- automatically at checkout, so it never enters the review queue.
--
-- setup_status lifecycle now:
--   null            – no method chosen
--   active          – live (Stripe connected, OR Manual approved) → restrictions lifted
--   pending_review  – Manual KYC submitted, awaiting Bookboll approval (NOT lifted)
--   rejected        – Manual KYC rejected (NOT lifted; provider can resubmit)
-- (The old transient 'pending' value is no longer produced.)
--
-- KYC fields live on provider_wallets (one method per business, mirrored across that
-- business's country wallets via the account-level fan-out in Wallet.vue).
-- ════════════════════════════════════════════════════════════════════════════

alter table provider_wallets
  add column if not exists kyc_business_name    text,
  add column if not exists kyc_registration_no  text,
  add column if not exists kyc_address          text,
  add column if not exists kyc_contact          text,
  add column if not exists kyc_doc_url          text,
  add column if not exists kyc_submitted_at     timestamptz,
  add column if not exists kyc_reviewed_at      timestamptz,
  add column if not exists kyc_reviewed_by      uuid,
  add column if not exists kyc_rejection_reason text;

-- If setup_status carries a CHECK constraint that whitelists values, relax it so the
-- new states are allowed. (No-op when the column is plain text with no such check.)
do $$
declare c record;
begin
  for c in
    select con.conname
    from pg_constraint con
    join pg_class rel on rel.oid = con.conrelid
    where rel.relname = 'provider_wallets'
      and con.contype = 'c'
      and pg_get_constraintdef(con.oid) ilike '%setup_status%'
  loop
    execute format('alter table provider_wallets drop constraint %I', c.conname);
  end loop;
end $$;

-- Backfill: existing ACTIVE Bookboll wallets are treated as already-approved so
-- current providers are not suddenly re-gated by the new approval requirement.
update provider_wallets
   set kyc_submitted_at = coalesce(kyc_submitted_at, setup_completed_at, now()),
       kyc_reviewed_at  = coalesce(kyc_reviewed_at,  setup_completed_at, now())
 where setup_method = 'bookboll'
   and setup_status = 'active';
