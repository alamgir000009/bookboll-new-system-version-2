<?php

namespace App\Console\Commands;

use App\Models\Upload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Housekeeping for the upload directory, now that uploads are recorded.
 *
 * Two different jobs, with deliberately different safety:
 *
 *   • Stale records — the row is there, the file is not. Removing the row
 *     deletes nothing, so this runs by default.
 *   • Unreferenced files — somebody opened a form, uploaded six photos, used
 *     two and left. The other four used to sit on disk forever. Finding them
 *     means proving no record anywhere still mentions the path, and getting
 *     that wrong deletes a live image. So it needs --delete-unreferenced, and
 *     --dry-run first is strongly advised.
 *
 * The columns that can hold a path are discovered from the live schema rather
 * than hardcoded: a hand-kept list silently stops covering a column someone
 * adds later, and the first symptom would be a deleted photo.
 */
class PruneOrphanUploads extends Command
{
    protected $signature = 'uploads:prune
        {--days=30 : Only consider uploads older than this many days}
        {--limit=500 : Most rows to examine in one pass}
        {--delete-unreferenced : Also delete old files that nothing references}
        {--dry-run : Report what would go without deleting anything}';

    protected $description = 'Remove upload records whose file is gone, and optionally old unreferenced files';

    /** Column names that conventionally hold an uploaded path or URL. */
    protected const NAME_HINTS = [
        'image', 'images', 'images_urls', 'photo', 'photos', 'photo_url',
        'logo', 'logo_url', 'brand_logo_url', 'avatar', 'avatar_image',
        'cover', 'cover_image', 'featured_image', 'banner', 'banners',
        'hero_banners', 'hero_image', 'icon', 'site_pages', 'media', 'picture',
    ];

    public function handle(): int
    {
        $dry      = (bool) $this->option('dry-run');
        $sweep    = (bool) $this->option('delete-unreferenced');
        $days     = max(1, (int) $this->option('days'));
        $limit    = max(1, (int) $this->option('limit'));

        $columns = $sweep ? $this->referencingColumns() : [];

        if ($sweep && ! $columns) {
            // Finding nothing to check against would make every file look
            // unreferenced. Refuse rather than delete the whole directory.
            $this->error('Found no columns that could reference an upload — refusing to sweep.');

            return self::FAILURE;
        }

        if ($sweep) {
            $this->line('Checking ' . count($columns) . ' column(s) for references.');
        }

        $rows = Upload::query()
            ->where('created_at', '<', now()->subDays($days))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing old enough to consider.');

            return self::SUCCESS;
        }

        $missing = 0;
        $orphans = 0;
        $kept    = 0;

        foreach ($rows as $upload) {
            if (! Storage::disk($upload->disk ?: 'public')->exists($upload->path)) {
                $missing++;
                $this->line("stale record (file already gone): {$upload->path}");
                if (! $dry) {
                    $upload->delete();
                }

                continue;
            }

            if (! $sweep) {
                continue;
            }

            if ($this->isReferenced($upload->path, $columns)) {
                $kept++;

                continue;
            }

            $orphans++;
            $this->line("unreferenced: {$upload->path}");
            if (! $dry) {
                $upload->delete();
            }
        }

        $verb = $dry ? 'Would remove' : 'Removed';
        $this->info("{$verb} {$missing} stale record(s)" . ($sweep ? " and {$orphans} unreferenced file(s); {$kept} still in use" : '') . ", out of {$rows->count()} examined.");

        if (! $sweep) {
            $this->line('Unreferenced files were not touched. Add --delete-unreferenced (with --dry-run first) to sweep them.');
        }
        if ($dry) {
            $this->line('Dry run — nothing was deleted.');
        }

        return self::SUCCESS;
    }

    /**
     * Text-ish columns in this database whose name suggests it can hold an
     * uploaded path. Read from the live schema so a new column with a
     * conventional name is covered without editing this command.
     *
     * @return array<int, array{table: string, column: string}>
     */
    protected function referencingColumns(): array
    {
        $out = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            // Our own bookkeeping, and framework tables that never hold one.
            if (in_array($table, ['uploads', 'migrations', 'sessions', 'cache', 'cache_locks',
                'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
                'personal_access_tokens'], true)) {
                continue;
            }

            foreach (Schema::getColumns($table) as $column) {
                $name = $column['name'] ?? '';
                $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

                if (! in_array($name, self::NAME_HINTS, true)) {
                    continue;
                }
                if (! preg_match('/char|text|json|blob/', $type)) {
                    continue;
                }

                $out[] = ['table' => $table, 'column' => $name];
            }
        }

        return $out;
    }

    /**
     * Does any record still mention this path? A JSON array of photos and a
     * single URL column are both just text containing it, so one LIKE covers
     * every shape it is stored in.
     *
     * @param  array<int, array{table: string, column: string}>  $columns
     */
    protected function isReferenced(string $path, array $columns): bool
    {
        // The stored path, and the public URL built from it, both appear in the
        // wild — older records saved the absolute URL.
        $needles = array_unique([$path, basename($path)]);

        $byTable = [];
        foreach ($columns as $c) {
            $byTable[$c['table']][] = $c['column'];
        }

        foreach ($byTable as $table => $cols) {
            $exists = DB::table($table)->where(function ($q) use ($cols, $needles) {
                foreach ($cols as $col) {
                    foreach ($needles as $needle) {
                        $q->orWhere($col, 'like', '%' . $needle . '%');
                    }
                }
            })->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }
}
