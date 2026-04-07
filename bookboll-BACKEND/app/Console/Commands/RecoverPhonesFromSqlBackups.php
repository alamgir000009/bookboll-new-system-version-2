<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RecoverPhonesFromSqlBackups extends Command
{
    protected $signature = 'users:recover-phones-from-backups
                            {path : Folder path that contains SQL backups}
                            {--dry-run : Preview updates without saving}
                            {--show-changes : Print each matched change}
                            {--include-non-null : Also overwrite non-null phone values}';

    protected $description = 'Scan SQL backup files and restore missing user phone numbers by email';

    public function handle(): int
    {
        $basePath = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $showChanges = (bool) $this->option('show-changes');
        $includeNonNull = (bool) $this->option('include-non-null');

        if (! is_dir($basePath)) {
            $this->error("Backup folder does not exist: {$basePath}");
            return self::FAILURE;
        }

        $files = $this->getBackupFiles($basePath);
        if (empty($files)) {
            $this->error('No .sql or .sql.gz files found in the provided folder.');
            return self::FAILURE;
        }

        $map = [];
        $idMap = [];
        $processedFiles = 0;
        foreach ($files as $filePath) {
            $processedFiles++;
            $this->line("Scanning: {$filePath}");
            $this->scanFile($filePath, $map, $idMap);
        }

        if (empty($map) && empty($idMap)) {
            $this->warn('No recoverable email/phone or user_id/phone pairs found in backup files.');
            return self::SUCCESS;
        }

        $this->info("Extracted " . count($map) . " email->phone pairs and " . count($idMap) . " user_id->phone pairs from {$processedFiles} backup files.");

        $userIdsFromMap = array_map('intval', array_keys($idMap));
        $users = User::query()
            ->when(! empty($map), fn($q) => $q->orWhereIn('email', array_keys($map)))
            ->when(! empty($userIdsFromMap), fn($q) => $q->orWhereIn('id', $userIdsFromMap))
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
                $newPhone = $map[$email] ?? ($idMap[(int) $user->id] ?? null);
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

    private function getBackupFiles(string $basePath): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath));
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $name = strtolower($file->getFilename());
            if (str_ends_with($name, '.sql') || str_ends_with($name, '.sql.gz')) {
                $out[] = $file->getPathname();
            }
        }
        return $out;
    }

    private function scanFile(string $path, array &$map, array &$idMap): void
    {
        if (str_ends_with(strtolower($path), '.gz')) {
            $handle = @gzopen($path, 'rb');
            if (! $handle) {
                return;
            }
            $statement = '';
            while (! gzeof($handle)) {
                $line = gzgets($handle);
                if ($line === false) {
                    break;
                }
                $this->consumeLine($line, $statement, $map, $idMap);
            }
            gzclose($handle);
            return;
        }

        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return;
        }
        $statement = '';
        while (! feof($handle)) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $this->consumeLine($line, $statement, $map, $idMap);
        }
        fclose($handle);
    }

    private function consumeLine(string $line, string &$statement, array &$map, array &$idMap): void
    {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '/*!')) {
            return;
        }

        $statement .= $line;
        if (str_contains($line, ';')) {
            $this->extractFromStatement($statement, $map, $idMap);
            $statement = '';
        }
    }

    private function extractFromStatement(string $sql, array &$map, array &$idMap): void
    {
        $this->extractFromBookingPayload($sql, $idMap);

        $lower = strtolower($sql);
        if (! str_contains($lower, 'users')) {
            return;
        }

        // INSERT INTO users (...)
        if (preg_match('/insert\s+into\s+`?users`?\s*\((.*?)\)\s*values\s*(.*)\s*;?$/is', $sql, $m)) {
            $columnsRaw = $m[1];
            $valuesRaw = $m[2];
            $columns = array_map(fn($c) => strtolower(trim((string) str_replace('`', '', $c))), explode(',', $columnsRaw));
            $emailIdx = array_search('email', $columns, true);
            $phoneIdx = array_search('phone', $columns, true);
            if ($emailIdx === false || $phoneIdx === false) {
                return;
            }

            foreach ($this->splitTuples($valuesRaw) as $tuple) {
                $fields = $this->splitFields($tuple);
                if (! isset($fields[$emailIdx], $fields[$phoneIdx])) {
                    continue;
                }
                $email = strtolower(trim((string) $this->parseSqlValue($fields[$emailIdx])));
                $phone = $this->normalizePhone((string) $this->parseSqlValue($fields[$phoneIdx]));
                if ($email !== '' && $phone) {
                    $map[$email] = $phone;
                }
            }
            return;
        }

        // UPDATE users SET phone='...' WHERE email='...'
        if (
            preg_match('/update\s+`?users`?\s+set\s+(.*?)\s+where\s+(.*)\s*;?$/is', $sql, $m)
            && preg_match('/phone\s*=\s*(\'(?:\\\\.|[^\'])*\'|null|[^,\s]+)/i', $m[1], $pm)
            && preg_match('/email\s*=\s*(\'(?:\\\\.|[^\'])*\'|null|[^,\s]+)/i', $m[2], $em)
        ) {
            $email = strtolower(trim((string) $this->parseSqlValue($em[1])));
            $phone = $this->normalizePhone((string) $this->parseSqlValue($pm[1]));
            if ($email !== '' && $phone) {
                $map[$email] = $phone;
            }
        }
    }

    private function extractFromBookingPayload(string $text, array &$idMap): void
    {
        if (! str_contains($text, 'Booking creation request')) {
            return;
        }

        if (
            preg_match_all('/"user_id"\s*:\s*"?(\d+)"?.*?"phone"\s*:\s*"([^"]+)"/is', $text, $matches, PREG_SET_ORDER)
            || preg_match_all('/"phone"\s*:\s*"([^"]+)".*?"user_id"\s*:\s*"?(\d+)"?/is', $text, $matches, PREG_SET_ORDER)
        ) {
            foreach ($matches as $m) {
                $first = $m[1] ?? '';
                $second = $m[2] ?? '';

                if (ctype_digit((string) $first)) {
                    $userId = (int) $first;
                    $phone = $this->normalizePhone((string) $second);
                } elseif (ctype_digit((string) $second)) {
                    $userId = (int) $second;
                    $phone = $this->normalizePhone((string) $first);
                } else {
                    continue;
                }

                if ($userId > 0 && $phone) {
                    $idMap[$userId] = $phone;
                }
            }
        }
    }

    private function splitTuples(string $valuesRaw): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $inQuote = false;
        $len = strlen($valuesRaw);

        for ($i = 0; $i < $len; $i++) {
            $ch = $valuesRaw[$i];
            $prev = $i > 0 ? $valuesRaw[$i - 1] : '';

            if ($ch === "'" && $prev !== '\\') {
                $inQuote = ! $inQuote;
            }

            if (! $inQuote && $ch === '(') {
                $depth++;
            }
            if ($depth > 0) {
                $buf .= $ch;
            }
            if (! $inQuote && $ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $out[] = trim($buf, " \t\n\r\0\x0B(),");
                    $buf = '';
                }
            }
        }
        return $out;
    }

    private function splitFields(string $tuple): array
    {
        $out = [];
        $buf = '';
        $inQuote = false;
        $len = strlen($tuple);

        for ($i = 0; $i < $len; $i++) {
            $ch = $tuple[$i];
            $prev = $i > 0 ? $tuple[$i - 1] : '';
            if ($ch === "'" && $prev !== '\\') {
                $inQuote = ! $inQuote;
            }
            if (! $inQuote && $ch === ',') {
                $out[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }

    private function parseSqlValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }
        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
            $value = str_replace("\\'", "'", $value);
            $value = str_replace("''", "'", $value);
        }
        return $value;
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

