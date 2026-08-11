<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->unsignedBigInteger('country_id')->nullable()->after('maps_url')->index();
            $table->unsignedBigInteger('state_id')->nullable()->after('country_id')->index();
            $table->unsignedBigInteger('city_id')->nullable()->after('state_id')->index();
        });

        if (Schema::hasTable('countries') && Country::exists()) {
            $qatar = Country::where('iso2', 'QA')->first();
            $dohaState = $qatar
                ? State::where('country_id', $qatar->id)
                    ->where('name', 'Doha')
                    ->first()
                : null;
            $dohaCity = $dohaState
                ? City::where('country_id', $qatar->id)
                    ->where('state_id', $dohaState->id)
                    ->first()
                : null;

            if ($qatar) {
                DB::table('venues')->whereNull('country_id')->update([
                    'country_id' => $qatar->id,
                    'state_id'   => $dohaState?->id,
                    'city_id'    => $dohaCity?->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn(['country_id', 'state_id', 'city_id']);
        });
    }
};
