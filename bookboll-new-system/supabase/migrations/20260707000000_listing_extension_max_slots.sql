/*
  # Session-extension discount: cap the discounted chain length

  Adds a per-listing limit for how many ADJACENT slots after the first can be
  discounted as an "extension" within one contiguous run.

  - extension_max_slots : integer, default 1
      e.g. 1 → only the single slot right after the booking is discounted; the
      next one (and beyond) is charged at full price. Increase to discount a
      longer chain (2 = two extra slots discounted, etc.).

  Additive with a safe default, so existing rows/code keep working unchanged.
*/

ALTER TABLE listings ADD COLUMN IF NOT EXISTS extension_max_slots integer NOT NULL DEFAULT 1;
