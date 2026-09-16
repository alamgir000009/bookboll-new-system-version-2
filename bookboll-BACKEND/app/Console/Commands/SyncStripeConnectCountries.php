<?php

namespace App\Console\Commands;

use App\Services\HostPayoutService;
use Illuminate\Console\Command;
use Stripe\StripeClient;

/**
 * Work out which countries this platform can actually open a host payout account
 * in, by asking Stripe rather than assuming.
 *
 * It matters because the answer is not "the countries Stripe supports": it depends
 * on the platform account's own country and what Stripe has approved it for. A US
 * platform is refused a Qatari account outright, even though Stripe lists Qatar.
 * Guessing from the country spec is not good enough either — tried against Stripe,
 * a spec-based guess was wrong for 13 of 121 countries.
 *
 * The only honest way to know is to try, so this creates a throwaway account per
 * country and deletes it again. Test mode only unless you really mean it.
 *
 * The result is written to `countries.stripe_connect_supported`, which is what the
 * picker reads. A migration seeds that column with a verified list, so this only
 * needs running when something changes: Stripe approving the platform for more
 * countries, or a `world:install` re-seed clearing the flags.
 */
class SyncStripeConnectCountries extends Command
{
    protected $signature = 'stripe:sync-connect-countries
        {--apply : Save the result. Without it, only report.}
        {--allow-live : Permit this against live keys, where the accounts are real.}';

    protected $description = 'Verify with Stripe which countries hosts can open a payout account in';

    public function handle(HostPayoutService $payouts): int
    {
        $secret = trim((string) config('services.stripe.secret'));

        if ($secret === '') {
            $this->error('Stripe is not configured.');

            return self::FAILURE;
        }

        $live = str_starts_with($secret, 'sk_live_');

        if ($live && ! $this->option('allow-live')) {
            $this->error('These are LIVE keys — this would create and delete real connected accounts.');
            $this->line('Run it against test keys, or pass --allow-live if you are certain.');

            return self::FAILURE;
        }

        $client = new StripeClient($secret);

        $this->info('Platform account: '.$client->accounts->retrieve()->country.($live ? ' (LIVE)' : ' (test)'));

        $candidates = [];

        foreach ($client->countrySpecs->all(['limit' => 100])->autoPagingIterator() as $spec) {
            $candidates[] = strtoupper((string) $spec->id);
        }

        sort($candidates);
        $this->info('Stripe lists '.count($candidates).' countries. Trying each…');

        $works = [];
        $refused = [];
        $bar = $this->output->createProgressBar(count($candidates));

        foreach ($candidates as $code) {
            try {
                $account = $client->accounts->create([
                    'type'         => 'express',
                    'country'      => $code,
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                        'transfers'     => ['requested' => true],
                    ],
                ]);

                $works[] = $code;

                try {
                    $client->accounts->delete($account->id);
                } catch (\Throwable $e) {
                    $this->warn("  could not delete probe account {$account->id} ({$code}): ".$e->getMessage());
                }
            } catch (\Throwable $e) {
                $refused[$code] = preg_replace('/\s+/', ' ', $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(count($works).' countries work:');
        $this->line('  '.implode(' ', $works));
        $this->newLine();
        $this->warn(count($refused).' refused. A few examples:');

        foreach (array_slice($refused, 0, 5, true) as $code => $message) {
            $this->line("  {$code}  ".substr($message, 0, 90));
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Dry run — pass --apply to save this as the list hosts are offered.');

            return self::SUCCESS;
        }

        $payouts->storeSupportedCountries($works);
        $this->newLine();
        $this->info('Saved to countries.stripe_connect_supported — hosts will now only be offered these '
            .count($works).' countries. Every other country is marked unsupported.');

        return self::SUCCESS;
    }
}
