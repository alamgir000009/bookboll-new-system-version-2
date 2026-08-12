/*
  # Blog posts — super-admin managed content for the marketplace storefront

  A simple content hub: the super admin creates/edits/deletes posts in the dashboard; the
  public storefront shows PUBLISHED posts at /blog and /blog/:slug. Body is markdown.

  RLS: the public (anon) sees only published posts; the admin app (authenticated) has full
  CRUD. Idempotent: safe to re-run.
*/

CREATE TABLE IF NOT EXISTS blog_posts (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  title            text NOT NULL,
  slug             text UNIQUE NOT NULL,
  excerpt          text NOT NULL DEFAULT '',
  body             text NOT NULL DEFAULT '',           -- markdown
  cover_image      text NOT NULL DEFAULT '',
  status           text NOT NULL DEFAULT 'draft',       -- draft | published
  author_name      text NOT NULL DEFAULT 'Bookboll',
  tags             text[] NOT NULL DEFAULT '{}',
  meta_title       text NOT NULL DEFAULT '',
  meta_description text NOT NULL DEFAULT '',
  published_at     timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_blog_posts_status_pub ON blog_posts(status, published_at DESC);
CREATE INDEX IF NOT EXISTS idx_blog_posts_slug ON blog_posts(slug);

ALTER TABLE blog_posts ENABLE ROW LEVEL SECURITY;

DO $$ BEGIN
  -- Public storefront: read published posts only.
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'blog_posts' AND policyname = 'blog_public_read') THEN
    CREATE POLICY blog_public_read ON blog_posts FOR SELECT TO anon USING (status = 'published');
  END IF;
  -- Admin app (authenticated): full read/write.
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'blog_posts' AND policyname = 'blog_auth_read') THEN
    CREATE POLICY blog_auth_read   ON blog_posts FOR SELECT TO authenticated USING (true);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'blog_posts' AND policyname = 'blog_auth_insert') THEN
    CREATE POLICY blog_auth_insert ON blog_posts FOR INSERT TO authenticated WITH CHECK (true);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'blog_posts' AND policyname = 'blog_auth_update') THEN
    CREATE POLICY blog_auth_update ON blog_posts FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'blog_posts' AND policyname = 'blog_auth_delete') THEN
    CREATE POLICY blog_auth_delete ON blog_posts FOR DELETE TO authenticated USING (true);
  END IF;
END $$;
