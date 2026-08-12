-- How joiners get into a hosted event: 'review' (host approves each request — the
-- default, today's behaviour) or 'instant' (anyone joins automatically until the
-- event is full; with Stripe tickets they pay online the moment they join).
-- Tournaments keep review-only registration.

alter table hosted_games add column if not exists approval_mode text not null default 'review';
