<?php

namespace App\Console\Commands;

use App\Models\VenueContractDocument;
use Illuminate\Console\Command;

/**
 * Remove contract uploads no agreement ever claimed.
 *
 * A contract is uploaded before the agreement citing it is saved, so an
 * abandoned editor leaves a file behind. readmeAlamgir: "Temporary unlinked
 * documents expire" and "A failed agreement save can leave only an expiring
 * temporary upload, never a public orphan."
 *
 * Only ever touches rows with no linked_at, so a contract an agreement refers
 * to is never at risk however old it gets.
 */
class PruneTemporaryContracts extends Command
{
    protected $signature = 'contracts:prune-temporary
        {--hours=48 : Remove unlinked uploads older than this}
        {--dry-run : Report what would go without deleting anything}';

    protected $description = 'Delete venue contract uploads that no agreement ever linked';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dry   = (bool) $this->option('dry-run');

        $stale = VenueContractDocument::query()
            ->whereNull('linked_at')
            ->where('created_at', '<', now()->subHours($hours))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No abandoned contract uploads.');

            return self::SUCCESS;
        }

        foreach ($stale as $doc) {
            $this->line("unlinked: venue {$doc->venue_id} · {$doc->original_name}");
            if (! $dry) {
                // The model's delete hook removes the file with the row.
                $doc->delete();
            }
        }

        $this->info(($dry ? 'Would remove ' : 'Removed ').$stale->count().' abandoned upload(s).');

        return self::SUCCESS;
    }
}
