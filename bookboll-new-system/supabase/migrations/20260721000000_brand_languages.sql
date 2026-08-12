/*
  # Provider storefront languages

  A provider can restrict which languages their white-label site offers in the language
  switcher (jsonb array of locale codes from src/lib/i18n.js SUPPORTED_LOCALES, e.g.
  ["ar","fr"]). NULL / empty = all 17 languages (default, and the pre-migration behavior).
  English is always available regardless (it's the source language of the content).

  Idempotent — safe to re-run.
*/

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_languages jsonb;
