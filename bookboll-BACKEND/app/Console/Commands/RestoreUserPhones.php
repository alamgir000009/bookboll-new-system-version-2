<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class RestoreUserPhones extends Command
{
    protected $signature = 'users:restore-phones
                            {--dry-run : Preview updates without saving}
                            {--show-changes : Print each matched change}
                            {--from-stripe : Enrich missing phones from Stripe customers}
                            {--include-non-null : Also overwrite non-null phone values}
                            {--users-file=public/bookboll_live_users.csv : Primary CSV source}
                            {--bookings-file=public/bookboll_live_bookings.csv : Secondary CSV source}';

    protected $description = 'Restore user phone numbers from CSV exports by matching user email';

    public function handle(): int
    {
        $usersCsvPath    = base_path((string) $this->option('users-file'));
        $bookingsCsvPath = base_path((string) $this->option('bookings-file'));
        $dryRun          = (bool) $this->option('dry-run');
        $showChanges     = (bool) $this->option('show-changes');
        $fromStripe      = (bool) $this->option('from-stripe');
        $includeNonNull  = (bool) $this->option('include-non-null');

        $map = [];

        $usersImported = $this->loadUsersCsvMap($usersCsvPath, $map);
        $bookingsAdded = $this->loadBookingsCsvMap($bookingsCsvPath, $map);
        $stripeAdded   = $fromStripe ? $this->loadStripeMap($map) : 0;

        if (empty($map)) {
            $this->error('No recoverable phone data found in configured sources.');
            return self::FAILURE;
        }

        $this->info("Prepared phone map for " . count($map) . " emails (users source: {$usersImported}, bookings source added: {$bookingsAdded}, stripe source added: {$stripeAdded}).");

        $query = User::query()->whereIn('email', array_keys($map));
        if (! $includeNonNull) {
            $query->where(function ($q) {
                $q->whereNull('phone')->orWhere('phone', '');
            });
        }

        $userIds = $query->pluck('id')->all();
        $users   = User::whereIn('id', $userIds)->get(['id', 'email', 'phone']);
        $updated = 0;
        $skipped = 0;
        $errors  = 0;

        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                /** @var User $user */
                $newPhone = $map[strtolower((string) $user->email)] ?? null;
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

                $oldPhone    = $user->phone;
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
            $errors++;
            Log::error('users:restore-phones failed', ['error' => $e->getMessage()]);
            $this->error("Restore failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Restore summary:');
        $this->line("- Updated: {$updated}");
        $this->line("- Skipped: {$skipped}");
        $this->line("- Errors: {$errors}");
        $this->line('- Mode: ' . ($dryRun ? 'DRY-RUN (no writes)' : 'LIVE'));
        $this->line('- Scope: ' . ($includeNonNull ? 'all matched users' : 'only users with null/empty phone'));

        return self::SUCCESS;
    }

    private function loadStripeMap(array &$map): int
    {
        $secret = $this->resolveStripeSecret();
        if (! $secret) {
            $this->warn('Stripe secret key not found. Skipping Stripe source.');
            return 0;
        }

        $stripe = new StripeClient($secret);
        $added  = 0;

        $users = User::query()
            ->whereNotNull('stripe_id')
            ->where(function ($q) {
                $q->whereNull('phone')->orWhere('phone', '');
            })
            ->get(['email', 'stripe_id']);

        foreach ($users as $user) {
            /** @var User $user */
            $email = strtolower(trim((string) $user->email));
            if ($email === '' || isset($map[$email])) {
                continue;
            }

            try {
                $customer = $stripe->customers->retrieve($user->stripe_id, []);
                $rawPhone = (string) ($customer->phone ?? ($customer->shipping->phone ?? ''));
                $phone    = $this->normalizePhone($rawPhone);
                if ($phone) {
                    $map[$email] = $phone;
                    $added++;
                }
            } catch (\Throwable $e) {
                Log::warning('Stripe customer phone lookup failed in users:restore-phones', [
                    'email' => $email,
                    'stripe_id' => $user->stripe_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $added;
    }

    private function resolveStripeSecret(): ?string
    {
        $configSecret = trim((string) config('services.stripe.secret'));
        if ($configSecret !== '') {
            return $configSecret;
        }

        $settingSecret = trim((string) Setting::where('key', 'stripe_secret_key')->value('value'));
        if ($settingSecret !== '') {
            return $settingSecret;
        }

        return null;
    }

    private function loadUsersCsvMap(string $path, array &$map): int
    {
        if (! is_file($path)) {
            $this->warn("Users CSV not found: {$path}");
            return 0;
        }

        $count = 0;
        $fp    = fopen($path, 'r');
        if (! $fp) {
            $this->warn("Unable to read users CSV: {$path}");
            return 0;
        }

        // Skip header
        fgets($fp);

        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // This export can be malformed after the first 3 columns.
            // Capture first 3 values: name,email,phone and ignore the rest.
            if (! preg_match('/^([^,]*),([^,]*),([^,]*),?.*$/', $line, $matches)) {
                continue;
            }

            $email = strtolower(trim((string) ($matches[2] ?? '')));
            $phone = $this->normalizePhone((string) ($matches[3] ?? ''));

            if ($email === '' || $phone === null) {
                continue;
            }

            if (! isset($map[$email])) {
                $map[$email] = $phone;
                $count++;
            }
        }

        fclose($fp);
        return $count;
    }

    private function loadBookingsCsvMap(string $path, array &$map): int
    {
        if (! is_file($path)) {
            $this->warn("Bookings CSV not found: {$path}");
            return 0;
        }

        $fp = fopen($path, 'r');
        if (! $fp) {
            $this->warn("Unable to read bookings CSV: {$path}");
            return 0;
        }

        $header = fgetcsv($fp);
        if (! is_array($header)) {
            fclose($fp);
            return 0;
        }

        $headerMap = [];
        foreach ($header as $idx => $column) {
            $headerMap[strtolower(trim((string) $column))] = $idx;
        }

        $emailIdx = $headerMap['user email'] ?? null;
        $phoneIdx = $headerMap['user phone'] ?? null;
        if ($emailIdx === null || $phoneIdx === null) {
            fclose($fp);
            $this->warn('Bookings CSV header does not contain "User email" and "User phone".');
            return 0;
        }

        $added = 0;
        while (($row = fgetcsv($fp)) !== false) {
            $email = strtolower(trim((string) ($row[$emailIdx] ?? '')));
            $phone = $this->normalizePhone((string) ($row[$phoneIdx] ?? ''));

            if ($email === '' || $phone === null) {
                continue;
            }

            if (! isset($map[$email])) {
                $map[$email] = $phone;
                $added++;
            }
        }

        fclose($fp);
        return $added;
    }

    private function normalizePhone(string $phone): ?string
    {
        $phone = trim($phone);
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

