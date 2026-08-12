-- Staged content approval for listings and membership programs.
--
-- Before: a provider editing an existing listing/program flipped the whole row to
-- approval_status='pending', pulling it OFF the Bookboll marketplace until an admin
-- re-approved — even for purely operational edits (schedule, slot duration, pricing).
--
-- After: only CONTENT that could be inappropriate (title, description, photos) needs
-- approval, and it no longer takes the row offline. Operational edits apply live
-- immediately. A content edit is STAGED in `pending_content` while the live columns
-- keep serving the last-approved content on the marketplace. On approval the staged
-- content is copied into the live columns and pending_content is cleared; on reject it
-- is simply discarded (live content unchanged).
--
-- pending_content shape (nullable; NULL = nothing awaiting review):
--   { "name": "...", "description": "...", "photo_url": "...", "photos": [ ... ] }

alter table listings       add column if not exists pending_content jsonb;
alter table class_programs  add column if not exists pending_content jsonb;
