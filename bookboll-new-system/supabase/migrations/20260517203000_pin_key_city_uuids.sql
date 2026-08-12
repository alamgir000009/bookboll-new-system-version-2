-- Pin specific UUIDs for cities referenced by hardcoded IDs in later migrations.
DO $$
DECLARE
  c_ae uuid; c_qa uuid; c_eg uuid; c_gb uuid; c_sg uuid; c_sa uuid;
BEGIN
  SELECT id INTO c_ae FROM countries WHERE iso_code = 'AE';
  SELECT id INTO c_qa FROM countries WHERE iso_code = 'QA';
  SELECT id INTO c_eg FROM countries WHERE iso_code = 'EG';
  SELECT id INTO c_gb FROM countries WHERE iso_code = 'GB';
  SELECT id INTO c_sg FROM countries WHERE iso_code = 'SG';
  SELECT id INTO c_sa FROM countries WHERE iso_code = 'SA';

  -- Delete and re-insert with fixed UUIDs (used in 20260517203315, 20260517203501, 20260517213442)
  DELETE FROM cities WHERE (name = 'Dubai'     AND country_id = c_ae)
                        OR (name = 'Abu Dhabi' AND country_id = c_ae)
                        OR (name = 'Doha'      AND country_id = c_qa)
                        OR (name = 'Cairo'     AND country_id = c_eg)
                        OR (name = 'London'    AND country_id = c_gb)
                        OR (name = 'Singapore' AND country_id = c_sg)
                        OR (name = 'Riyadh'    AND country_id = c_sa);

  INSERT INTO cities (id, country_id, name, status) VALUES
    ('cba3f62e-4be0-483a-b8dc-42760c3cc153', c_ae, 'Dubai',     'active'),
    ('9aefa213-8cc9-4d7d-a2e7-2d16d05a0501', c_ae, 'Abu Dhabi', 'active'),
    ('97064dd0-b0a7-4c81-be7e-488bb821a975', c_qa, 'Doha',      'active'),
    ('d128a349-5b01-4b48-8bad-b4c8d5bc475a', c_eg, 'Cairo',     'active'),
    ('76724e31-fba9-474f-be04-7daed7365de8', c_gb, 'London',    'active'),
    ('b2f048da-1eac-4409-9393-d410d1dde861', c_sg, 'Singapore', 'active'),
    ('526658d4-3c0e-49f4-b5ab-e698c6407ef2', c_sa, 'Riyadh',    'active')
  ON CONFLICT (id) DO NOTHING;
END $$;
