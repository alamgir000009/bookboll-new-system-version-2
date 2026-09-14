<?php

namespace App\Console\Commands;

use App\Models\ProviderWallet;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Relabel wallets whose currency disagrees with the money inside them.
 *
 * Wallets used to be opened with a hardcoded 'AED' default whenever the currency
 * could not be resolved, so a provider paid in PKR could end up with a wallet
 * reading "AED 159.20" — the amount right, the currency a fiction.
 *
 * The transactions carry the true currency (they are written from the charge), so
 * a wallet whose transactions are unanimous and disagree with its label is simply
 * mislabelled. A wallet that genuinely holds two currencies is reported and left
 * alone: relabelling that would misstate real money.
 *
 * Dry run by default; pass --apply to write.
 */
class FixWalletCurrencies extends Command
{
    protected $signature = 'wallet:fix-currencies
                            {--apply : Write the corrections (default is a dry run)}';

    protected $description = 'Correct wallet currencies that disagree with their own transactions';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $fixed = 0;
        $mixed = 0;
        $empty = 0;

        ProviderWallet::query()->orderBy('id')->chunkById(200, function ($wallets) use ($apply, &$fixed, &$mixed, &$empty) {
            foreach ($wallets as $w) {
                $label = strtoupper(trim((string) $w->currency));

                $currencies = WalletTransaction::query()
                    ->where('wallet_id', $w->id)
                    ->whereNotNull('currency')
                    ->where('currency', '!=', '')
                    ->distinct()
                    ->pluck('currency')
                    ->map(fn ($c) => strtoupper(trim((string) $c)))
                    ->unique()
                    ->values();

                if ($currencies->isEmpty()) {
                    // Nothing has landed yet — there is no evidence to correct against.
                    $empty++;
                    continue;
                }

                if ($currencies->count() > 1) {
                    $mixed++;
                    $this->warn(sprintf(
                        '  wallet %s holds %s — left alone, needs a human',
                        $w->id, $currencies->implode(' + ')
                    ));
                    continue;
                }

                $truth = $currencies->first();
                if ($truth === $label) {
                    continue;
                }

                $fixed++;
                $this->line(sprintf(
                    '  wallet %-6s %-5s -> %-5s   (balance %s, %s transaction(s))',
                    $w->id, $label ?: '(none)', $truth,
                    number_format((float) $w->available_balance + (float) $w->pending_balance, 2),
                    WalletTransaction::where('wallet_id', $w->id)->count()
                ));

                if ($apply) {
                    DB::transaction(fn () => ProviderWallet::whereKey($w->id)->update(['currency' => $truth]));
                }
            }
        });

        $this->newLine();
        $this->info(($apply ? 'Corrected: ' : 'Would correct: ')."{$fixed} wallet(s)");
        if ($mixed) {
            $this->warn("Mixed-currency wallets left untouched: {$mixed}");
        }
        if ($empty) {
            $this->line("Wallets with no transactions yet (nothing to check): {$empty}");
        }
        if (! $apply && $fixed) {
            $this->comment('Re-run with --apply to write these corrections.');
        }

        return self::SUCCESS;
    }
}
