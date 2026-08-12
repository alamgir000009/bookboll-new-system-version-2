-- Host payouts via Stripe Connect (Luma-style, SIMULATED like the rest of the
-- payment rails): a CUSTOMER who hosts paid events can connect a Stripe account.
-- With Stripe tickets enabled on an event, joiners pay online and Bookboll's
-- service fee is split automatically per ticket — so the host no longer fronts
-- the per-seat fee at publish time. The event's ticket mode itself lives inside
-- hosted_games.charge (jsonb, already exists) — no hosted_games change needed.

alter table customers add column if not exists stripe_connect jsonb;
