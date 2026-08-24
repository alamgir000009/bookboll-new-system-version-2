<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UUID models (CustomType, HostedGame, Community, wallets, etc.) need a
 * string morph id; bigint subject_id truncates UUIDs and breaks Spatie logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `activity_log` MODIFY `subject_id` VARCHAR(64) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `activity_log` MODIFY `subject_id` BIGINT UNSIGNED NULL');
    }
};
