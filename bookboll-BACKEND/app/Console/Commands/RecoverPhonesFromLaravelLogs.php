<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RecoverPhonesFromLaravelLogs extends Command
{
    protected $signature = 'users:recover-phones-from-logs
                            {path? : Folder path that contains laravel log files (defaults to storage/logs)}
                            {--dry-run : Preview updates without saving}
                            {--show-changes : Print each matched change}
                            {--include-non-null : Also overwrite non-null phone values}
                            {--window=120 : Minutes around log timestamp to narrow booking created_at (0 = skip time filter)}
                            {--all-logs : Include any *.log / *.log.gz, not only laravel*.log}';

    protected $description = 'Scan Laravel logs for "Booking creation request" payload and restore user phones';

    public function handle(): int
    {
        $basePath = (string) ($this->argument('path') ?: storage_path('logs'));
        $dryRun = (bool) $this->option('dry-run');
        $showChanges = (bool) $this->option('show-changes');
        $includeNonNull = (bool) $this->option('include-non-null');
        $windowMinutes = max(0, (int) $this->option('window'));
        $allLogs = (bool) $this->option('all-logs');

        if (! is_dir($basePath)) {
            $this->error("Folder does not exist: {$basePath}");
            return self::FAILURE;
        }

        $files = $this->getLogFiles($basePath, $allLogs);
        if (empty($files)) {
            $this->warn('No log files found. Try --all-logs to scan every *.log file in the folder.');
            return self::SUCCESS;
        }

        $idMap = [];
        $emailMap = [];
        $hits = 0;
        $parsed = 0;
        $noPhoneInPayload = 0;
        $unresolved = 0;

        foreach ($files as $file) {
            $this->line("Scanning: {$file}");
            $result = $this->scanFile($file, $idMap, $emailMap, $windowMinutes);
            $hits += $result['hits'];
            $parsed += $result['parsed'];
            $noPhoneInPayload += $result['no_phone'];
            $unresolved += $result['unresolved'];
        }

        $this->info("Lines mentioning booking creation: {$hits}");
        $this->info("Payloads with JSON + phone extracted: {$parsed}");
        $this->info("Payloads without usable phone: {$noPhoneInPayload}");
        $this->info("Payloads with phone but no user resolved: {$unresolved}");
        $this->info('Recovered maps: ' . count($idMap) . ' user_id->phone, ' . count($emailMap) . ' email->phone.');

        if (empty($idMap) && empty($emailMap)) {
            $this->warn('No recoverable phones found in logs.');
            return self::SUCCESS;
        }

        $users = User::query()
            ->where(function ($q) use ($idMap, $emailMap) {
                if (! empty($idMap)) {
                    $q->orWhereIn('id', array_map('intval', array_keys($idMap)));
                }
                if (! empty($emailMap)) {
                    $q->orWhereIn('email', array_keys($emailMap));
                }
            })
            ->when(! $includeNonNull, function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('phone')->orWhere('phone', '');
                });
            })
            ->get(['id', 'email', 'phone']);

        $updated = 0;
        $skipped = 0;

        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                /** @var User $user */
                $email = strtolower(trim((string) $user->email));
                $newPhone = $idMap[(int) $user->id] ?? ($emailMap[$email] ?? null);
                if (! $newPhone) {
                    $skipped++;
                    continue;
                }

                if ((string) $user->phone === (string) $newPhone) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    if ($showChanges) {
                        $this->line("[DRY-RUN] user_id={$user->id} email={$user->email} phone: '{$user->phone}' => '{$newPhone}'");
                    }
                    $updated++;
                    continue;
                }

                $oldPhone = $user->phone;
                $user->phone = $newPhone;
                $user->save();
                if ($showChanges) {
                    $this->line("[UPDATED] user_id={$user->id} email={$user->email} phone: '{$oldPhone}' => '{$newPhone}'");
                }
                $updated++;
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Recovery failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Recovery summary:');
        $this->line("- Updated: {$updated}");
        $this->line("- Skipped: {$skipped}");
        $this->line('- Mode: ' . ($dryRun ? 'DRY-RUN (no writes)' : 'LIVE'));
        $this->line('- Scope: ' . ($includeNonNull ? 'all matched users' : 'only users with null/empty phone'));

        return self::SUCCESS;
    }

    private function getLogFiles(string $basePath, bool $allLogs): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath));
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $name = strtolower($file->getFilename());
            if (! str_ends_with($name, '.log') && ! str_ends_with($name, '.log.gz')) {
                continue;
            }
            if ($allLogs) {
                $out[] = $file->getPathname();
                continue;
            }
            if (str_starts_with($name, 'laravel') && str_contains($name, '.log')) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @return array{hits:int, parsed:int, no_phone:int, unresolved:int}
     */
    private function scanFile(string $path, array &$idMap, array &$emailMap, int $windowMinutes): array
    {
        $hits = 0;
        $parsed = 0;
        $noPhone = 0;
        $unresolved = 0;

        $isGz = str_ends_with(strtolower($path), '.gz');
        $h = $isGz ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if (! $h) {
            return [
                'hits' => $hits,
                'parsed' => $parsed,
                'no_phone' => $noPhone,
                'unresolved' => $unresolved,
            ];
        }

        while (true) {
            $line = $isGz ? gzgets($h) : fgets($h);
            if ($line === false) {
                break;
            }

            $lower = strtolower($line);
            if (str_contains($lower, 'booking creation request')) {
                $buffer = $line;
                $guard = 0;
                while ($this->needsMoreJson($buffer) && $guard < 200) {
                    $next = $isGz ? gzgets($h) : fgets($h);
                    if ($next === false) {
                        break;
                    }
                    $buffer .= $next;
                    $guard++;
                }
                $hits++;
                $r = $this->extractFromLogBlock($buffer, $idMap, $emailMap, $windowMinutes);
                $parsed += $r['parsed'];
                $noPhone += $r['no_phone'];
                $unresolved += $r['unresolved'];
            }
        }

        if ($isGz) {
            gzclose($h);
        } else {
            fclose($h);
        }

        return [
            'hits' => $hits,
            'parsed' => $parsed,
            'no_phone' => $noPhone,
            'unresolved' => $unresolved,
        ];
    }

    private function needsMoreJson(string $buffer): bool
    {
        if (! str_contains(strtolower($buffer), 'booking creation request')) {
            return false;
        }
        $open = substr_count($buffer, '{');
        $close = substr_count($buffer, '}');

        return $open > $close && $open < 500;
    }

    /**
     * @return array{parsed:int, no_phone:int, unresolved:int}
     */
    private function extractFromLogBlock(string $block, array &$idMap, array &$emailMap, int $windowMinutes): array
    {
        $parsed = 0;
        $noPhone = 0;
        $unresolved = 0;

        $jsonText = $this->extractJsonObjectAfterMarker($block, 'Booking creation request');
        if ($jsonText === null) {
            return ['parsed' => 0, 'no_phone' => 1, 'unresolved' => 0];
        }

        $decoded = json_decode($jsonText, true);
        if (! is_array($decoded)) {
            return ['parsed' => 0, 'no_phone' => 1, 'unresolved' => 0];
        }

        $req = $decoded['Request'] ?? $decoded['request'] ?? null;
        if (! is_array($req)) {
            return ['parsed' => 0, 'no_phone' => 1, 'unresolved' => 0];
        }

        $phoneRaw = $req['phone'] ?? null;
        $phone = is_string($phoneRaw) ? $this->normalizePhone($phoneRaw) : null;
        if (! $phone) {
            return ['parsed' => 0, 'no_phone' => 1, 'unresolved' => 0];
        }

        $parsed = 1;

        $userId = isset($req['user_id']) ? (int) $req['user_id'] : 0;
        $email = isset($req['email']) ? strtolower(trim((string) $req['email'])) : '';

        if ($userId > 0) {
            $idMap[$userId] = $phone;
        }
        if ($email !== '') {
            $emailMap[$email] = $phone;
        }

        if ($userId > 0 || $email !== '') {
            return ['parsed' => $parsed, 'no_phone' => 0, 'unresolved' => 0];
        }

        $loggedAt = $this->extractLogTimestampFromBlock($block);
        $resolved = $this->resolveUserIdFromRequest($req, $loggedAt, $windowMinutes);
        if ($resolved) {
            $idMap[$resolved] = $phone;
            return ['parsed' => $parsed, 'no_phone' => 0, 'unresolved' => 0];
        }

        $unresolved = 1;

        return ['parsed' => $parsed, 'no_phone' => 0, 'unresolved' => $unresolved];
    }

    private function extractJsonObjectAfterMarker(string $block, string $marker): ?string
    {
        $pos = stripos($block, $marker);
        if ($pos === false) {
            return null;
        }
        $from = strpos($block, '{', $pos);
        if ($from === false) {
            return null;
        }
        $len = strlen($block);
        $depth = 0;
        for ($i = $from; $i < $len; $i++) {
            $c = $block[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($block, $from, $i - $from + 1);
                }
            }
        }

        return null;
    }

    private function extractLogTimestampFromBlock(string $block): ?Carbon
    {
        if (preg_match('/^\[([0-9:\-\s]+)\]/', $block, $m)) {
            try {
                return Carbon::createFromFormat('Y-m-d H:i:s', trim($m[1]));
            } catch (\Throwable) {
                // continue
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $req
     */
    private function resolveUserIdFromRequest(array $req, ?Carbon $loggedAt, int $windowMinutes): ?int
    {
        $paymentIntentId = isset($req['payment_intent_id']) ? trim((string) $req['payment_intent_id']) : '';
        if ($paymentIntentId !== '') {
            $payment = Payment::query()->where('payment_intent_id', $paymentIntentId)->value('user_id');
            if ($payment) {
                return (int) $payment;
            }
        }

        $date = isset($req['date']) ? trim((string) $req['date']) : '';
        $venueId = isset($req['venue_id']) ? (int) $req['venue_id'] : 0;
        $listingId = isset($req['listing_id']) ? (int) $req['listing_id'] : 0;
        $startTime = isset($req['start_time']) ? trim((string) $req['start_time']) : '';
        $endTime = isset($req['end_time']) ? trim((string) $req['end_time']) : '';

        if ($date === '' || $venueId <= 0 || $listingId <= 0 || $startTime === '' || $endTime === '') {
            return null;
        }

        try {
            $startNorm = Carbon::parse($startTime)->format('H:i:s');
            $endNorm = Carbon::parse($endTime)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }

        $booking = $this->findBookingBySlot($date, $venueId, $listingId, $startNorm, $endNorm, $loggedAt, $windowMinutes);

        return $booking && $booking->user_id ? (int) $booking->user_id : null;
    }

    private function findBookingBySlot(
        string $date,
        int $venueId,
        int $listingId,
        string $startNorm,
        string $endNorm,
        ?Carbon $loggedAt,
        int $windowMinutes
    ): ?Booking {
        $base = Booking::query()
            ->whereDate('date', $date)
            ->where('venue_id', $venueId)
            ->where('listing_id', $listingId)
            ->whereRaw('TIME(start_time) = TIME(?)', [$startNorm])
            ->whereRaw('TIME(end_time) = TIME(?)', [$endNorm]);

        if ($loggedAt && $windowMinutes > 0) {
            $from = $loggedAt->copy()->subMinutes($windowMinutes);
            $to = $loggedAt->copy()->addMinutes($windowMinutes);
            $q = clone $base;
            $q->whereBetween('created_at', [$from, $to]);

            $hit = $q->orderByDesc('id')->first();
            if ($hit) {
                return $hit;
            }
        }

        return $base->orderByDesc('id')->first();
    }

    private function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        $phone = ltrim($phone, "'");
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        if (is_numeric($phone) && (stripos($phone, 'e') !== false || stripos($phone, 'E') !== false)) {
            $phone = number_format((float) $phone, 0, '', '');
        }

        $phone = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        if ($phone === '') {
            return null;
        }

        if (! str_starts_with($phone, '+')) {
            if (str_starts_with($phone, '974')) {
                $phone = '+' . $phone;
            } elseif (strlen($phone) >= 8) {
                $phone = '+974' . $phone;
            }
        }

        return $phone;
    }
}
