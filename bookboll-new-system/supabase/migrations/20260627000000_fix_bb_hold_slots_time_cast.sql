/*
  # Fix: bb_hold_slots text<time error breaks every slot selection

  `bookings.start_time` / `bookings.end_time` are stored as TEXT ('HH:MM' / 'HH:MM:SS'),
  but an earlier deployed version of bb_hold_slots compared them to a `time` value without
  casting, so the function raised:

      operator does not exist: text < time without time zone

  That error bubbled up as "That time was just taken" on the storefront, rolling back EVERY
  slot selection and making venue booking impossible.

  This re-deploys bb_hold_slots with every time comparison explicitly cast to `time`
  (defensive casts on both the bookings side — text columns — and the slot_holds side —
  already `time`, so the cast is a harmless no-op). CREATE OR REPLACE, idempotent.

  Requires 20260622000000_slot_holds (slot_holds table + columns).
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

    -- bookings.start_time/end_time are TEXT ('HH:MM'/'HH:MM:SS') — must cast to time.
    SELECT count(*) INTO v_committed FROM bookings
      WHERE listing_id = p_listing_id AND booking_date = p_date
        AND booking_status <> 'cancelled'
        AND start_time::time < v_end AND end_time::time > v_start;

    SELECT COALESCE(sum(qty), 0) INTO v_held FROM slot_holds
      WHERE listing_id = p_listing_id AND booking_date = p_date
        AND held_by <> p_held_by AND expires_at > now()
        AND start_time::time < v_end AND end_time::time > v_start;

    IF v_committed + v_held >= v_cap THEN
      RETURN jsonb_build_object('ok', false, 'reason', 'slot_taken', 'start_time', v_slot->>'start_time');
    END IF;
  END LOOP;

  -- Reconcile: drop any of this holder's holds on this day that are NOT in the new
  -- selection (so deselecting a slot frees it immediately for everyone else).
  DELETE FROM slot_holds
    WHERE listing_id = p_listing_id AND booking_date = p_date AND held_by = p_held_by
      AND start_time::time NOT IN (SELECT (s->>'start_time')::time FROM jsonb_array_elements(p_slots) s);

  -- Pass 2: (re)create this holder's holds (idempotent extend = fresh expiry).
  FOR v_slot IN SELECT jsonb_array_elements(p_slots) LOOP
    v_start := (v_slot->>'start_time')::time;
    v_end   := (v_slot->>'end_time')::time;
    DELETE FROM slot_holds
      WHERE listing_id = p_listing_id AND booking_date = p_date AND held_by = p_held_by AND start_time::time = v_start;
    INSERT INTO slot_holds (listing_id, booking_date, start_time, end_time, qty, held_by, expires_at)
      VALUES (p_listing_id, p_date, v_start, v_end, 1, p_held_by, v_exp);
    v_out := v_out || jsonb_build_array(jsonb_build_object(
      'start_time', to_char(v_start, 'HH24:MI'), 'end_time', to_char(v_end, 'HH24:MI')));
  END LOOP;

  RETURN jsonb_build_object('ok', true, 'expires_at', v_exp, 'slots', v_out);
END $$;

GRANT EXECUTE ON FUNCTION bb_hold_slots(uuid, date, jsonb, text, integer) TO anon, authenticated;
