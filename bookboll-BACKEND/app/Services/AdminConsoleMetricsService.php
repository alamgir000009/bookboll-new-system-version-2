<?php

namespace App\Services;

use App\Models\SlotLock;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Numbers for the admin console's landing page.
 *
 * This console is not the business analytics dashboard — that one lives in the
 * Next.js app and computes its own KPIs from AdminAnalyticsService's raw
 * collections. This is the operator's view: is the platform busy, is anything
 * waiting on a human, and is the installation itself healthy.
 *
 * Query budget is deliberate. Shared hosting caps this account's MySQL
 * connections, and a connection is held for the whole request, so every figure
 * below is either one aggregate over an indexed column or is cached. Nothing
 * here loops a query per row.
 */
class AdminConsoleMetricsService
{
    /** information_schema and the Telescope count are expensive and barely move. */
    private const SYSTEM_CACHE_SECONDS = 600;

    /** Days of history in the trend chart. */
    public const TREND_DAYS = 14;

    /**
     * Trade, today against yesterday, plus the rolling week.
     *
     * One pass over `bookings` restricted to the last seven days. Value excludes
     * cancelled bookings — money that was never taken should not read as trade —
     * while the counts include them, with cancellations reported separately.
     */
    public function pulse(): array
    {
        $todayStart = now()->startOfDay();
        $yesterdayStart = now()->subDay()->startOfDay();
        $weekStart = now()->subDays(7)->startOfDay();

        $bookings = DB::table('bookings')
            ->where('created_at', '>=', $weekStart)
            ->selectRaw(
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today_count,
                 SUM(CASE WHEN created_at >= ? AND status != ? THEN total_price ELSE 0 END) AS today_value,
                 SUM(CASE WHEN created_at >= ? AND status = ? THEN 1 ELSE 0 END) AS today_cancelled,
                 SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS yesterday_count,
                 SUM(CASE WHEN created_at >= ? AND created_at < ? AND status != ? THEN total_price ELSE 0 END) AS yesterday_value,
                 COUNT(*) AS week_count,
                 SUM(CASE WHEN status != ? THEN total_price ELSE 0 END) AS week_value',
                [
                    $todayStart,
                    $todayStart, 'cancelled',
                    $todayStart, 'cancelled',
                    $yesterdayStart, $todayStart,
                    $yesterdayStart, $todayStart, 'cancelled',
                    'cancelled',
                ]
            )
            ->first();

        $users = DB::table('users')
            ->where('created_at', '>=', $weekStart)
            ->selectRaw(
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today_count,
                 SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS yesterday_count,
                 COUNT(*) AS week_count',
                [$todayStart, $yesterdayStart, $todayStart]
            )
            ->first();

        return [
            'bookings_today' => (int) ($bookings->today_count ?? 0),
            'bookings_yesterday' => (int) ($bookings->yesterday_count ?? 0),
            'bookings_week' => (int) ($bookings->week_count ?? 0),
            'cancelled_today' => (int) ($bookings->today_cancelled ?? 0),
            'value_today' => (float) ($bookings->today_value ?? 0),
            'value_yesterday' => (float) ($bookings->yesterday_value ?? 0),
            'value_week' => (float) ($bookings->week_value ?? 0),
            'signups_today' => (int) ($users->today_count ?? 0),
            'signups_yesterday' => (int) ($users->yesterday_count ?? 0),
            'signups_week' => (int) ($users->week_count ?? 0),
        ];
    }

    /**
     * Bookings per day for the trend chart, zero-filled so a quiet day is a gap
     * in the line rather than a missing bar.
     *
     * @return array<int, array{date: string, label: string, count: int, value: float}>
     */
    public function trend(): array
    {
        $from = now()->subDays(self::TREND_DAYS - 1)->startOfDay();

        $rows = DB::table('bookings')
            ->where('created_at', '>=', $from)
            ->selectRaw(
                'DATE(created_at) AS day,
                 COUNT(*) AS bookings,
                 SUM(CASE WHEN status != ? THEN total_price ELSE 0 END) AS value',
                ['cancelled']
            )
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];

