-- Customer blocks table
-- Supports granular blocking: platform-wide, per-location, per-listing, per-program

CREATE TABLE IF NOT EXISTS customer_blocks (
  id          UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id UUID        NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  block_type  TEXT        NOT NULL CHECK (block_type IN ('platform', 'location', 'listing', 'program')),
  target_id   UUID,                          -- NULL for platform blocks
  reason      TEXT        NOT NULL DEFAULT '',
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Prevent duplicate blocks (same customer + same scope + same target)
CREATE UNIQUE INDEX IF NOT EXISTS customer_blocks_unique
  ON customer_blocks (customer_id, block_type, COALESCE(target_id, '00000000-0000-0000-0000-000000000000'::UUID));

CREATE INDEX IF NOT EXISTS customer_blocks_customer_idx ON customer_blocks (customer_id);

-- Open RLS policy for anon key (matches existing table policies in this project)
ALTER TABLE customer_blocks ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow all operations" ON customer_blocks FOR ALL USING (true) WITH CHECK (true);
