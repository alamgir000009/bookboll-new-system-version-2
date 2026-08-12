/*
  # Add unique constraints to countries and cities

  - countries.iso_code must be unique (prevents duplicate country entries)
  - cities.(country_id, name) must be unique (prevents duplicate city per country)
*/

ALTER TABLE countries ADD CONSTRAINT countries_iso_code_key UNIQUE (iso_code);
ALTER TABLE cities    ADD CONSTRAINT cities_country_id_name_key UNIQUE (country_id, name);
