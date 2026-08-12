/*
  # Booking extensions + typed add-ons

  1. Listings — slot extension pricing (customer can extend a booking into the next
     contiguous slot at a cheaper rate):
     - extension_pricing : 'none' | 'percent' | 'flat'
     - extension_value   : the % discount (percent) or the flat price per extra slot (flat)

  2. Add-ons — make them typed so they price correctly on longer/multi-slot bookings:
     - kind        : 'product' | 'service' | 'rental'   (grouping/label only)
     - charge_unit : 'per_item' (qty × price) | 'per_booking' (once) | 'per_hour' (× booked hours)

  All additive with safe defaults, so existing rows/code keep working unchanged.
*/

ALTER TABLE listings ADD COLUMN IF NOT EXISTS extension_pricing text NOT NULL DEFAULT 'none';
ALTER TABLE listings ADD COLUMN IF NOT EXISTS extension_value   numeric NOT NULL DEFAULT 0;

ALTER TABLE addons ADD COLUMN IF NOT EXISTS kind        text NOT NULL DEFAULT 'product';
ALTER TABLE addons ADD COLUMN IF NOT EXISTS charge_unit text NOT NULL DEFAULT 'per_item';
