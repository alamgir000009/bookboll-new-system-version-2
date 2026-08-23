<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align MySQL `listings` with Postgres `public.listings` from the dashboard dump.
 * Keeps Laravel FKs (venue_id, sports_category_id) and existing booking fields.
 * Idempotent — safe on DBs that already have some of these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('listings')) {
            return;
        }

        Schema::table('listings', function (Blueprint $table) {
            // Cover image + gallery (Postgres photo_url / photos)
            if (! Schema::hasColumn('listings', 'photo_url')) {
                $table->string('photo_url', 1024)->nullable()->after('description');
            }
            if (! Schema::hasColumn('listings', 'photos')) {
                $table->json('photos')->nullable()->after('photo_url');
            }

            // Denormalized category label (Postgres sport_category text).
            // Laravel still uses sports_category_id FK when set.
            if (! Schema::hasColumn('listings', 'sport_category')) {
                $table->string('sport_category')->nullable()->after('sports_category_id');
            }

            // Approval / eligibility (dashboard)
            if (! Schema::hasColumn('listings', 'approval_status')) {
                $table->string('approval_status', 20)->default('approved')->after('status');
            }
            if (! Schema::hasColumn('listings', 'eligibility_status')) {
                $table->string('eligibility_status', 32)->default('eligible')->after('approval_status');
            }
            if (! Schema::hasColumn('listings', 'admin_note')) {
                $table->text('admin_note')->nullable()->after('eligibility_status');
            }
            if (! Schema::hasColumn('listings', 'previous_snapshot')) {
                $table->json('previous_snapshot')->nullable()->after('admin_note');
            }
            if (! Schema::hasColumn('listings', 'pending_content')) {
                $table->json('pending_content')->nullable()->after('previous_snapshot');
            }

            // Listing type / marketplace category
            if (! Schema::hasColumn('listings', 'listing_type')) {
                $table->string('listing_type', 40)->default('per_space')->after('sort_order');
            }
            if (! Schema::hasColumn('listings', 'category')) {
                $table->string('category', 40)->default('sports')->after('listing_type');
            }

            // Visibility
            if (! Schema::hasColumn('listings', 'show_on_marketplace')) {
                $table->boolean('show_on_marketplace')->default(true)->after('category');
            }
            if (! Schema::hasColumn('listings', 'show_on_provider_site')) {
                $table->boolean('show_on_provider_site')->default(true)->after('show_on_marketplace');
            }

            // Slot / extension / hold (Postgres)
            if (! Schema::hasColumn('listings', 'buffer_time')) {
                $table->unsignedInteger('buffer_time')->default(0)->after('slot_duration');
            }
            if (! Schema::hasColumn('listings', 'start_time_mode')) {
                $table->string('start_time_mode', 20)->default('auto')->after('buffer_time');
            }
            if (! Schema::hasColumn('listings', 'fixed_start_times')) {
                $table->json('fixed_start_times')->nullable()->after('start_time_mode');
            }
            if (! Schema::hasColumn('listings', 'booking_hold_minutes')) {
                $table->unsignedInteger('booking_hold_minutes')->default(10)->after('fixed_start_times');
            }
            if (! Schema::hasColumn('listings', 'extension_pricing')) {
                $table->string('extension_pricing', 20)->default('none')->after('booking_hold_minutes');
            }
            if (! Schema::hasColumn('listings', 'extension_value')) {
                $table->decimal('extension_value', 15, 2)->default(0)->after('extension_pricing');
            }
            if (! Schema::hasColumn('listings', 'extension_max_slots')) {
                $table->unsignedInteger('extension_max_slots')->default(1)->after('extension_value');
            }

            // Commission override
            if (! Schema::hasColumn('listings', 'commission_rate')) {
                $table->decimal('commission_rate', 5, 2)->nullable()->after('extension_max_slots');
            }

            // Submission metadata
            if (! Schema::hasColumn('listings', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('commission_rate');
            }
            if (! Schema::hasColumn('listings', 'submitted_by_name')) {
                $table->string('submitted_by_name')->nullable()->after('submitted_at');
            }
        });

        // Postgres listing_hours.inherit_from_location — store on listing schedule rows.
        if (Schema::hasTable('schedules') && ! Schema::hasColumn('schedules', 'inherit_from_location')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->boolean('inherit_from_location')->default(true)->after('is_open');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('schedules') && Schema::hasColumn('schedules', 'inherit_from_location')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropColumn('inherit_from_location');
            });
        }

        if (! Schema::hasTable('listings')) {
            return;
        }

        $cols = [
            'photo_url',
            'photos',
            'sport_category',
            'approval_status',
            'eligibility_status',
            'admin_note',
            'previous_snapshot',
            'pending_content',
            'listing_type',
            'category',
            'show_on_marketplace',
            'show_on_provider_site',
            'buffer_time',
            'start_time_mode',
            'fixed_start_times',
            'booking_hold_minutes',
            'extension_pricing',
            'extension_value',
            'extension_max_slots',
            'commission_rate',
            'submitted_at',
            'submitted_by_name',
        ];

        $drop = array_values(array_filter($cols, fn($c) => Schema::hasColumn('listings', $c)));
        if ($drop) {
            Schema::table('listings', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
