<?php

namespace App\Console\Commands;

use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Give each venue its real coordinates, read from the Google Maps link it already has.
 *
 * Why this is needed: VenuesDohaQatarSeeder stamped every Qatar venue with one
 * constant — DOHA_LAT/DOHA_LNG, "approximate Doha city centre", a placeholder for
 * venues with no coordinates — and nothing ever replaced it. The storefront's
 * distance badge is computed correctly from those numbers, so all sixteen venues
 * report the identical distance and the badge reads as precise while being useless.
 *
 * The real positions were there all along: every venue carries its own
 * maps.app.goo.gl share link. The admin's own extractor cannot read them because a
 * SHORT link holds no coordinates — they only appear once the redirect is followed,
 * which a browser cannot do cross-origin. That is exactly what this command does.
 *
 * Nothing is written without --dry-run having been possible first, and by default a
 * venue someone actually pinned by hand is left alone.
 */
class BackfillVenueCoordinates extends Command
{
    protected $signature = 'venues:backfill-coordinates
        {--dry-run : Resolve and report every venue, writing nothing}
        {--only= : Comma-separated venue ids to limit to}
        {--force : Also overwrite venues whose coordinates look hand-set}
        {--sleep=300 : Milliseconds to wait between link lookups}';

    protected $description = "Resolve each venue's Google Maps link into real latitude/longitude";

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $venues = $this->targets();

        if ($venues->isEmpty()) {
            $this->info('Nothing to do — no venue has a maps link worth resolving.');

            return self::SUCCESS;
        }

        $shared = $this->sharedCoordinates();

        $this->line($dry ? '<comment>DRY RUN — nothing will be written.</comment>' : '<info>Writing changes.</info>');
        $this->newLine();

        $rows = [];
        $willWrite = 0;

        foreach ($venues as $venue) {
            $current = $this->pair($venue->latitude, $venue->longitude);
            $handSet = $current !== null && ! in_array($current, $shared, true);

            if ($handSet && ! $this->option('force')) {
                $rows[] = [$venue->id, $this->short($venue->name), $current, '—', 'skipped (looks hand-set)'];
                continue;
            }

            $found = $this->coordinatesFor((string) $venue->maps_url);
            usleep(max(0, (int) $this->option('sleep')) * 1000);

            if ($found === null) {
                $rows[] = [$venue->id, $this->short($venue->name), $current ?? '(none)', '—', 'no coordinates in link'];
                continue;
            }

            [$lat, $lng] = $found;
            $next = sprintf('%.7F, %.7F', $lat, $lng);

            if ($current === $next) {
                $rows[] = [$venue->id, $this->short($venue->name), $current, $next, 'already correct'];
                continue;
            }

            $willWrite++;

            if (! $dry) {
                $venue->forceFill(['latitude' => $lat, 'longitude' => $lng])->save();
            }

            $rows[] = [$venue->id, $this->short($venue->name), $current ?? '(none)', $next, $dry ? 'would update' : 'UPDATED'];
        }

        $this->table(['id', 'venue', 'current', 'resolved', 'result'], $rows);
        $this->newLine();

        if ($dry) {
            $this->comment("{$willWrite} venue(s) would be updated. Re-run without --dry-run to apply.");
        } else {
            $this->info("{$willWrite} venue(s) updated.");
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Venue> */
    protected function targets()
    {
        $query = Venue::query()
            ->whereNotNull('maps_url')
            ->where('maps_url', '!=', '')
            ->orderBy('id');

        if ($only = trim((string) $this->option('only'))) {
            $ids = collect(explode(',', $only))->map(fn ($i) => (int) trim($i))->filter()->all();
            $query->whereIn('id', $ids ?: [0]);
        }

        return $query->get();
    }

    /**
     * Coordinates that more than one venue reports.
     *
     * A pin dropped on a real venue is its own; a pair repeated across venues is a
     * placeholder somebody stamped in bulk. That is what makes it safe to refresh
     * those by default and leave a hand-pinned venue (the Lahore one, say) untouched.
     *
     * @return array<int, string>
     */
    protected function sharedCoordinates(): array
    {
        return Venue::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['latitude', 'longitude'])
            ->map(fn ($v) => $this->pair($v->latitude, $v->longitude))
            ->filter()
            ->countBy()
            ->filter(fn ($n) => $n > 1)
            ->keys()
            ->all();
    }

    /**
     * Pull a lat/lng out of a Maps link, following the redirect when it is a short one.
     *
     * @return array{0: float, 1: float}|null
     */
    protected function coordinatesFor(string $url): ?array
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if ($found = $this->extract($url)) {
            return $found;
        }

        // A share link (maps.app.goo.gl / goo.gl/maps) carries no coordinates until
        // it is expanded, which is the whole reason the admin's paste box cannot
        // read these.
        try {
            $response = Http::withHeaders([
                // A MOBILE user agent, and it matters. Asked as a desktop browser,
                // maps.app.goo.gl answers 200 with a Firebase "durable deep link"
                // interstitial that resolves itself in JavaScript — no redirect, no
                // coordinates, nothing a server-side fetch can use. Asked as Android,
                // the same link redirects properly to the real /maps/place page.
                'User-Agent' => 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(25)->get($url);
        } catch (\Throwable $e) {
            $this->warn("  {$url} — {$e->getMessage()}");

            return null;
        }

        // urldecode first: the place name is percent-encoded and can swallow the
        // separators the patterns below look for.
        $final = urldecode((string) ($response->effectiveUri() ?? ''));

        // The URL is authoritative. The body is 800 KB of nearby places, so it is a
        // last resort and will happily hand back the wrong pin if trusted first.
        return $this->extract($final) ?? $this->extract($response->body());
    }

    /**
     * The coordinate forms Google uses, widest-first. Mirrors the admin's
     * applyMapsLink() and adds the two that only ever appear in an expanded URL or
     * in the page body.
     *
     * @return array{0: float, 1: float}|null
     */
    protected function extract(string $haystack): ?array
    {
        $patterns = [
            '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/',       // place page, most precise
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',           // /maps/@lat,lng,zoom
            '/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/[?&]ll=(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/[?&]destination=(-?\d+\.\d+),(-?\d+\.\d+)/',
            // A pin dropped on open ground (no named place) resolves to a SEARCH
            // url instead: /maps/search/25.260969,+51.482322. urldecode() above has
            // already turned that "+" into a space.
            '#/maps/(?:search|dir)/(?:[^/]*/)?(-?\d+\.\d+),\s*(-?\d+\.\d+)#',
            '/\[null,null,(-?\d+\.\d+),(-?\d+\.\d+)\]/',   // embedded in the page JSON
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $haystack, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];

                // A match that is not a point on Earth is a false positive from the
                // page body, not a location.
                if (abs($lat) <= 90 && abs($lng) <= 180 && ($lat !== 0.0 || $lng !== 0.0)) {
                    return [$lat, $lng];
                }
            }
        }

        return null;
    }

    protected function pair($lat, $lng): ?string
    {
        return ($lat === null || $lng === null) ? null : sprintf('%.7F, %.7F', (float) $lat, (float) $lng);
    }

    protected function short(?string $name): string
    {
        $name = (string) $name;

        return mb_strlen($name) > 38 ? mb_substr($name, 0, 37).'…' : $name;
    }
}
