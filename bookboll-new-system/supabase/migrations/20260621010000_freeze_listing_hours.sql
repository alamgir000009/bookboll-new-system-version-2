/*
  # Decouple listing schedules from their location

  Listings used to "follow" their location via inherit_from_location rows (or by having
  no listing_hours rows at all), which meant a location schedule change silently changed
  those listings too. Listings now keep their OWN concrete weekly hours; the location
  schedule only restricts which slots can be opened, it never auto-updates a listing.

  This migration freezes every still-inheriting listing to a concrete copy of its
  location's CURRENT hours, so going forward each listing is independent. Listings that
  already have their own custom hours (at least one concrete open row) are left untouched.

  Idempotent: re-running only affects listings that are still inheriting.
*/

-- 1. Drop legacy inherit rows for listings that have no concrete (custom) open hours.
DELETE FROM listing_hours lh
WHERE lh.inherit_from_location = true
  AND NOT EXISTS (
    SELECT 1 FROM listing_hours x
    WHERE x.listing_id = lh.listing_id
      AND x.inherit_from_location = false
      AND x.is_open = true
  );

-- 2. For any listing now left with NO rows, insert a concrete copy of its location's
--    current weekly hours (so it keeps exactly what it used to show).
INSERT INTO listing_hours (listing_id, day_of_week, is_open, inherit_from_location, open_time, close_time)
SELECT l.id, loh.day_of_week, loh.is_open, false, loh.open_time, loh.close_time
FROM listings l
JOIN location_hours loh ON loh.location_id = l.location_id
WHERE NOT EXISTS (SELECT 1 FROM listing_hours lh WHERE lh.listing_id = l.id);

-- 3. Normalise any remaining inherit flags to false (all hours are concrete now).
UPDATE listing_hours SET inherit_from_location = false WHERE inherit_from_location = true;
