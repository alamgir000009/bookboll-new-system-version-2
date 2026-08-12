-- WhatsApp menu-driven booking funnel (Bookboll marketplace).
--
-- 1) whatsapp_sessions — per-sender conversation state for the bot state machine.
--    Written by the whatsapp-webhook Edge Function (service role); anon read for debugging.
CREATE TABLE IF NOT EXISTS whatsapp_sessions (
  wa_id      text PRIMARY KEY,                 -- the customer's WhatsApp number (digits)
  step       text NOT NULL DEFAULT 'start',    -- current funnel step
  context    jsonb NOT NULL DEFAULT '{}'::jsonb, -- collected selection (city/sport/listing/date/slot)
  updated_at timestamptz NOT NULL DEFAULT now()
);
-- RLS on, NO anon policy: rows hold customer phone numbers + booking intent, and only the
-- webhook (service role, which bypasses RLS) ever touches this table, so the public anon key
-- must not be able to read it. (An earlier revision granted anon SELECT — this drops it.)
ALTER TABLE whatsapp_sessions ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS whatsapp_sessions_read ON whatsapp_sessions;

-- 2) bb_available_slots — the ONE source of truth for "which slots are free on date D for
--    listing L, and at what price" — shared by the web app and the WhatsApp bot (the Deno
--    function can't import the JS booking libs, so this MIRRORS src/lib/slots.js generateSlots()
--    + src/customer/booking.js buildDaySlots() exactly:
--      • window is a FIXED 06:00–23:00 (the generateSlots defaults the customer flow uses;
--        it does NOT read location_hours), stepping by (slot_duration + buffer_time),
--      • OR the listing's explicit fixed_start_times when start_time_mode = 'fixed',
--      • pricing = pricing_cells override → pricing_group → base_price,
--      • a slot is available while (overlapping non-cancelled bookings + active holds) is
--        below max_bookings_per_slot; bookings reserve buffer_time on both sides,
--      • past slots for "today" (market timezone) are dropped.
--    Not ported (rare / MVP): cross-listing resource-sharing capacity.
CREATE OR REPLACE FUNCTION bb_available_slots(p_listing_id uuid, p_date date)
RETURNS TABLE(start_time text, end_time text, price numeric, available boolean)
LANGUAGE plpgsql STABLE
AS $$
DECLARE
  v_listing  record;
  v_dur      int;
  v_buffer   int;
  v_step     int;
  v_maxper   int;
  v_dow      int;
  v_starts   int[];
  sm         int;   -- slot start minutes-from-midnight
  em         int;   -- slot end minutes
  s_txt      text;
  e_txt      text;
  v_booked   int;
  v_held     int;
  v_now      timestamp;
  v_today    date;
  v_nowmin   int;
  v_open     constant int := 6 * 60;    -- 06:00
  v_close    constant int := 23 * 60;   -- 23:00
BEGIN
  SELECT l.* INTO v_listing FROM listings l WHERE l.id = p_listing_id AND l.status = 'active';
  IF NOT FOUND THEN RETURN; END IF;

  v_dur    := COALESCE(NULLIF(v_listing.slot_duration, 0), 60);
  v_buffer := COALESCE(v_listing.buffer_time, 0);
  v_step   := v_dur + v_buffer;
  v_maxper := GREATEST(1, COALESCE(v_listing.max_bookings_per_slot, 1));
  v_dow    := EXTRACT(dow FROM p_date)::int;
  IF v_step <= 0 THEN RETURN; END IF;

  -- market-local "now" for past-slot filtering
  v_now    := now() AT TIME ZONE 'Asia/Qatar';
  v_today  := v_now::date;
  v_nowmin := EXTRACT(hour FROM v_now)::int * 60 + EXTRACT(minute FROM v_now)::int;

  -- Build the list of slot start minutes. fixed_start_times is a text[] column.
  IF v_listing.start_time_mode = 'fixed'
     AND v_listing.fixed_start_times IS NOT NULL
     AND COALESCE(array_length(v_listing.fixed_start_times, 1), 0) > 0 THEN
    SELECT array_agg(m ORDER BY m) INTO v_starts FROM (
      SELECT (split_part(x, ':', 1)::int * 60 + split_part(x, ':', 2)::int) AS m
      FROM unnest(v_listing.fixed_start_times) AS x
    ) q WHERE m >= v_open AND m + v_dur <= v_close;
  ELSE
    SELECT array_agg(m) INTO v_starts FROM generate_series(v_open, v_close - v_dur, v_step) AS m;
  END IF;

  IF v_starts IS NULL THEN RETURN; END IF;

  FOREACH sm IN ARRAY v_starts LOOP
    em := sm + v_dur;
    s_txt := lpad((sm / 60)::text, 2, '0') || ':' || lpad((sm % 60)::text, 2, '0');
    e_txt := lpad((em / 60)::text, 2, '0') || ':' || lpad((em % 60)::text, 2, '0');

    -- skip past slots on today (market timezone)
    IF p_date = v_today AND sm <= v_nowmin THEN CONTINUE; END IF;

    -- pricing: pricing_cells override → pricing_group → base_price
    SELECT COALESCE(
      (SELECT COALESCE(pc.direct_price, pg.price)
         FROM pricing_cells pc
         LEFT JOIN pricing_groups pg ON pg.id = pc.pricing_group_id
        WHERE pc.listing_id = p_listing_id AND pc.day_of_week = v_dow AND pc.slot_time = s_txt
        LIMIT 1),
      v_listing.base_price, 0) INTO price;

    -- overlapping non-cancelled bookings (buffer reserved on both sides)
    -- The ~ '^[0-9]' guards skip malformed/empty time strings (bookings.start_time is text
    -- DEFAULT '') so one bad row can't abort the whole function via ''::int — matching the JS
    -- toMinutes(), which returns 0 for falsy input rather than throwing.
    SELECT COALESCE(count(*), 0) INTO v_booked
    FROM bookings b
    WHERE b.listing_id = p_listing_id AND b.booking_date = p_date
      AND COALESCE(b.booking_status, '') <> 'cancelled'
      AND b.start_time ~ '^[0-9]' AND b.end_time ~ '^[0-9]'
      AND (split_part(b.start_time, ':', 1)::int * 60 + split_part(b.start_time, ':', 2)::int) - v_buffer < em
      AND (split_part(b.end_time,   ':', 1)::int * 60 + split_part(b.end_time,   ':', 2)::int) + v_buffer > sm;

    -- active holds (sum reserved qty)
    SELECT COALESCE(sum(COALESCE(h.qty, 1)), 0) INTO v_held
    FROM slot_holds h
    WHERE h.listing_id = p_listing_id AND h.booking_date = p_date AND h.expires_at > now()
      AND (EXTRACT(hour FROM h.start_time)::int * 60 + EXTRACT(minute FROM h.start_time)::int) < em
      AND (EXTRACT(hour FROM h.end_time)::int   * 60 + EXTRACT(minute FROM h.end_time)::int)   > sm;

    start_time := s_txt;
    end_time   := e_txt;
    price      := COALESCE(price, 0);
    available  := (v_booked + v_held) < v_maxper;
    RETURN NEXT;
  END LOOP;
END $$;

GRANT EXECUTE ON FUNCTION bb_available_slots(uuid, date) TO anon, authenticated, service_role;
