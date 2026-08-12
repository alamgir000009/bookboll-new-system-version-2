/*
  # SUPERSEDED — do not run

  This migration was written under an earlier "providers are locked to deposit-only"
  model and would overwrite a listing's admin-chosen methods (e.g. turn OFF online on a
  provider venue). The model is now "Bookboll admin can enable any combination of
  methods per listing", so forcing values here is wrong.

  Use 20260605150000_payment_options_public_read.sql instead (adds the anon read policy
  and seeds defaults ONLY for listings that have no payment row yet).

  Intentionally a no-op.
*/
SELECT 1;
