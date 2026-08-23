<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure venues has all Postgres `locations` dashboard columns. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('venues')) {
            return;
        }

        Schema::table('venues', function (Blueprint $table) {
            if (! Schema::hasColumn('venues', 'owner_user_id')) {
                $table->unsignedBigInteger('owner_user_id')->nullable()->index()->after('created_by');
            }
            if (! Schema::hasColumn('venues', 'address')) {
                $table->string('address')->nullable()->after('description');
            }
            if (! Schema::hasColumn('venues', 'phone')) {
                $table->string('phone', 40)->nullable()->after('address');
            }
            if (! Schema::hasColumn('venues', 'photo_url')) {
                $table->string('photo_url', 1024)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('venues', 'photos')) {
                $table->json('photos')->nullable()->after('photo_url');
            }
            if (! Schema::hasColumn('venues', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('photos');
            }
            if (! Schema::hasColumn('venues', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('venues', 'timezone_override')) {
                $table->string('timezone_override', 64)->nullable()->after('longitude');
            }
            if (! Schema::hasColumn('venues', 'currency_override')) {
                $table->string('currency_override', 8)->nullable()->after('timezone_override');
            }
            if (! Schema::hasColumn('venues', 'approval_status')) {
                $table->string('approval_status', 20)->default('approved')->after('status');
            }
            if (! Schema::hasColumn('venues', 'eligibility_status')) {
                $table->string('eligibility_status', 32)->default('eligible')->after('approval_status');
            }
            if (! Schema::hasColumn('venues', 'admin_note')) {
                $table->text('admin_note')->nullable()->after('eligibility_status');
            }
            if (! Schema::hasColumn('venues', 'previous_snapshot')) {
                $table->json('previous_snapshot')->nullable()->after('admin_note');
            }
            if (! Schema::hasColumn('venues', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (! Schema::hasColumn('venues', 'submitted_by_name')) {
                $table->string('submitted_by_name')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('venues')) {
            return;
        }

        $cols = [
            'owner_user_id',
            'address',
            'phone',
            'photo_url',
            'photos',
            'latitude',
            'longitude',
            'timezone_override',
            'currency_override',
            'approval_status',
            'eligibility_status',
            'admin_note',
            'previous_snapshot',
            'submitted_at',
            'submitted_by_name',
        ];

        $drop = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('venues', $c)));
        if ($drop) {
            Schema::table('venues', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
