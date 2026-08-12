-- Community profile photo (avatar) — shown next to the community's name on its
-- page, cards, the home Communities row and community-hosted events. The app
-- treats a missing column as "no photo", so this is safe to apply any time.
alter table communities add column if not exists avatar_image text;
