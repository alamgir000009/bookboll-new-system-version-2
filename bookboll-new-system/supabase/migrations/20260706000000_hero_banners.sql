-- Customizable home hero slideshow banners.
--
-- Each provider stores an ordered array of banner objects for THEIR white-label
-- storefront home page. The Bookboll marketplace (apex) equivalent is stored in
-- platform_settings under the key `hero_banners` (key/value table, no schema change).
--
-- Banner shape (jsonb):
--   { id, heading, subheading, image, bg, text_color,
--     audience: 'all'|'in'|'out',        -- show to everyone / signed-in / signed-out
--     action,                            -- preset key (book_venue, join_event, ...) or 'custom' / 'none'
--     link,                              -- target when action = 'custom'
--     button_text, button_color }

alter table admin_users add column if not exists hero_banners jsonb default '[]'::jsonb;
