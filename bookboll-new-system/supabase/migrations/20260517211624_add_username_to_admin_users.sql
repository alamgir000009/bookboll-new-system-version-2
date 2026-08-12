/*
  # Add username to admin_users

  - Adds a unique `username` text column
  - Backfills existing users with sensible lowercase usernames derived from their names/emails
*/

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS username text;

-- Backfill existing users
UPDATE admin_users SET username = 'superadmin'    WHERE email = 'admin@bookboll.com';
UPDATE admin_users SET username = 'ahmed.mansouri' WHERE email = 'ahmed@dsc.ae';
UPDATE admin_users SET username = 'mahmoud.hassan' WHERE email = 'mahmoud@cfa.eg';
UPDATE admin_users SET username = 'khalid.saud'    WHERE email = 'khalid@rpc.sa';
UPDATE admin_users SET username = 'fatima.rashidi' WHERE email = 'fatima@alwasl.ae';
UPDATE admin_users SET username = 'yusuf.farsi'    WHERE email = 'yusuf@dohaarena.qa';
UPDATE admin_users SET username = 'nadia.kamel'    WHERE email = 'nadia@cairoelite.eg';

ALTER TABLE admin_users ADD CONSTRAINT admin_users_username_unique UNIQUE (username);
