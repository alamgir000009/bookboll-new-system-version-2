/*
  # Slot holds — prevent double-booking + temporary "reserved while checking out"

  Problem: customer slot availability is derived from committed `bookings` only, so a
  limited-capacity slot stays visible to everyone while someone is mid-checkout, and the
  final insert is a non-atomic read-then-write (two people can both pass the check and
  both book → capacity overrun).

  Fix:
    1. `slot_holds` — a short-lived reservation row per held slot, with `expires_at`.
       Availability everywhere subtracts ACTIVE holds (expires_at > now()), so a held
       slot disappears for other users and frees itself automatically when the hold
       expires — no cron needed for correctness.
    2. `bb_hold_slots()` — the ATOMIC gate. Under a per-listing/day advisory lock it
       recounts capacity (committed bookings + other users' live holds) and only then
       inserts/extends this holder's holds. Capacity can never be exceeded under
       concurrency. Called both when checkout starts and again at confirm.
    3. `bb_release_holds()` — drop a holder's holds (on abandon / after booking).
    4. `listings.booking_hold_minutes` — admin-configurable countdown (default 10).

  Everything is additive + guarded in the app, so the site works unchanged until this runs.
  Idempotent: safe to re-run.
*/

-- Admin-configurable hold/countdown length per listing (minutes).
ALTER TABLE listings ADD COLUMN IF NOT EXISTS booking_hold_minutes integer NOT NULL DEFAULT 10;

CREATE TABLE IF NOT EXISTS slot_holds (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id    uuid NOT NULL,
  booking_date  date NOT NULL,
  start_time    time NOT NULL,
  end_time      time NOT NULL,
  qty           integer NOT NULL DEFAULT 1,
  held_by       text NOT NULL,          -- customer id, or a per-device anon token
  expires_at    timestamptz NOT NULL,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS slot_holds_lookup_idx ON slot_holds (listing_id, booking_date, expires_at);
CREATE INDEX IF NOT EXISTS slot_holds_holder_idx ON slot_holds (held_by);

-- Availability reads need to see live holds. Writes go only through the SECURITY
-- DEFINER functions below (which run the atomic capacity check), so no write policy.
ALTER TABLE slot_holds ENABLE ROW LEVEL SECURITY;
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'slot_holds' AND policyname = 'slot_holds_read') THEN
    CREATE POLICY slot_holds_read ON slot_holds FOR SELECT USING (true);
  END IF;
END $$;

/*
  bb_hold_slots — atomically reserve one or more slots for `p_held_by`.
  p_slots: jsonb array of { "start_time": "HH:MM", "end_time": "HH:MM" }.
  Returns: { ok: true, expires_at, slots:[{start_time,end_time}] }
        or { ok: false, reason: 'slot_taken', start_time }.
*/
CREATE OR REPLACE FUNCTION bb_hold_slots(
  p_listing_id uuid,
  p_date       date,
  p_slots      jsonb,
  p_held_by    text,
  p_minutes    integer DEFAULT 10
) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER AS $$
DECLARE
  v_cap       integer;
  v_minutes   integer := GREATEST(1, COALESCE(p_minutes, 10));
  v_exp       timestamptz := now() + make_interval(mins => v_minutes);
  v_slot      jsonb;
  v_start     time;
  v_end       time;
  v_committed integer;
  v_held      integer;
  v_out       jsonb := '[]'::jsonb;
BEGIN
  IF p_held_by IS NULL OR p_held_by = '' THEN
    RETURN jsonb_build_object('ok', false, 'reason', 'no_holder');
  END IF;

  -- Serialize every hold/book decision for this listing on this day.
  PERFORM pg_advisory_xact_lock(hashtext(p_listing_id::text || '|' || p_date::text));

  SELECT GREATEST(1, COALESCE(max_bookings_per_slot, 1)) INTO v_cap FROM listings WHERE id = p_listing_id;
  IF v_cap IS NULL THEN v_cap := 1; END IF;

  -- Hygiene: clear expired holds for this listing/day.
  DELETE FROM slot_holds WHERE listing_id = p_listing_id AND booking_date = p_date AND expires_at <= now();

  -- Pass 1: verify capacity for EVERY requested slot before reserving any.
  FOR v_slot IN SELECT jsonb_array_elements(p_slots) LOOP
    v_start := (v_slot->>'start_time')::time;
    v_end   := (v_slot->>'end_time')::time;

    -- bookings.start_time/end_time are stored as text ('HH:MM'/'HH:MM:SS') — cast to time.
    SELECT count(*) INTO v_committed FROM bookings
      WHERE listing_id = p_listing_id AND booking_date = p_date
        AND booking_status <> 'cancelled'
        AND start_time::time < v_end AND end_time::time > v_start;

    SELECT COALESCE(sum(qty), 0) INTO v_held FROM slot_holds
      WHERE listing_id = p_listing_id AND booking_date = p_date
        AND held_by <> p_held_by AND expires_at > now()
        AND start_time < v_end AND end_time > v_start;

    IF v_committed + v_held >= v_cap THEN
      RETURN jsonb_build_object('ok', false, 'reason', 'slot_taken', 'start_time', v_slot->>'start_time');
    END IF;
  END LOOP;

  -- Reconcile: drop any of this holder's holds on this day that are NOT in the new
  -- selection (so deselecting a slot frees it immediately for everyone else).
  DELETE FROM slot_holds
    WHERE listing_id = p_listing_id AND booking_date = p_date AND held_by = p_held_by
      AND start_time NOT IN (SELECT (s->>'start_time')::time FROM jsonb_array_elements(p_slots) s);

  -- Pass 2: (re)create this holder's holds (idempotent extend = fresh expiry).
  FOR v_slot IN SELECT jsonb_array_elements(p_slots) LOOP
    v_start := (v_slot->>'start_time')::time;
    v_end   := (v_slot->>'end_time')::time;
    DELETE FROM slot_holds
      WHERE listing_id = p_listing_id AND booking_date = p_date AND held_by = p_held_by AND start_time = v_start;
    INSERT INTO slot_holds (listing_id, booking_date, start_time, end_time, qty, held_by, expires_at)
      VALUES (p_listing_id, p_date, v_start, v_end, 1, p_held_by, v_exp);
    v_out := v_out || jsonb_build_array(jsonb_build_object(
      'start_time', to_char(v_start, 'HH24:MI'), 'end_time', to_char(v_end, 'HH24:MI')));
  END LOOP;

  RETURN jsonb_build_object('ok', true, 'expires_at', v_exp, 'slots', v_out);
END $$;

CREATE OR REPLACE FUNCTION bb_release_holds(
  p_held_by    text,
  p_listing_id uuid DEFAULT NULL,
  p_date       date DEFAULT NULL
) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN
  DELETE FROM slot_holds
    WHERE held_by = p_held_by
      AND (p_listing_id IS NULL OR listing_id = p_listing_id)
      AND (p_date IS NULL OR booking_date = p_date);
END $$;

GRANT EXECUTE ON FUNCTION bb_hold_slots(uuid, date, jsonb, text, integer) TO anon, authenticated;
GRANT EXECUTE ON FUNCTION bb_release_holds(text, uuid, date) TO anon, authenticated;

-- Live updates: let the storefront react when others hold/release a slot. Ignored if
-- the table is already in the publication. (bookings is left as-is to avoid an error.)
DO $$ BEGIN
  ALTER PUBLICATION supabase_realtime ADD TABLE slot_holds;
EXCEPTION WHEN duplicate_object OR undefined_object THEN NULL;
END $$;
