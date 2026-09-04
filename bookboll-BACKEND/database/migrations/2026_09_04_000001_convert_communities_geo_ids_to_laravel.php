<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Communities were seeded from Supabase and kept its UUID city/country ids, while
 * the storefront filters with Laravel's integer geo ids — so `?city_id=92949`
 * never matched a row and the communities page always came back empty.
 *
 * The whole legacy seed sat in Doha, Qatar, so those two UUIDs map cleanly. Any
 * other leftover UUID is unmappable, and is cleared rather than guessed at.
 */
return new class extends Migration
{
    private const SUPABASE_DOHA  = '97064dd0-b0a7-4c81-be7e-488bb821a975';

    private const SUPABASE_QATAR = '79cad528-bd7f-44f9-adbf-6193025843dd';

    public function up(): void
    {
        $qatar = DB::table('countries')->where('iso2', 'QA')->value('id');
        $doha  = $qatar ? DB::table('cities')->where('country_id', $qatar)->where('name', 'Doha')->value('id') : null;

        if ($doha) {
            DB::table('communities')->where('city_id', self::SUPABASE_DOHA)->update(['city_id' => $doha]);
        }

        if ($qatar) {
            DB::table('communities')->where('country_id', self::SUPABASE_QATAR)->update(['country_id' => $qatar]);
        }

        DB::table('communities')->whereRaw("city_id NOT REGEXP '^[0-9]+$'")->update(['city_id' => null]);
        DB::table('communities')->whereRaw("country_id NOT REGEXP '^[0-9]+$'")->update(['country_id' => null]);

        Schema::table('communities', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->change();
            $table->unsignedBigInteger('country_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->uuid('city_id')->nullable()->change();
            $table->uuid('country_id')->nullable()->change();
        });

        $qatar = DB::table('countries')->where('iso2', 'QA')->value('id');
        $doha  = $qatar ? DB::table('cities')->where('country_id', $qatar)->where('name', 'Doha')->value('id') : null;

        if ($doha) {
            DB::table('communities')->where('city_id', $doha)->update(['city_id' => self::SUPABASE_DOHA]);
        }

        if ($qatar) {
            DB::table('communities')->where('country_id', $qatar)->update(['country_id' => self::SUPABASE_QATAR]);
        }
    }
};
