-- 20260761000000_community_event_visibility.sql
-- Community-hosted events (games / hangouts / tournaments tagged with a community_id)
-- are PRIVATE to that community by default: they don't appear in the public Events
-- marketplace and only community members can join. The host/admin can opt an event
-- into public listing by flipping community_public → true.
alter table hosted_games add column if not exists community_public boolean not null default false;
