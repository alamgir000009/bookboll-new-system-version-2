/*
  # Events: charge column + waitlist status

  1. hosted_games.charge (jsonb) — how the host collects seat/entry money:
     { courtTotal, courtNow, courtArrival, serviceFee, bookbollOwned,
       ticketMode: 'arrival' | 'stripe', stripeAccount }
     The wizard has written this for tournaments since 20260736 but NO migration
     ever created the column — on databases without it every tournament publish
     hard-failed, and games never persisted their ticket mode at all. The client
     now writes it best-effort for both.

  2. game_join_requests.status gains the value 'waitlisted' (text column, no
     constraint — documented here). Instant-join events that are FULL now queue
     new joiners as 'waitlisted' instead of turning them away; when a spot frees
     (someone leaves, is removed, or the host raises the cap) the earliest
     waitlisted joiner is auto-promoted to 'accepted' and notified.
     Waitlisted rows do NOT count against max_players.
*/

ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS charge jsonb;
