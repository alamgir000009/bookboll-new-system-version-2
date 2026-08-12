/*
  # Provider-owned blog posts

  Adds an optional owner to blog posts so each provider can publish their OWN blog on
  their white-label storefront, separate from the Bookboll marketplace blog:

  - owner_user_id : NULL  → a Bookboll MARKETPLACE post (apex /blog, super-admin managed)
                    set   → a PROVIDER post (shown on that provider's white-label /blog)

  The storefront shows the Blog module on a provider's site only when they have at least
  one published post (auto-derived, like venues/memberships). Slugs stay globally unique.

  Additive + nullable, so existing marketplace posts (owner_user_id NULL) keep working.
  The public read RLS (status = 'published') already covers provider posts too.
*/

ALTER TABLE blog_posts ADD COLUMN IF NOT EXISTS owner_user_id uuid;
CREATE INDEX IF NOT EXISTS idx_blog_posts_owner ON blog_posts(owner_user_id, status, published_at DESC);
