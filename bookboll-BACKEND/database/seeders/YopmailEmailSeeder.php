<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class YopmailEmailSeeder extends Seeder
{
    public function run(): void
    {
        if (! $this->takeDatabaseBackup()) {
            return;
        }

        $usersSanitized = $this->sanitizeUsers();
        $usersUpdated = $this->rewriteEmails('users');

        $this->command->info("YopmailEmailSeeder: sanitized {$usersSanitized} user(s), updated {$usersUpdated} user email(s).");
    }

    protected function takeDatabaseBackup(): bool
    {
        $this->command->info('Creating database backup before rewriting emails...');

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
                $this->command->error('Database backup failed. Email rewrite aborted.');

                return false;
            }

            $this->command->info('Database backup completed successfully.');

            return true;
        } catch (Throwable $exception) {
            $this->command->error('Database backup failed: '.$exception->getMessage());

            return false;
        }
    }

    protected function sanitizeUsers(): int
    {
        if (! Schema::hasTable('users')) {
            $this->command->warn('Skipping users sanitization: users table not found.');

            return 0;
        }

        $updates = [];

        if (Schema::hasColumn('users', 'password')) {
            $updates['password'] = Hash::make('12345678');
        }

        foreach ([
            'stripe_id',
            'pm_type',
            'pm_last_four',
            'trial_ends_at',
        ] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $updates[$column] = null;
            }
        }

        if ($updates === []) {
            $this->command->warn('Skipping users sanitization: no applicable columns found.');

            return 0;
        }

        $count = DB::table('users')->count();

        DB::table('users')->update($updates);

        return $count;
    }

    protected function rewriteEmails(string $table): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'email')) {
            $this->command->warn("Skipping {$table}: table or email column not found.");

            return 0;
        }

        $updated = 0;

        $query = DB::table($table)->whereNotNull('email');

        foreach (['%@yopmail.com', '%@bookboll.com'] as $domain) {
            $query->where('email', 'not like', $domain);
        }

        $query->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, &$updated) {
                foreach ($rows as $row) {
                    $localPart = Str::before($row->email, '@');

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['email' => "{$localPart}_{$row->id}@yopmail.com"]);

                    $updated++;
                }
            });

        return $updated;
    }
}
