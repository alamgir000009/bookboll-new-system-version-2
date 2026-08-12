-- ─────────────────────────────────────────────────────────────────────────────
-- PRE-SEED: Insert key cities with specific UUIDs BEFORE the world seed runs.
-- The world seed uses ON CONFLICT (country_id, name) DO NOTHING, so these rows
-- will be preserved with their correct UUIDs when the full seed runs after.
-- ─────────────────────────────────────────────────────────────────────────────

DO $$
DECLARE
  c_ae uuid := (SELECT id FROM countries WHERE iso_code = 'AE' LIMIT 1);
  c_qa uuid := (SELECT id FROM countries WHERE iso_code = 'QA' LIMIT 1);
  c_eg uuid := (SELECT id FROM countries WHERE iso_code = 'EG' LIMIT 1);
  c_gb uuid := (SELECT id FROM countries WHERE iso_code = 'GB' LIMIT 1);
  c_sg uuid := (SELECT id FROM countries WHERE iso_code = 'SG' LIMIT 1);
BEGIN
  INSERT INTO cities (id, country_id, name, status) VALUES
    -- UAE
    ('cba3f62e-4be0-483a-b8dc-42760c3cc153', c_ae, 'Dubai',     'active'),
    ('9aefa213-8cc9-4d7d-a2e7-2d16d05a0501', c_ae, 'Abu Dhabi', 'active'),
    -- Qatar
    ('97064dd0-b0a7-4c81-be7e-488bb821a975', c_qa, 'Doha',      'active'),
    -- Egypt
    ('d128a349-5b01-4b48-8bad-b4c8d5bc475a', c_eg, 'Cairo',     'active'),
    -- UK
    ('76724e31-fba9-474f-be04-7daed7365de8', c_gb, 'London',    'active'),
    -- Singapore
    ('b2f048da-1eac-4409-9393-d410d1dde861', c_sg, 'Singapore', 'active')
  ON CONFLICT (country_id, name) DO NOTHING;
END $$;
