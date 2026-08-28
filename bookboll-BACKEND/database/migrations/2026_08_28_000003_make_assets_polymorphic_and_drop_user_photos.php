<?php

use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (! Schema::hasColumn('assets', 'assetable_type')) {
                $table->nullableMorphs('assetable');
            }
        });

        // Allow long external URLs (e.g. imgbb) without requiring doctrine/dbal.
        try {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE assets MODIFY path TEXT NOT NULL');
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE assets ALTER COLUMN path TYPE TEXT');
            }
        } catch (\Throwable $e) {
            // path may already be text or driver unsupported — continue
        }

        if (Schema::hasColumn('assets', 'listing_id')) {
            $listingType = Listing::class;
            DB::table('assets')
                ->whereNotNull('listing_id')
                ->whereNull('assetable_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($listingType) {
                    foreach ($rows as $row) {
                        DB::table('assets')->where('id', $row->id)->update([
                            'assetable_type' => $listingType,
                            'assetable_id'   => $row->listing_id,
                        ]);
                    }
                });

            Schema::table('assets', function (Blueprint $table) {
                $table->dropForeign(['listing_id']);
                $table->dropIndex(['listing_id', 'sort_order']);
                $table->dropColumn('listing_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'photos')) {
                $table->json('photos')->nullable()->after('bio');
            }
        });

        Schema::table('assets', function (Blueprint $table) {
            if (! Schema::hasColumn('assets', 'listing_id')) {
                $table->unsignedBigInteger('listing_id')->nullable()->after('id');
            }
        });

        $listingType = Listing::class;
        DB::table('assets')
            ->where('assetable_type', $listingType)
            ->update(['listing_id' => DB::raw('assetable_id')]);

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->index(['listing_id', 'sort_order']);
            $table->dropMorphs('assetable');
        });
    }

    protected function normalizePath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            if (str_contains($path, '/storage/')) {
                return ltrim(preg_replace('#^.*/storage/#', '', $path), '/');
            }

            return $url;
        }
        if (str_starts_with($url, '/storage/')) {
            return ltrim(substr($url, strlen('/storage/')), '/');
        }
        if (str_starts_with($url, 'storage/')) {
            return ltrim(substr($url, strlen('storage/')), '/');
        }

        return $url;
    }

    protected function pathToUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        return url('storage/' . ltrim($path, '/'));
    }
};
