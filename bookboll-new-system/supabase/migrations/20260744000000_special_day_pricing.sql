/*
  # Special-day OPTIONAL pricing override (server side)

  Pairs with the client resolver (src/lib/specialDays.js applySpecialDayPrice):
  a custom-schedule special day may now carry
    • custom_price      → every slot that date costs exactly this, or
    • price_multiplier  → the listing's resolved price × multiplier (1 = off).
  Neither set → listing base/dynamic pricing applies unchanged (old behavior).
  Off-days are unaffected (closed = no slots). bb_hold_slots is unchanged.

  CREATE OR REPLACE, idempotent. Supersedes the bb_available_slots body from
  20260742000000_special_days_availability.sql.
*/

CREATE OR REPLACE FUNCTION bb_available_slots(p_listing_id uuid, p_date date)
RETURNS TABLE(start_time text, end_time text, price numeric, available boolean)
LANGUAGE plpgsql STABLE
AS $$
DECLARE
  v_listing  record;
  v_sd       record;
  v_dur      int;
  v_buffer   int;
  v_step     int;
  v_maxper   int;
  v_dow      int;
  v_starts   int[];
  v_win_o    int[];   -- window open minutes (parallel arrays)
  v_win_c    int[];   -- window close minutes
  wi         int;
  sm         int;     -- slot start minutes-from-midnight
  em         int;     -- slot end minutes
  s_txt      text;
  e_txt      text;
  v_booked   int;
  v_held     int;
  v_now      timestamp;
  v_today    date;
  v_nowmin   int;
  v_cp       numeric;      -- special-day flat price override
  v_pm       numeric := 1; -- special-day price multiplier
