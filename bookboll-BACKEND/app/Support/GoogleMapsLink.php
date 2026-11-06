<?php
namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turn any Google Maps link a provider pastes into a coordinate.
 *
 * Share links from the Google Maps app (maps.app.goo.gl/…, goo.gl/maps/…,
 * g.co/maps/…) carry NO coordinates themselves: they are redirects to the long
 * google.com/maps URL that does. A browser cannot read that redirect target
 * cross-origin, so the dashboard asks us to follow it. We follow the hops by
 * hand (never automatically), checking every hop is still a Google host, so a
 * pasted link can't be used to make the server fetch something else.
 *
 * Result: ['expanded_url', 'latitude', 'longitude', 'source', 'place_name'].
 * `source` is 'link' when the coordinates were in the URL itself, 'geocoded'
 * when the URL only named a place and we looked that name up (approximate —
 * the caller should say so), or null when nothing could be read.
 */
class GoogleMapsLink
{
    protected const MAX_HOPS = 6;

    /** Hosts a Google Maps link may start on or redirect through. */
    protected const ALLOWED_HOST_PATTERNS = [
        '/^maps\.app\.goo\.gl$/i',
        '/^goo\.gl$/i',
        '/^g\.co$/i',
        '/^(www\.|maps\.)?google\.[a-z]{2,3}(\.[a-z]{2})?$/i',
        '/^consent\.google\.[a-z]{2,3}(\.[a-z]{2})?$/i',
    ];

    public static function resolve(string $url): array
    {
        $url = trim($url);
        $empty = ['expanded_url' => $url, 'latitude' => null, 'longitude' => null, 'source' => null, 'place_name' => null];

        if ($url === '' || ! self::isAllowedUrl($url)) {
            return $empty;
        }

        // Coordinates may already be in the pasted link — no network needed.
        if ($coords = self::coordinatesFromUrl($url)) {
            return ['expanded_url' => $url, 'latitude' => $coords[0], 'longitude' => $coords[1], 'source' => 'link', 'place_name' => self::placeNameFromUrl($url)];
        }

        $expanded = self::expand($url);
        if ($expanded && ($coords = self::coordinatesFromUrl($expanded))) {
            return ['expanded_url' => $expanded, 'latitude' => $coords[0], 'longitude' => $coords[1], 'source' => 'link', 'place_name' => self::placeNameFromUrl($expanded)];
        }

        // The link only names a place ("/maps/place/Some+Arena") — look it up.
        $placeName = self::placeNameFromUrl($expanded ?: $url);
        if ($placeName && ($coords = self::geocodePlaceName($placeName))) {
            return ['expanded_url' => $expanded ?: $url, 'latitude' => $coords[0], 'longitude' => $coords[1], 'source' => 'geocoded', 'place_name' => $placeName];
        }

        return ['expanded_url' => $expanded ?: $url, 'latitude' => null, 'longitude' => null, 'source' => null, 'place_name' => $placeName];
    }

    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        foreach (self::ALLOWED_HOST_PATTERNS as $pattern) {
            if (preg_match($pattern, $parts['host'])) {
                return true;
            }
        }

