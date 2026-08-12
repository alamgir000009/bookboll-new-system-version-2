/*
  # Program setup improvements: joining fee + draft flag

  Two optional columns on class_programs, both with graceful pre-migration fallbacks
  in the editor (writes retry WITHOUT these columns on a missing-column error):

    • joining_fee — a one-off sign-up / joining charge (default 0) taken once when a
      member enrols, on top of the recurring price. The recurring charge is unchanged;
      customer/data.js applies this fee once at enrolment.
    • is_draft    — marks a program saved via "Save draft": persisted with
      status:'inactive' (already hidden from the storefront) so the provider can finish
      it later. A normal Save clears this flag and runs full validation. No new
      customer-visible status is introduced.

  Idempotent: safe to re-run.
*/

ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS joining_fee numeric(12,2) NOT NULL DEFAULT 0;
ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS is_draft boolean NOT NULL DEFAULT false;
