/*
  # Link Supabase Auth users to admin_users

  Adds auth_user_id (the Supabase auth.users UUID) to admin_users so that after
  login we can look up the correct admin row using auth.uid().

  Also adds business_status column for provider approval tracking if not present.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'admin_users' AND column_name = 'auth_user_id'
  ) THEN
    ALTER TABLE admin_users ADD COLUMN auth_user_id uuid UNIQUE;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'admin_users' AND column_name = 'password_set'
  ) THEN
    ALTER TABLE admin_users ADD COLUMN password_set boolean DEFAULT false;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_admin_users_auth_user_id ON admin_users(auth_user_id);
CREATE INDEX IF NOT EXISTS idx_admin_users_email ON admin_users(email);
