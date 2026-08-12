/*
  # Program placement: Bookboll Marketplace vs. the provider's own website

  Brings membership programs (class_programs) into the same placement model as
  listings (see 20260619000000_listing_placement.sql). Each program can now choose
  where it appears:

    - show_on_marketplace   appears on the Bookboll marketplace (subject to the
                            existing approval / eligibility review)
    - show_on_provider_site appears on the provider's own white-label site
                            (no Bookboll approval needed)

  Both default to true (on the marketplace AND the provider site). Existing
  hidden programs (status='inactive') are migrated to "off everywhere" so their
  visibility is unchanged. The Membership Programs page still uses status for the
  provider's own hide/show; placement adds the marketplace-vs-site split.

  Idempotent: safe to re-run.
*/

ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS show_on_marketplace   boolean DEFAULT true;
ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS show_on_provider_site boolean DEFAULT true;

-- Preserve current visibility: anything hidden today becomes off on both surfaces.
UPDATE class_programs
SET show_on_marketplace = false,
    show_on_provider_site = false
WHERE status = 'inactive'
  AND show_on_marketplace IS DISTINCT FROM false
  AND show_on_provider_site IS DISTINCT FROM false;

-- Make sure no rows are left null (older rows created before the default).
UPDATE class_programs SET show_on_marketplace   = true WHERE show_on_marketplace   IS NULL;
UPDATE class_programs SET show_on_provider_site = true WHERE show_on_provider_site IS NULL;
