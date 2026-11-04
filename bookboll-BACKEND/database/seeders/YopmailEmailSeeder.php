<?php

namespace Database\Seeders;

use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Makes a live database import safe for local testing.
 *
 * Run it alone (it is deliberately NOT in DatabaseSeeder):
 *
 *   php artisan db:seed --class=YopmailEmailSeeder
 *
 * What it does, in order:
 *  1. refuses to run unless APP_ENV=local
 *  2. takes a DB-only backup (spatie backup:run) and aborts if that fails
 *  3. users: password "12345678", email -> local_<id>@yopmail.com, Cashier/social ids cleared
 *  4. every other email column follows the SAME old->new mapping, so columns the code
 *     matches against users.email (class_subscriptions.customer_email, entry_passes, ...)
 *     still line up. Emails that belong to no user get a stable hash-based yopmail address.
 *  5. phone / WhatsApp numbers masked everywhere
 *  6. per-business SMTP + WhatsApp credentials, Stripe ids, bank details cleared
 *  7. sessions, API tokens, OTPs, queued jobs, push subscriptions, telescope entries truncated
 *
 * Emails on the domains in KEEP_DOMAINS are left untouched (staff accounts, already-sanitized rows),
 * which also makes the seeder safe to run more than once.
 */
class YopmailEmailSeeder extends Seeder
{
    protected const PASSWORD = '12345678';

    protected const YOPMAIL = 'yopmail.com';

    protected const KEEP_DOMAINS = ['yopmail.com', 'bookboll.com'];

    protected const CHUNK = 500;

    /** Email columns that must follow the users mapping: [table, column]. */
    protected const EMAIL_COLUMNS = [
        ['class_subscriptions', 'customer_email'],
        ['entry_passes', 'customer_email'],
        ['offer_redemptions', 'customer_email'],
        ['promocode_redemptions', 'customer_email'],
        ['email_outbound', 'to_email'],
        ['audit_logs', 'actor_email'],
        ['contacts', 'email'],
        ['subscribers', 'email'],
        ['registration_requests', 'email'],
        ['deleted_accounts', 'email'],
    ];

    /** JSON columns holding an array of emails: [table, column]. */
    protected const EMAIL_JSON_COLUMNS = [
        ['promocodes', 'target_emails'],
    ];

    /**
     * Phone columns: [table, column, prefix].
     * "+9715..." for display numbers, digits-only "9715..." for WhatsApp numbers
     * (whatsapp_* tables store digits only, country code, no plus).
     */
    protected const PHONE_COLUMNS = [
        ['users', 'phone', '+9715'],
        ['businesses', 'phone', '+9715'],
        ['deleted_accounts', 'phone', '+9715'],
        ['hosted_games', 'host_phone', '+9715'],
        ['hosted_games', 'host_whatsapp', '9715'],
        ['game_join_requests', 'joiner_phone', '+9715'],
        ['game_join_requests', 'joiner_whatsapp', '9715'],
        ['whatsapp_outbound', 'to_number', '9715'],
        ['whatsapp_inbound', 'from_number', '9715'],
    ];

    /** Columns set to NULL because they hold credentials, Stripe ids, bank or social-login data. */
    protected const NULL_COLUMNS = [
        'users' => ['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at', 'provider_id', 'remember_token'],
        'businesses' => ['smtp_config', 'whatsapp_config'],
        'host_payout_accounts' => ['secret_key', 'publishable_key', 'stripe_account_id'],
        'payment_methods' => ['stripe_payment_method_id'],
        'class_subscriptions' => ['stripe_subscription_id', 'stripe_customer_id', 'stripe_checkout_session_id'],
        'business_subscriptions' => ['stripe_subscription_id', 'stripe_customer_id', 'stripe_checkout_session_id'],
        'payment_accounts' => ['iban', 'swift', 'account_holder'],
        'withdrawal_requests' => ['payout_details'],
        'deleted_accounts' => ['user_snapshot', 'ip_address', 'user_agent'],
    ];

    /** JSON columns replaced with a marker (NOT NULL columns that hold raw webhook payloads). */
    protected const JSON_PLACEHOLDER_COLUMNS = [
        ['whatsapp_inbound', 'raw'],
    ];

