-- Storefront sort preferences.
--
-- Per-provider default ordering for their white-label storefront, set from the
-- Listings page: one for the "by space" (flat) view, one for the "by venue"
-- (grouped) view. The customer site still shows NEAREST FIRST when the visitor
-- has shared their location; these are the fallback when location is unavailable.
--
-- The Bookboll marketplace (apex) equivalents are stored in platform_settings
-- under keys `storefront_sort_space` / `storefront_sort_venue` (key/value table,
-- no schema change needed there).
--
-- Allowed values: recommended | newest | price_asc | price_desc | name

alter table admin_users add column if not exists sort_space text default 'recommended';
alter table admin_users add column if not exists sort_venue text default 'recommended';
