<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Opt every customer in to a marketing channel, in one go.
 *
 * The consent flags default to OFF, so a base imported before the flags existed
 * shows every customer as "not opted in" and campaigns reach nobody. This flips
 * them on for customer accounts only — never staff — and stamps
 * marketing_updated_at so the change is visible. Customers can switch any
 * channel back off from their account page at any time.
 *
 * One UPDATE per channel by default — 16k rows in one statement, no models
 * hydrated. That bypasses Eloquent events, so Spatie's per-model activity log
 * sees nothing; instead ONE summary entry per channel is written to the
 * activity log (log name "customers", causer: none — a console run) with the
 * count and who ran it. `--per-customer` trades speed for a full trail: it
 * chunks through the accounts and saves each model, so every customer gets
 * their own "marketing_whatsapp: false → true" activity entry.
 *
 *   php artisan customers:opt-in-marketing --dry-run
 *   php artisan customers:opt-in-marketing --channel=whatsapp
 *   php artisan customers:opt-in-marketing --channel=all
 *   php artisan customers:opt-in-marketing --channel=whatsapp --per-customer
 */
class OptInCustomersToMarketing extends Command
{
    protected $signature = 'customers:opt-in-marketing
        {--channel=all : whatsapp, email, sms or all}
        {--per-customer : Save each account individually so the activity log records every customer (slower)}
        {--dry-run : Count what would change without writing}';

    protected $description = 'Opt all customer accounts in to WhatsApp / email / SMS marketing (they can opt out later)';

    private const CHANNELS = [
        'whatsapp' => 'marketing_whatsapp',
        'email'    => 'marketing_email',
        'sms'      => 'marketing_sms',
    ];

    public function handle(): int
    {
        $pick = strtolower((string) $this->option('channel'));
        $columns = $pick === 'all' ? self::CHANNELS : array_intersect_key(self::CHANNELS, [$pick => true]);
        if (! $columns) {
            $this->error('Unknown channel. Use whatsapp, email, sms or all.');

            return self::FAILURE;
        }

        $customers = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'customer'));
        $this->info('Customer accounts: '.(clone $customers)->count());

        foreach ($columns as $channel => $column) {
            $pending = (clone $customers)->where(fn ($q) => $q->whereNull($column)->orWhere($column, false));
            $n = $pending->count();

            if ($this->option('dry-run')) {
                $this->line("  {$channel}: would opt in {$n}");
                continue;
            }

            if ($this->option('per-customer')) {
                $done = 0;
                $pending->orderBy('id')->chunkById(500, function ($rows) use ($column, &$done) {
                    foreach ($rows as $user) {
                        // Model save → Spatie logs the dirty flag for this customer.
                        $user->forceFill([$column => true, 'marketing_updated_at' => now()])->save();
                        $done++;
                    }
                });
            } else {
                $done = $pending->update([$column => true, 'marketing_updated_at' => now()]);
            }
            $this->line("  {$channel}: opted in {$done}");

            // The audit trail for the run itself, whichever mode: who, what, how many.
            activity('customers')
                ->withProperties([
                    'channel'      => $channel,
                    'column'       => $column,
                    'opted_in'     => $done,
                    'mode'         => $this->option('per-customer') ? 'per_customer' : 'bulk',
                    'run_by'       => get_current_user().'@'.gethostname(),
                ])
                ->log("Opted {$done} customers in to {$channel} marketing via customers:opt-in-marketing");
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');
        }

        return self::SUCCESS;
    }
}
