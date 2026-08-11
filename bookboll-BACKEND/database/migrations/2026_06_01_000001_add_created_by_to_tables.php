<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'users',
        'venues',
        'listings',
        'add_ons',
        'resources',
        'schedules',
        'special_days',
        'time_blocks',
        'sports_types',
        'sports_categories',
        'pricing_groups',
        'assets',
        'tags',
        'post_categories',
        'posts',
        'promocodes',
        'gift_cards',
        'loyalty_tiers',
        'settings',
        'reviews',
        'registration_requests',
        'subscribers',
        'contacts',
        'payments',
        'invoices',
        'transactions',
        'loyalty_points',
        'preferences',
        'payment_methods',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'created_by')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'created_by')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('created_by');
            });
        }
    }
};
