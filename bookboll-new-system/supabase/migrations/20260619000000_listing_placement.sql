/*
  # Listing placement: Bookboll Marketplace vs. the provider's own website

  Each listing can now choose where it appears. Previously the only control was
  listings.status (active/inactive = shown/hidden everywhere). Now:

    - show_on_marketplace   appears on the Bookboll marketplace (subject to the
                            existing approval / eligibility review)
    - show_on_provider_site appears on the provider's own white-label site
                            (no Bookboll approval needed)

  Both default to true (on the marketplace AND the provider site). Existing
  hidden listings (status='inactive') are migrated to "off everywhere" so their
  visibility is unchanged. listings.status is no longer the listing visibility
  gate (locations still use status for venue-level hide).

  Idempotent: safe to re-run.
*/

ALTER TABLE listings ADD COLUMN IF NOT EXISTS show_on_marketplace   boolean DEFAULT true;
ALTER TABLE listings ADD COLUMN IF NOT EXISTS show_on_provider_site boolean DEFAULT true;

-- Preserve current visibility: anything hidden today becomes off on both surfaces.
UPDATE listings
SET show_on_marketplace = false,
    show_on_provider_site = false
WHERE status = 'inactive'
  AND show_on_marketplace IS DISTINCT FROM false
  AND show_on_provider_site IS DISTINCT FROM false;

-- Make sure no rows are left null (older rows created before the default).
UPDATE listings SET show_on_marketplace   = true WHERE show_on_marketplace   IS NULL;
UPDATE listings SET show_on_provider_site = true WHERE show_on_provider_site IS NULL;