        for ($i = 0; $i < self::TREND_DAYS; $i++) {
            $day = now()->subDays(self::TREND_DAYS - 1 - $i)->startOfDay();
            $key = $day->toDateString();
            $row = $rows[$key] ?? null;

            $series[] = [
                'date' => $key,
                'label' => $day->format('D j'),
                'count' => (int) ($row->bookings ?? 0),
                'value' => (float) ($row->value ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Everything waiting on a person, and the background machinery that fails
     * silently. A zero here is the healthy answer for every row.
     */
    public function attention(): array
    {
        $pendingUsers = DB::table('users')->where('status', User::PENDING)->count();

        $pendingWithdrawals = Schema::hasTable('withdrawal_requests')
            ? DB::table('withdrawal_requests')->where('status', 'pending')->count()
            : 0;

        $pendingListings = DB::table('listings')->where('approval_status', 'pending')->count();

        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->count()
            : 0;

        $queued = Schema::hasTable('jobs')
            ? DB::table('jobs')->count()
            : 0;

        $blockingLocks = SlotLock::query()->active()->count();

        return [
            'pending_users' => $pendingUsers,
            'pending_withdrawals' => $pendingWithdrawals,
            'pending_listings' => $pendingListings,
            'failed_jobs' => $failedJobs,
            'queued_jobs' => $queued,
            'blocking_locks' => $blockingLocks,
            'backup' => $this->lastBackup(),
        ];
    }

    /**
     * The installation's own health — the things that degrade quietly and are
     * only noticed once something breaks.
     *
     * Cached: information_schema sizes and a count over telescope_entries are
     * both too heavy to run on every page view.
     */
    public function system(): array
    {
        return Cache::remember('admin-console:system-health', self::SYSTEM_CACHE_SECONDS, function () {
            $database = DB::connection()->getDatabaseName();

            $tables = DB::table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', $database)
                ->selectRaw('TABLE_NAME AS name, DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_ROWS AS approx_rows')
                ->orderByDesc(DB::raw('DATA_LENGTH + INDEX_LENGTH'))
                ->limit(6)
                ->get()
                ->map(fn ($row) => [
                    'name' => $row->name,
                    'bytes' => (int) $row->bytes,
                    'rows' => (int) $row->approx_rows,
                ])
                ->all();

            $totalBytes = (int) DB::table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', $database)
                ->sum(DB::raw('DATA_LENGTH + INDEX_LENGTH'));

            $telescopeRows = Schema::hasTable('telescope_entries')
                ? (int) DB::table('telescope_entries')->count()
                : null;

            return [
                'database' => $database,
                'total_bytes' => $totalBytes,
                'tables' => $tables,
                'telescope_rows' => $telescopeRows,
                'telescope_enabled' => (bool) config('telescope.enabled'),
                'cache_driver' => config('cache.default'),
                'session_driver' => config('session.driver'),
                'queue_driver' => config('queue.default'),
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    /** Drops the cached system panel so the page can be refreshed on demand. */
    public function forgetSystemCache(): void
    {
        Cache::forget('admin-console:system-health');
    }

    /**
     * Warnings derived from the system panel. Each is a condition this project
     * has actually been bitten by, phrased as what to do about it.
     *
     * @return array<int, array{level: string, title: string, detail: string}>
     */
    public function warnings(array $system, array $attention): array
    {
        $warnings = [];

        if (($system['telescope_rows'] ?? 0) > 250_000) {
            $warnings[] = [
                'level' => 'danger',
                'title' => 'Telescope has '.number_format($system['telescope_rows']).' entries',
                'detail' => 'Every request writes into this table, so inserts slow as it grows and each request holds its database connection longer. Run `php artisan telescope:clear`, then either set TELESCOPE_ENABLED=false or schedule `telescope:prune`.',
            ];
        }

        foreach ([
            'cache' => $system['cache_driver'] ?? null,
            'session' => $system['session_driver'] ?? null,
        ] as $name => $driver) {
            if ($driver === 'database') {
                $warnings[] = [
                    'level' => 'warning',
                    'title' => ucfirst($name).' driver is `database`',
                    'detail' => 'On shared hosting this spends the account\'s limited MySQL connections on traffic that need not touch the database at all. Set '.strtoupper($name === 'cache' ? 'CACHE_STORE' : 'SESSION_DRIVER').'=file and clear the config cache.',
                ];
            }
        }

        if (($attention['failed_jobs'] ?? 0) > 0) {
            $warnings[] = [
                'level' => 'danger',
                'title' => number_format($attention['failed_jobs']).' failed job(s)',
                'detail' => 'Queued work that gave up. Inspect with `php artisan queue:failed`, then retry or flush.',
            ];
        }

        $backup = $attention['backup'] ?? null;

        if ($backup === null || $backup['age_hours'] === null) {
            $warnings[] = [
                'level' => 'warning',
                'title' => 'No database backup found',
                'detail' => 'The nightly `backup:run --only-db` has not produced a file. Check the scheduler cron is running.',
            ];
        } elseif ($backup['age_hours'] > 48) {
            $warnings[] = [
                'level' => 'warning',
                'title' => 'Last backup is '.(int) round($backup['age_hours'] / 24).' day(s) old',
                'detail' => 'Backups run nightly at 01:30. If this keeps growing, the scheduler cron has stopped.',
            ];
        }

        return $warnings;
    }

    /** @return array{file: string, age_hours: float|null, bytes: int}|null */
    private function lastBackup(): ?array
    {
        $disk = Storage::disk('backups');
        $directory = config('backup.backup.name');

        if (! $disk->exists($directory)) {
            return null;
        }

        $latest = collect($disk->files($directory))
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.zip'))
            ->sortByDesc(fn (string $path) => $disk->lastModified($path))
            ->first();

        if ($latest === null) {
            return null;
        }

        $modified = Carbon::createFromTimestamp($disk->lastModified($latest));

        return [
            'file' => basename($latest),
            'age_hours' => $modified->diffInRealHours(now()),
            'bytes' => $disk->size($latest),
        ];
    }
}
