-- Dedicated "Bookboll for Business" blog, separate from the customer marketplace blog.
--
-- blog_posts already serves two scopes via owner_user_id (NULL = Bookboll, set = a
-- provider's white-label blog). This adds a third axis for the Bookboll-owned posts:
--   audience = 'marketplace'  → the customer-facing marketplace blog at /blog
--   audience = 'business'     → the for-businesses landing blog at /for-business/blog
-- The DEFAULT backfills every existing row as 'marketplace', so nothing moves.
-- Provider posts (owner_user_id set) ignore this column entirely.

alter table blog_posts add column if not exists audience text default 'marketplace';