        return false;
    }

    /** Follow redirects hop by hop, staying on Google hosts; returns the final URL or null. */
    protected static function expand(string $url): ?string
    {
        $current = $url;
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            // An EU consent interstitial wraps the real destination in `continue=`.
            if (preg_match('/^consent\.google\./i', parse_url($current, PHP_URL_HOST) ?: '')) {
                parse_str((string) parse_url($current, PHP_URL_QUERY), $q);
                $next = $q['continue'] ?? null;
                if ($next && self::isAllowedUrl($next)) {
                    $current = $next;
                    continue;
                }

                return $current;
            }

            try {
                $response = Http::withOptions(['allow_redirects' => false])
                    ->timeout(8)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; Bookboll/1.0; +https://bookboll.com)',
                        'Accept-Language' => 'en',
                    ])
                    ->get($current);
            } catch (\Throwable $e) {
                Log::info('GoogleMapsLink: could not expand link', ['url' => $current, 'error' => $e->getMessage()]);

                return $hop ? $current : null;
            }

            $location = $response->header('Location');
            if ($response->redirect() && $location) {
                $next = self::absolutize($location, $current);
                if (! self::isAllowedUrl($next)) {
                    return $current;
                }
                $current = $next;
                continue;
            }

            // Some share pages land on a 200 that carries the long URL in its body.
            if ($response->ok() && $hop === 0) {
                $body = (string) $response->body();
                if (preg_match('/https:\/\/www\.google\.[a-z.]+\/maps\/[^"\'<>\s\\\\]+/i', $body, $m)) {
                    $candidate = html_entity_decode($m[0]);
                    if (self::isAllowedUrl($candidate)) {
                        return $candidate;
                    }
                }
            }

            return $current;
        }

        return $current;
    }

    protected static function absolutize(string $location, string $base): string
    {
        if (preg_match('/^https?:\/\//i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '');

        return $origin . (str_starts_with($location, '/') ? $location : '/' . $location);
    }

    /**
     * Pull [lat, lng] out of a long Google Maps URL. Most specific first: the
     * `!3d…!4d…` pair is the PLACE itself; `@lat,lng` is only the viewport.
     */
    public static function coordinatesFromUrl(string $url): ?array
    {
        $decoded = rawurldecode($url);
        $num = '(-?\d{1,3}(?:\.\d+)?)';
        $patterns = [
            '/!3d' . $num . '!4d' . $num . '/',
            '/[?&](?:q|query|ll|center|destination|daddr|saddr|sll)=' . $num . '\s*,\s*' . $num . '(?:[&\s]|$)/i',
            '/\/(?:place|search|dir)\/' . $num . '\s*,\s*' . $num . '(?:[\/?]|$)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $decoded, $m) && self::valid((float) $m[1], (float) $m[2])) {
                return [round((float) $m[1], 7), round((float) $m[2], 7)];
            }
        }

        // A dropped pin shares as degrees/minutes/seconds: 25°12'34.5"N 55°16'12.3"E —
        // that is the pin itself, so it outranks the "@lat,lng" viewport centre below.
        $dms = '(\d{1,3})°(\d{1,2})\'(\d{1,2}(?:\.\d+)?)"?([NS])\s*\+?\s*(\d{1,3})°(\d{1,2})\'(\d{1,2}(?:\.\d+)?)"?([EW])';
        if (preg_match('/' . $dms . '/u', $decoded, $m)) {
            $lat = ((int) $m[1] + (int) $m[2] / 60 + (float) $m[3] / 3600) * ($m[4] === 'S' ? -1 : 1);
            $lng = ((int) $m[5] + (int) $m[6] / 60 + (float) $m[7] / 3600) * ($m[8] === 'W' ? -1 : 1);
            if (self::valid($lat, $lng)) {
                return [round($lat, 7), round($lng, 7)];
            }
        }

        if (preg_match('/@' . $num . ',' . $num . '/', $decoded, $m) && self::valid((float) $m[1], (float) $m[2])) {
            return [round((float) $m[1], 7), round((float) $m[2], 7)];
        }

        return null;
    }

    /** The human-readable place a long URL names, e.g. "/maps/place/Al+Nasr+Leisureland/…" → "Al Nasr Leisureland". */
    public static function placeNameFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/maps/place/([^/]+)#', $path, $m)) {
            $name = trim(rawurldecode(str_replace('+', ' ', $m[1])));
            if ($name !== '' && ! self::coordinatesFromUrl('/place/' . $m[1] . '/') && ! preg_match('/^\d/', $name)) {
                return $name;
            }
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        foreach (['q', 'query', 'destination'] as $key) {
            $value = trim((string) ($q[$key] ?? ''));
            if ($value !== '' && ! preg_match('/^-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?$/', $value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Best-effort lookup of a place name: Google Geocoding when a server key is
     * configured (services.google_maps.server_key), OpenStreetMap otherwise.
     */
    protected static function geocodePlaceName(string $name): ?array
    {
        $key = (string) config('services.google_maps.server_key');
        if ($key !== '') {
            try {
                $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $name, 'key' => $key, 'language' => 'en',
                ]);
                $loc = $response->ok() ? ($response->json()['results'][0]['geometry']['location'] ?? null) : null;
                if ($loc && isset($loc['lat'], $loc['lng']) && self::valid((float) $loc['lat'], (float) $loc['lng'])) {
                    return [round((float) $loc['lat'], 7), round((float) $loc['lng'], 7)];
                }
            } catch (\Throwable $e) {
                Log::info('GoogleMapsLink: google geocode failed', ['name' => $name, 'error' => $e->getMessage()]);
            }
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'Bookboll/1.0 (support@bookboll.com)',
                    'Accept-Language' => 'en',
                ])
                ->get('https://nominatim.openstreetmap.org/search', ['q' => $name, 'format' => 'json', 'limit' => 1]);
            $hit = $response->ok() ? ($response->json()[0] ?? null) : null;
            if ($hit && isset($hit['lat'], $hit['lon']) && self::valid((float) $hit['lat'], (float) $hit['lon'])) {
                return [round((float) $hit['lat'], 7), round((float) $hit['lon'], 7)];
            }
        } catch (\Throwable $e) {
            Log::info('GoogleMapsLink: geocode failed', ['name' => $name, 'error' => $e->getMessage()]);
        }

        return null;
    }

    protected static function valid(float $lat, float $lng): bool
    {
        // (0,0) is in the Gulf of Guinea, never a venue — it is what a broken parse yields.
        return abs($lat) <= 90 && abs($lng) <= 180 && ! ($lat == 0.0 && $lng == 0.0);
    }
}
