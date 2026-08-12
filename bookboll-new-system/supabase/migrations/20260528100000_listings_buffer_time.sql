-- Add buffer_time column to listings
-- buffer_time: gap in minutes between consecutive bookings (0 = no buffer)

ALTER TABLE listings
  ADD COLUMN IF NOT EXISTS buffer_time INTEGER NOT NULL DEFAULT 0;
