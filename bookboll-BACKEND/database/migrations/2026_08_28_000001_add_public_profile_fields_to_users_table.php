<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
                $table->index('uuid');
            }
            if (! Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('image');
            }
            if (! Schema::hasColumn('users', 'is_private')) {
                $table->boolean('is_private')->default(false)->after('bio');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = ['uuid', 'bio', 'is_private'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