BEGIN
  SELECT l.* INTO v_listing FROM listings l WHERE l.id = p_listing_id AND l.status = 'active';
  IF NOT FOUND THEN RETURN; END IF;

  v_dur    := COALESCE(NULLIF(v_listing.slot_duration, 0), 60);
  v_buffer := COALESCE(v_listing.buffer_time, 0);
  v_step   := v_dur + v_buffer;
  v_maxper := GREATEST(1, COALESCE(v_listing.max_bookings_per_slot, 1));
  v_dow    := EXTRACT(dow FROM p_date)::int;
  IF v_step <= 0 THEN RETURN; END IF;

  -- ── 1) Special day: most specific usable row wins ──────────────────────────
  -- (usable = closed, or a valid HH:MM window with close > open)
  -- The o/c casts are CASE-guarded: a closed row qualifies via the is_closed
  -- arm regardless of what its open/close TEXT columns hold, so an unguarded
  -- cast on garbage text would abort the whole function.
  SELECT sd.is_closed,
         CASE WHEN sd.open_time ~ '^[0-9]+:[0-9]+'
              THEN (split_part(sd.open_time,  ':', 1)::int * 60 + split_part(sd.open_time,  ':', 2)::int) END AS o,
         CASE WHEN sd.close_time ~ '^[0-9]+:[0-9]+'
              THEN (split_part(sd.close_time, ':', 1)::int * 60 + split_part(sd.close_time, ':', 2)::int) END AS c,
         sd.custom_price AS cp,
         sd.price_multiplier AS pm
    INTO v_sd
    FROM special_days sd
   WHERE sd.date = p_date
     AND sd.location_id = v_listing.location_id
     AND (sd.listing_id = p_listing_id OR sd.listing_id IS NULL)
     AND (sd.is_closed OR (
           sd.open_time ~ '^[0-9]+:[0-9]+' AND sd.close_time ~ '^[0-9]+:[0-9]+'
           AND (split_part(sd.close_time, ':', 1)::int * 60 + split_part(sd.close_time, ':', 2)::int)
             > (split_part(sd.open_time,  ':', 1)::int * 60 + split_part(sd.open_time,  ':', 2)::int)))
   ORDER BY (sd.listing_id IS NOT NULL) DESC, sd.is_closed DESC
   LIMIT 1;

  IF FOUND THEN
    IF v_sd.is_closed THEN RETURN; END IF;
    v_win_o := ARRAY[v_sd.o];
    v_win_c := ARRAY[v_sd.c];
    v_cp := v_sd.cp;
    v_pm := CASE WHEN COALESCE(v_sd.pm, 0) > 0 THEN v_sd.pm ELSE 1 END;
  ELSE
    -- ── 2) Weekly listing_hours (one row per open period) ───────────────────
    SELECT array_agg(o ORDER BY o), array_agg(c ORDER BY o)
      INTO v_win_o, v_win_c
      FROM (
        SELECT (split_part(lh.open_time,  ':', 1)::int * 60 + split_part(lh.open_time,  ':', 2)::int) AS o,
               (split_part(lh.close_time, ':', 1)::int * 60 + split_part(lh.close_time, ':', 2)::int) AS c
          FROM listing_hours lh
         WHERE lh.listing_id = p_listing_id AND lh.day_of_week = v_dow AND lh.is_open
           AND lh.open_time ~ '^[0-9]' AND lh.close_time ~ '^[0-9]'
      ) q WHERE c > o;

    IF v_win_o IS NULL THEN
      IF EXISTS (SELECT 1 FROM listing_hours lh WHERE lh.listing_id = p_listing_id) THEN
        RETURN;   -- hours are configured and this weekday is closed
      END IF;
      -- ── 3) No schedule configured → legacy always-open window ─────────────
      v_win_o := ARRAY[6 * 60];
      v_win_c := ARRAY[23 * 60];
    END IF;
  END IF;

  -- market-local "now" for past-slot filtering
  v_now    := now() AT TIME ZONE 'Asia/Qatar';
  v_today  := v_now::date;
  v_nowmin := EXTRACT(hour FROM v_now)::int * 60 + EXTRACT(minute FROM v_now)::int;

  -- Build slot start minutes across every window. fixed_start_times is text[].
  IF v_listing.start_time_mode = 'fixed'
     AND v_listing.fixed_start_times IS NOT NULL
     AND COALESCE(array_length(v_listing.fixed_start_times, 1), 0) > 0 THEN
    SELECT array_agg(DISTINCT m ORDER BY m) INTO v_starts FROM (
      SELECT (split_part(x, ':', 1)::int * 60 + split_part(x, ':', 2)::int) AS m
      FROM unnest(v_listing.fixed_start_times) AS x
    ) q, generate_subscripts(v_win_o, 1) AS w
    WHERE q.m >= v_win_o[w] AND q.m + v_dur <= v_win_c[w];
  ELSE
    SELECT array_agg(DISTINCT m ORDER BY m) INTO v_starts FROM (
      SELECT generate_series(v_win_o[w], v_win_c[w] - v_dur, v_step) AS m
      FROM generate_subscripts(v_win_o, 1) AS w
    ) q;
  END IF;

  IF v_starts IS NULL THEN RETURN; END IF;

  FOREACH sm IN ARRAY v_starts LOOP
    em := sm + v_dur;
    s_txt := lpad((sm / 60)::text, 2, '0') || ':' || lpad((sm % 60)::text, 2, '0');
    e_txt := lpad((em / 60)::text, 2, '0') || ':' || lpad((em % 60)::text, 2, '0');

    -- skip past slots on today (market timezone)
    IF p_date = v_today AND sm <= v_nowmin THEN CONTINUE; END IF;

    -- pricing: pricing_cells override → pricing_group → base_price,
    -- then the OPTIONAL special-day override just below
    SELECT COALESCE(
      (SELECT COALESCE(pc.direct_price, pg.price)
         FROM pricing_cells pc
         LEFT JOIN pricing_groups pg ON pg.id = pc.pricing_group_id
        WHERE pc.listing_id = p_listing_id AND pc.day_of_week = v_dow AND pc.slot_time = s_txt
        LIMIT 1),
      v_listing.base_price, 0) INTO price;

    -- Special-day pricing override (custom days only; closed days never get here)
    IF v_cp IS NOT NULL THEN price := v_cp;
    ELSIF v_pm <> 1 THEN price := round(price * v_pm, 2);
    END IF;

    -- overlapping non-cancelled bookings (buffer reserved on both sides)
    -- ~ '^[0-9]' guards skip malformed/empty time strings (bookings times are text).
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


