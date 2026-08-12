-- Extend special_days table
-- listing_id: NULL = applies to all listings at the location
--             non-NULL = applies to a specific listing only
--             For custom schedules (type = 'custom'), listing_id should always be set
-- open_time / close_time: for custom schedules that override the day's hours
-- custom_price: for custom schedules that override the listing's base price

ALTER TABLE special_days
  ADD COLUMN IF NOT EXISTS listing_id   UUID REFERENCES listings(id) ON DELETE CASCADE,
  ADD COLUMN IF NOT EXISTS open_time    TEXT,
  ADD COLUMN IF NOT EXISTS close_time   TEXT,
  ADD COLUMN IF NOT EXISTS custom_price NUMERIC;

-- Index for quick lookups by listing
CREATE INDEX IF NOT EXISTS special_days_listing_idx ON special_days (listing_id);
