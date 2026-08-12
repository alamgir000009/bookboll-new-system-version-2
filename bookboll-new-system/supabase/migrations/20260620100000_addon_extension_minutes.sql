/*
  # Add-on session extension = extra minutes (not a discount)

  Corrects the add-on extension model: a "booking session extension" add-on simply
  adds extra time to the customer's booking when they buy it (e.g. +30 min). The
  earlier discount-style columns were a mistake and are removed; the next-slot
  Extension Discount lives on the listing itself (listings.extension_pricing).

  Idempotent: safe to re-run.
*/

ALTER TABLE addons ADD COLUMN IF NOT EXISTS extension_minutes integer NOT NULL DEFAULT 0;
ALTER TABLE addons DROP COLUMN IF EXISTS extension_pricing;
ALTER TABLE addons DROP COLUMN IF EXISTS extension_value;
