<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Reusable Stripe Products and Prices for the things we sell on a subscription.
 *
 * Checkout used to build every recurring line with inline `price_data` +
 * `product_data`. Stripe does not deduplicate those: each checkout minted a brand
 * new Product and Price, so a hundred businesses on Premium meant a hundred
 * Products all named "Bookboll Premium — Monthly". For a one-off charge that is
 * correct and recommended ("Booking #1234" really is unique), but a subscription
 * keeps its Price referenced for years, and ad-hoc ones cost you revenue-per-plan
 * reporting, central price changes and Stripe's price-migration tooling.
 *
 * So: one Product per sellable thing, reused by everyone who subscribes to it.
 *   • Bookboll Premium   → one Product, one Price per billing interval.
 *   • A class programme  → one Product per programme (each is a different
 *     merchant's product with its own name, currency and interval), one Price
 *     per pricing configuration.
 *
 * Prices are IMMUTABLE in Stripe. Changing an amount therefore creates a NEW
 * Price under the SAME Product — which is the behaviour you want: existing
 * subscribers keep paying what they signed up for until they are deliberately
 * migrated. The fingerprint below is what detects that change.
 *
 * Resolved ids are cached in `settings` so the lookup costs nothing after the
 * first checkout.
 */
class StripeCatalog
{
    /** settings key holding the json map of fingerprint → price id. */
    protected const SETTING_KEY = 'stripe_catalog_prices';

    /** @var array<string,string>|null fingerprint → price id, loaded once per request */
    protected static ?array $cache = null;

    /**
     * A Price id for a recurring charge, creating the Product/Price once and
     * reusing it afterwards.
     *
     * @param  string  $productKey  stable id for the thing being sold
     *                              ("premium", "program:{uuid}")
     * @param  string  $productName what the customer sees on the invoice
     * @param  int     $unitAmount  in the smallest currency unit (cents/fils)
     * @param  string  $interval    day|week|month|year
     * @param  int     $intervalCount
     */
    public static function recurringPrice(
        StripeClient $stripe,
        string $productKey,
        string $productName,
        int $unitAmount,
        string $currency,
        string $interval,
        int $intervalCount = 1,
        array $productMetadata = []
    ): ?string {
        $currency    = strtolower($currency);
        $fingerprint = self::fingerprint($productKey, $unitAmount, $currency, $interval, $intervalCount);

        if ($existing = self::lookup($fingerprint)) {
            return $existing;
        }

        try {
            $productId = self::productId($stripe, $productKey, $productName, $productMetadata);

            $price = $stripe->prices->create([
                'product'     => $productId,
                'currency'    => $currency,
                'unit_amount' => $unitAmount,
                'recurring'   => array_filter([
                    'interval'       => $interval,
                    'interval_count' => $intervalCount > 1 ? $intervalCount : null,
                ]),
                'metadata'    => ['product_key' => $productKey],
            ]);

            self::remember($fingerprint, $price->id);

            return $price->id;
        } catch (\Throwable $e) {
            // Never block a sale on catalogue bookkeeping. The caller falls back
            // to inline price_data, which still charges the right amount.
            Log::warning('Stripe catalogue price could not be resolved; falling back to inline price_data', [
                'product_key' => $productKey,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** The Product for a key, created once and remembered under its own fingerprint. */
    protected static function productId(StripeClient $stripe, string $key, string $name, array $metadata): string
    {
        $productFingerprint = 'product:'.$key;

        if ($existing = self::lookup($productFingerprint)) {
            return $existing;
        }

        $product = $stripe->products->create([
            'name'     => $name !== '' ? $name : $key,
            'metadata' => array_merge($metadata, ['product_key' => $key]),
        ]);

        self::remember($productFingerprint, $product->id);

        return $product->id;
    }

    /**
     * Amount, currency and interval are all part of the key: change any of them
     * and a new Price is minted rather than the old one silently reused.
     */
    protected static function fingerprint(string $key, int $amount, string $currency, string $interval, int $count): string
    {
        return implode('|', [$key, $amount, $currency, $interval, $count]);
    }

    protected static function lookup(string $fingerprint): ?string
    {
        $map = self::map();

        return $map[$fingerprint] ?? null;
    }

    protected static function remember(string $fingerprint, string $id): void
    {
        $map = self::map();
        $map[$fingerprint] = $id;
        self::$cache = $map;

        DB::table('settings')->updateOrInsert(
            ['key' => self::SETTING_KEY],
            ['value' => json_encode($map), 'updated_at' => now()]
        );
    }

    /** @return array<string,string> */
    protected static function map(): array
    {
        if (self::$cache === null) {
            $raw = DB::table('settings')->where('key', self::SETTING_KEY)->value('value');
            $decoded = $raw ? json_decode((string) $raw, true) : [];
            self::$cache = is_array($decoded) ? $decoded : [];
        }

        return self::$cache;
    }

    /** Tests and long-running workers must not reuse another run's map. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
