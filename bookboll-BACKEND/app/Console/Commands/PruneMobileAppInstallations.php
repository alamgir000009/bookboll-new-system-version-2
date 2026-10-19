<?php

namespace App\Console\Commands;

use App\Models\MobileAppInstallation;
use App\Support\MobileAppAdoption;
use Illuminate\Console\Command;

/**
 * The retention half of the adoption policy.
 *
 * Installation records are evidence of past use, not a permanent register of
 * people's devices. An installation nobody has signed in from for
 * MobileAppAdoption::RETENTION_DAYS is deleted, and the account simply returns
 * to `unknown` — which is the honest answer, since a last-use timestamp that
 * old proves nothing about whether the app is still there.
 *
 * Deleting an account removes its records immediately through the foreign key;
 * this covers the records nothing else would ever clean up.
 */
class PruneMobileAppInstallations extends Command
{
    protected $signature = 'mobile-app:prune-installations {--dry-run : Count what would be deleted and change nothing}';

    protected $description = 'Delete mobile-app installation records past their retention window';

    public function handle(): int
    {
        $cutoff = now()->subDays(MobileAppAdoption::RETENTION_DAYS);

        $query = MobileAppInstallation::where('last_authenticated_use_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '%d installation record(s) last used before %s would be deleted.',
                $query->count(),
                $cutoff->toDateString(),
            ));

            return self::SUCCESS;
        }

        // Chunked so a long-neglected table does not become one enormous delete.
        $deleted = 0;
        do {
            $batch = $query->clone()->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info(sprintf(
            'Deleted %d installation record(s) last used before %s (retention: %d days).',
            $deleted,
            $cutoff->toDateString(),
            MobileAppAdoption::RETENTION_DAYS,
        ));

        return self::SUCCESS;
    }
}
