-- Seed: Doha (Qatar) events for the next ~2 months — games, social hangouts
-- and tournaments across sports, so the Events module has a lively demo feed.
-- Idempotent: skips itself if the marker event already exists. Venue/listing
-- links attach to real Doha venues when present; hangouts use custom spots.

DO $$
DECLARE
  v_city    uuid;
  v_country uuid;
  h1 uuid; h2 uuid; h3 uuid;
  foot_l uuid; foot_loc uuid;
  padel_l uuid; padel_loc uuid;
  tennis_l uuid; tennis_loc uuid;
  basket_l uuid; basket_loc uuid;
BEGIN
  SELECT id, country_id INTO v_city, v_country FROM cities WHERE lower(name) = 'doha' LIMIT 1;
  IF v_city IS NULL THEN RAISE NOTICE 'No Doha city row — nothing seeded.'; RETURN; END IF;
  IF EXISTS (SELECT 1 FROM hosted_games WHERE title = 'Friday Night 5v5 Football') THEN
    RAISE NOTICE 'Doha events already seeded.'; RETURN;
  END IF;

  -- Demo hosts (created only if missing)
  SELECT id INTO h1 FROM customers WHERE email = 'khalid.host@example.com';
  IF h1 IS NULL THEN INSERT INTO customers (full_name, email, phone, country_id, status)
    VALUES ('Khalid Al-Thani', 'khalid.host@example.com', '+974 5511 2233', v_country, 'active') RETURNING id INTO h1; END IF;
  SELECT id INTO h2 FROM customers WHERE email = 'sara.host@example.com';
  IF h2 IS NULL THEN INSERT INTO customers (full_name, email, phone, country_id, status)
    VALUES ('Sara Mansour', 'sara.host@example.com', '+974 5522 3344', v_country, 'active') RETURNING id INTO h2; END IF;
  SELECT id INTO h3 FROM customers WHERE email = 'omar.host@example.com';
  IF h3 IS NULL THEN INSERT INTO customers (full_name, email, phone, country_id, status)
    VALUES ('Omar Farouk', 'omar.host@example.com', '+974 5533 4455', v_country, 'active') RETURNING id INTO h3; END IF;

  -- Real Doha venues per sport, when they exist (events still render without one)
  SELECT l.id, l.location_id INTO foot_l, foot_loc FROM listings l
    JOIN locations lo ON lo.id = l.location_id
   WHERE lo.city_id = v_city AND l.sport_category ILIKE '%foot%' AND l.status = 'active' LIMIT 1;
  SELECT l.id, l.location_id INTO padel_l, padel_loc FROM listings l
    JOIN locations lo ON lo.id = l.location_id
   WHERE lo.city_id = v_city AND l.sport_category ILIKE '%padel%' AND l.status = 'active' LIMIT 1;
  SELECT l.id, l.location_id INTO tennis_l, tennis_loc FROM listings l
    JOIN locations lo ON lo.id = l.location_id
   WHERE lo.city_id = v_city AND l.sport_category ILIKE '%tennis%' AND l.status = 'active' LIMIT 1;
  SELECT l.id, l.location_id INTO basket_l, basket_loc FROM listings l
    JOIN locations lo ON lo.id = l.location_id
   WHERE lo.city_id = v_city AND l.sport_category ILIKE '%basket%' AND l.status = 'active' LIMIT 1;

  INSERT INTO hosted_games
    (host_customer_id, host_name, host_phone, host_whatsapp, city_id, country_id,
     location_id, listing_id, title, sport, game_type, game_date, start_time, end_time,
     price_per_person, currency, max_players, skill_level, description, status,
     approval_mode, num_teams, format, prize_pot, prize_note,
     custom_location_name, custom_location_address)
  VALUES
  -- ── Weekly pickup GAMES ────────────────────────────────────────────────
  (h1, 'Khalid Al-Thani', '+974 5511 2233', '+974 5511 2233', v_city, v_country,
   foot_loc, foot_l, 'Friday Night 5v5 Football', 'Football', 'game',
   CURRENT_DATE + 4, '20:00', '22:00', 40, 'QAR', 10, 'intermediate',
   'Competitive but friendly 5-a-side under the lights. Bibs and balls provided — bring water and good vibes. We split into balanced teams on arrival.',
   'open', 'instant', 0, 'single_elim', 0, '', '', ''),
  (h1, 'Khalid Al-Thani', '+974 5511 2233', '+974 5511 2233', v_city, v_country,
   foot_loc, foot_l, 'Friday Night 5v5 Football — Week 2', 'Football', 'game',
   CURRENT_DATE + 11, '20:00', '22:00', 40, 'QAR', 10, 'intermediate',
   'Week 2 of our Friday run. Regulars get priority; newcomers welcome while spots last.',
   'open', 'instant', 0, 'single_elim', 0, '', '', ''),
  (h2, 'Sara Mansour', '+974 5522 3344', '+974 5522 3344', v_city, v_country,
   padel_loc, padel_l, 'Padel Mixed Doubles Night', 'Padel', 'game',
   CURRENT_DATE + 6, '19:00', '21:00', 60, 'QAR', 8, 'all',
   'Two courts, rotating mixed doubles every 20 minutes. All levels — we pair beginners with experienced partners.',
   'open', 'review', 0, 'single_elim', 0, '', '', ''),
  (h3, 'Omar Farouk', '+974 5533 4455', '+974 5533 4455', v_city, v_country,
   basket_loc, basket_l, 'Saturday Morning Basketball Run', 'Basketball', 'game',
   CURRENT_DATE + 7, '08:00', '10:00', 25, 'QAR', 15, 'all',
   'Full-court runs, first to 15, winners stay on. Come early to warm up — tip-off at 8 sharp before the heat.',
   'open', 'instant', 0, 'single_elim', 0, '', '', ''),
  (h2, 'Sara Mansour', '+974 5522 3344', '+974 5522 3344', v_city, v_country,
   tennis_loc, tennis_l, 'Tennis Rally & Match Play Evening', 'Tennis', 'game',
   CURRENT_DATE + 13, '18:30', '20:30', 50, 'QAR', 8, 'beginner',
   'Beginner-friendly evening: 45 minutes of guided rallying, then relaxed short-set matches. Rackets available to borrow.',
   'open', 'review', 0, 'single_elim', 0, '', '', ''),
  (h1, 'Khalid Al-Thani', '+974 5511 2233', '+974 5511 2233', v_city, v_country,
   foot_loc, foot_l, 'Sunset 7v7 Football', 'Football', 'game',
   CURRENT_DATE + 20, '18:00', '20:00', 35, 'QAR', 14, 'advanced',
   'Higher-tempo 7-a-side for experienced players. Bring both a dark and a light shirt.',
   'open', 'review', 0, 'single_elim', 0, '', '', ''),
  (h3, 'Omar Farouk', '+974 5533 4455', '+974 5533 4455', v_city, v_country,
   padel_loc, padel_l, 'Padel Americano Social', 'Padel', 'game',
   CURRENT_DATE + 27, '19:30', '21:30', 55, 'QAR', 12, 'all',
   'Americano format — everyone plays with everyone, points carry across rounds. Prizes for the top pair.',
   'open', 'instant', 0, 'single_elim', 0, '', '', ''),
  (h2, 'Sara Mansour', '+974 5522 3344', '+974 5522 3344', v_city, v_country,
   basket_loc, basket_l, '3x3 Basketball Evening', 'Basketball', 'game',
   CURRENT_DATE + 34, '19:00', '21:00', 20, 'QAR', 12, 'intermediate',
   'Half-court 3x3, round-robin between four squads. Fast games, short rests.',
   'open', 'instant', 0, 'single_elim', 0, '', '', ''),

  -- ── Social HANGOUTS (game_type = event) ───────────────────────────────
  (h2, 'Sara Mansour', '+974 5522 3344', '+974 5522 3344', v_city, v_country,
   NULL, NULL, 'Corniche Sunset Run & Karak', 'Running', 'event',
   CURRENT_DATE + 9, '17:30', '19:00', 0, 'QAR', 25, 'all',
   'Easy-pace 5K along the Corniche, karak on the host afterwards. Walkers welcome — nobody gets left behind.',
   'open', 'instant', 0, 'single_elim', 0, '',
   'Doha Corniche', 'Corniche promenade, meet by the Pearl Monument'),
  (h3, 'Omar Farouk', '+974 5533 4455', '+974 5533 4455', v_city, v_country,
   NULL, NULL, 'Beach Volleyball & BBQ Hangout', 'Volleyball', 'event',
   CURRENT_DATE + 16, '16:00', '19:30', 30, 'QAR', 20, 'all',
   'Casual beach volleyball rotations followed by a group BBQ — price covers food and drinks. Families welcome.',
   'open', 'review', 0, 'single_elim', 0, '',
   'Katara Beach', 'Katara Cultural Village, Beach gate 1'),
  (h1, 'Khalid Al-Thani', '+974 5511 2233', '+974 5511 2233', v_city, v_country,
   NULL, NULL, 'Padel Watch Party & Open Courts', 'Padel', 'event',
   CURRENT_DATE + 41, '18:00', '22:00', 0, 'QAR', 40, 'all',
   'Big-screen finals viewing with open courts between matches. Free entry — book your court time separately if you want to play.',
   'open', 'instant', 0, 'single_elim', 0, '',
   'Padel In Qatar Lounge', 'Al Waab Street, Doha'),

  -- ── TOURNAMENTS ────────────────────────────────────────────────────────
  (h1, 'Khalid Al-Thani', '+974 5511 2233', '+974 5511 2233', v_city, v_country,
   padel_loc, padel_l, 'Doha Padel Doubles Cup', 'Padel', 'tournament',
   CURRENT_DATE + 24, '17:00', '22:00', 200, 'QAR', 16, 'intermediate',
   'Knockout doubles cup — 8 teams, best of 3 sets from the semis. Entry is per team; includes balls and water.',
   'open', 'review', 8, 'single_elim', 1500, 'QAR 1,500 + trophies for the winning pair',
   '', ''),
  (h3, 'Omar Farouk', '+974 5533 4455', '+974 5533 4455', v_city, v_country,
   foot_loc, foot_l, 'Summer 5s Football Championship', 'Football', 'tournament',
   CURRENT_DATE + 45, '16:00', '22:00', 350, 'QAR', 8, 'all',
   'One-day 5-a-side championship: group stage into knockouts, 8 teams, squads of up to 8. Entry is per team.',
   'open', 'review', 8, 'single_elim', 3000, 'QAR 3,000 prize pool + medals',
   '', ''),
  (h2, 'Sara Mansour', '+974 5522 3344', '+974 5522 3344', v_city, v_country,
   tennis_loc, tennis_l, 'Doha Open Tennis Singles Ladder', 'Tennis', 'tournament',
   CURRENT_DATE + 55, '15:00', '21:00', 120, 'QAR', 16, 'advanced',
   'Singles knockout for confident players — 16 draw, short sets to keep the day moving. Finalists seeded into next season.',
   'open', 'review', 16, 'single_elim', 800, 'QAR 800 + champion trophy',
   '', '');

  RAISE NOTICE 'Seeded 14 Doha events.';
END $$;
