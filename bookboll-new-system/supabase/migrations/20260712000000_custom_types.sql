/*
  # Custom types catalog — provider-suggested listing/program types

  When a provider creates a venue listing or a membership program and the TYPE they need
  isn't in the built-in list (e.g. "Padel"), they can add it inline: they enter a name and
  pick ONE existing category. The type is stored on their listing/program immediately, and a
  `pending` row is recorded here. When that listing/program is APPROVED, the type is promoted
  to `approved` and becomes selectable for every provider (it joins the shared catalog).

  - domain   : 'venue'  (listings)  | 'program' (class_programs)
  - category : a category KEY from the matching taxonomy
               venue   → sports | hangout | work     (venueCategories.js)
               program → gym | class | academy        (membershipCategories.js)
  - status   : pending | approved | rejected

  RLS: anon reads approved (catalog is public-safe); authenticated has full CRUD (providers
  add pending; admins approve). Idempotent: safe to re-run.
*/

CREATE TABLE IF NOT EXISTS custom_types (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  domain       text NOT NULL,                       -- 'venue' | 'program'
  name         text NOT NULL,                       -- e.g. 'Padel'
  category     text NOT NULL,                       -- category key within the domain
  status       text NOT NULL DEFAULT 'pending',     -- pending | approved | rejected
  created_by   uuid REFERENCES admin_users(id) ON DELETE SET NULL,
  created_at   timestamptz NOT NULL DEFAULT now(),
  approved_at  timestamptz
);

CREATE INDEX IF NOT EXISTS idx_custom_types_domain_status ON custom_types(domain, status);
CREATE INDEX IF NOT EXISTS idx_custom_types_lookup ON custom_types(domain, category, lower(name), status);

ALTER TABLE custom_types ENABLE ROW LEVEL SECURITY;

DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'custom_types' AND policyname = 'custom_types_anon_read') THEN
    CREATE POLICY custom_types_anon_read ON custom_types FOR SELECT TO anon USING (status = 'approved');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'custom_types' AND policyname = 'custom_types_auth_read') THEN
    CREATE POLICY custom_types_auth_read   ON custom_types FOR SELECT TO authenticated USING (true);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'custom_types' AND policyname = 'custom_types_auth_insert') THEN
    CREATE POLICY custom_types_auth_insert ON custom_types FOR INSERT TO authenticated WITH CHECK (true);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'custom_types' AND policyname = 'custom_types_auth_update') THEN
    CREATE POLICY custom_types_auth_update ON custom_types FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'custom_types' AND policyname = 'custom_types_auth_delete') THEN
    CREATE POLICY custom_types_auth_delete ON custom_types FOR DELETE TO authenticated USING (true);
  END IF;
END $$;
