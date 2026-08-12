/*
  # Add-on image + booking-session-extension

  Simplifies the add-on model to what the listing form now exposes:
    - image_url          a photo for the add-on (uploaded via imgbb, like other images)
    - extension_pricing  'none' | 'percent' | 'flat' — optionally turns the add-on
                         into a booking-session extension (the customer can add the
                         next free adjacent slot at a discount or flat price)
    - extension_value    the % discount or flat price for the extension

  Idempotent: safe to re-run.
*/

ALTER TABLE addons ADD COLUMN IF NOT EXISTS image_url         text    NOT NULL DEFAULT '';
ALTER TABLE addons ADD COLUMN IF NOT EXISTS extension_pricing text    NOT NULL DEFAULT 'none';
ALTER TABLE addons ADD COLUMN IF NOT EXISTS extension_value   numeric NOT NULL DEFAULT 0;