    /** Tables emptied outright: live tokens, sessions, pending jobs, device subscriptions, debug data. */
    protected const TRUNCATE_TABLES = [
        'personal_access_tokens',
        'sessions',
        'password_reset_tokens',
        'email_verification_otps',
        'stripe_sessions',
        'onesignal_subscriptions',
        'jobs',
        'failed_jobs',
        'whatsapp_queued_campaigns',
        'telescope_entries_tags',
        'telescope_entries',
        'telescope_monitoring',
    ];

    /** lower(old email) => new email, built while rewriting users. */
    protected array $emailMap = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command->error('YopmailEmailSeeder only runs with APP_ENV=local (current: '.app()->environment().'). Aborted.');

            return;
        }

        if (! $this->takeDatabaseBackup()) {
            return;
        }

        $this->sanitizeUsers();
        $this->rewriteUserEmails();
        $this->rewriteLinkedEmails();
        $this->rewriteJsonEmails();
        $this->maskPhones();
        $this->clearSecrets();
        $this->replaceJsonPayloads();
        $this->truncateTables();

        $this->command->info('YopmailEmailSeeder: done. Every user can now log in with password "'.self::PASSWORD.'".');
    }

    // ---------------------------------------------------------------- backup

    protected function takeDatabaseBackup(): bool
    {
        $this->command->info('Creating database backup before sanitizing...');

        try {
            $exitCode = Artisan::call('backup:run', [
                '--only-db' => true,
                '--disable-notifications' => true,
            ]);

            $output = trim(Artisan::output());

            if ($output !== '') {
                $this->command->line($output);
            }

            if ($exitCode !== 0) {
                $this->command->error('Database backup failed. Sanitizing aborted.');

                return false;
            }

            $this->command->info('Database backup completed successfully.');

            return true;
        } catch (Throwable $exception) {
            $this->command->error('Database backup failed: '.$exception->getMessage());

            return false;
        }
    }

    // ----------------------------------------------------------------- users

    protected function sanitizeUsers(): void
    {
        if (! Schema::hasTable('users')) {
            $this->command->warn('Skipping users: table not found.');

            return;
        }

        if (! Schema::hasColumn('users', 'password')) {
            $this->command->warn('Skipping password reset: users.password not found.');

            return;
        }

        $count = DB::table('users')->update(['password' => Hash::make(self::PASSWORD)]);

        $this->command->info("users: password reset for {$count} user(s).");
    }

    protected function rewriteUserEmails(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'email')) {
            $this->command->warn('Skipping users emails: table or email column not found.');

            return;
        }

        $updated = 0;

        $this->rewritableEmailQuery('users', 'email')
            ->chunkById(self::CHUNK, function ($rows) use (&$updated) {
                foreach ($rows as $row) {
                    $new = Str::before($row->email, '@').'_'.$row->id.'@'.self::YOPMAIL;

                    $this->emailMap[strtolower($row->email)] = $new;

                    DB::table('users')->where('id', $row->id)->update(['email' => $new]);
                    $updated++;
                }
            });

        $this->command->info("users: rewrote {$updated} email(s).");
    }

    // ---------------------------------------------------- linked email columns

    protected function rewriteLinkedEmails(): void
    {
        foreach (self::EMAIL_COLUMNS as [$table, $column]) {
            if (! $this->hasColumns($table, [$column, 'id'])) {
                $this->command->warn("Skipping {$table}.{$column}: table or column not found.");

                continue;
            }

            $updated = 0;

            $this->rewritableEmailQuery($table, $column)
                ->chunkById(self::CHUNK, function ($rows) use ($table, $column, &$updated) {
                    foreach ($rows as $row) {
                        $new = $this->mapEmail($row->{$column});

                        try {
                            DB::table($table)->where('id', $row->id)->update([$column => $new]);
                        } catch (QueryException $exception) {
                            // Unique index collision (e.g. subscribers.email). Fall back to an id-based address.
                            $new = Str::before($row->{$column}, '@').'_'.$row->id.'@'.self::YOPMAIL;
                            DB::table($table)->where('id', $row->id)->update([$column => $new]);
                        }

                        $updated++;
                    }
                });

            $this->command->info("{$table}.{$column}: rewrote {$updated} email(s).");
        }
    }

    protected function rewriteJsonEmails(): void
    {
        foreach (self::EMAIL_JSON_COLUMNS as [$table, $column]) {
            if (! $this->hasColumns($table, [$column, 'id'])) {
                $this->command->warn("Skipping {$table}.{$column}: table or column not found.");

                continue;
            }

            $updated = 0;

            DB::table($table)
                ->select(['id', $column])
                ->whereNotNull($column)
                ->orderBy('id')
                ->chunkById(self::CHUNK, function ($rows) use ($table, $column, &$updated) {
                    foreach ($rows as $row) {
                        $emails = json_decode((string) $row->{$column}, true);

                        if (! is_array($emails) || $emails === []) {
                            continue;
                        }

                        $mapped = array_values(array_unique(array_map(
                            fn ($email) => is_string($email) && str_contains($email, '@') ? $this->mapEmail($email) : $email,
                            $emails
                        )));

                        if ($mapped === $emails) {
                            continue;
                        }

                        DB::table($table)->where('id', $row->id)->update([$column => json_encode($mapped)]);
                        $updated++;
                    }
                });

            $this->command->info("{$table}.{$column}: rewrote {$updated} row(s).");
        }
    }

    /**
     * Same old address always becomes the same new address, across every table:
     * users use local_<id>@yopmail.com, anything that is not a user gets a stable hash suffix.
     */
    protected function mapEmail(string $email): string
    {
        $email = trim($email);
        $key = strtolower($email);

        if ($this->shouldKeep($key)) {
            return $email;
        }

        if (isset($this->emailMap[$key])) {
            return $this->emailMap[$key];
        }

        $local = Str::before($key, '@') ?: 'user';

        return $this->emailMap[$key] = $local.'_'.substr(md5($key), 0, 8).'@'.self::YOPMAIL;
    }

    protected function shouldKeep(string $email): bool
    {
        foreach (self::KEEP_DOMAINS as $domain) {
            if (Str::endsWith($email, '@'.$domain)) {
                return true;
            }
        }

        return ! str_contains($email, '@');
    }

    protected function rewritableEmailQuery(string $table, string $column)
    {
        $query = DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, 'like', '%@%');

        foreach (self::KEEP_DOMAINS as $domain) {
            $query->where($column, 'not like', '%@'.$domain);
        }

        return $query->orderBy('id');
    }

    // ---------------------------------------------------------------- phones

    protected function maskPhones(): void
    {
        $isMysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        foreach (self::PHONE_COLUMNS as [$table, $column, $prefix]) {
            if (! $this->hasColumns($table, [$column, 'id'])) {
                $this->command->warn("Skipping {$table}.{$column}: table or column not found.");

                continue;
            }

            $query = DB::table($table)->whereNotNull($column)->where($column, '!=', '');

            if ($isMysql) {
                // One statement: <prefix> + 8 digits derived from the row id, unique within the table.
                $updated = $query->update([
                    $column => DB::raw("CONCAT('{$prefix}', LPAD(id % 100000000, 8, '0'))"),
                ]);
            } else {
                $updated = 0;

                $query->select(['id'])->orderBy('id')
                    ->chunkById(self::CHUNK, function ($rows) use ($table, $column, $prefix, &$updated) {
                        foreach ($rows as $row) {
                            DB::table($table)->where('id', $row->id)->update([
                                $column => $prefix.str_pad((string) ($row->id % 100000000), 8, '0', STR_PAD_LEFT),
                            ]);
                            $updated++;
                        }
                    });
            }

            $this->command->info("{$table}.{$column}: masked {$updated} number(s).");
        }
    }

    // --------------------------------------------------------------- secrets

    protected function clearSecrets(): void
    {
        foreach (self::NULL_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->command->warn("Skipping {$table}: table not found.");

                continue;
            }

            $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));

            if ($existing === []) {
                $this->command->warn("Skipping {$table}: none of the expected columns found.");

                continue;
            }

            $updated = DB::table($table)->update(array_fill_keys($existing, null));

            $this->command->info("{$table}: cleared ".implode(', ', $existing)." on {$updated} row(s).");
        }
    }

    protected function replaceJsonPayloads(): void
    {
        foreach (self::JSON_PLACEHOLDER_COLUMNS as [$table, $column]) {
            if (! $this->hasColumns($table, [$column])) {
                $this->command->warn("Skipping {$table}.{$column}: table or column not found.");

                continue;
            }

            $updated = DB::table($table)->update([$column => json_encode(['sanitized' => true])]);

            $this->command->info("{$table}.{$column}: replaced payload on {$updated} row(s).");
        }
    }

    // -------------------------------------------------------------- truncate

    protected function truncateTables(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::TRUNCATE_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::table($table)->truncate();
                $this->command->info("{$table}: truncated.");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    // --------------------------------------------------------------- helpers

    protected function hasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
