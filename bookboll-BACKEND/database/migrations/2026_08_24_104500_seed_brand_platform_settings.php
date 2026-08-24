<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed marketplace + admin-chrome brand keys used by apps/admin /brand
 * (mirrors Supabase platform_settings brand_* / admin_* keys).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();
        $defaults = [
            ['key' => 'brand_name', 'value' => 'Bookboll', 'type' => 'string', 'description' => 'Marketplace display name'],
            ['key' => 'brand_tagline', 'value' => '', 'type' => 'string', 'description' => 'Marketplace tagline'],
            ['key' => 'brand_logo_url', 'value' => '', 'type' => 'string', 'description' => 'Marketplace logo URL'],
            ['key' => 'brand_primary', 'value' => '#0f766e', 'type' => 'string', 'description' => 'Marketplace primary color'],
            ['key' => 'brand_accent', 'value' => '#14b8a6', 'type' => 'string', 'description' => 'Marketplace accent color'],
            ['key' => 'brand_meta_title', 'value' => '', 'type' => 'string', 'description' => 'Marketplace SEO title'],
            ['key' => 'brand_meta_description', 'value' => '', 'type' => 'text', 'description' => 'Marketplace SEO description'],
            ['key' => 'admin_logo_url', 'value' => '', 'type' => 'string', 'description' => 'Admin dashboard logo URL'],
            ['key' => 'admin_primary', 'value' => '', 'type' => 'string', 'description' => 'Admin dashboard primary color'],
        ];

        foreach ($defaults as $row) {
            if (DB::table('settings')->where('key', $row['key'])->exists()) {
                continue;
            }
            DB::table('settings')->insert([
                'key'         => $row['key'],
                'value'       => $row['value'],
                'type'        => $row['type'],
                'description' => $row['description'],
                'encrypted'   => false,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('key', [
            'brand_name',
            'brand_tagline',
            'brand_logo_url',
            'brand_primary',
            'brand_accent',
            'brand_meta_title',
            'brand_meta_description',
            'admin_logo_url',
            'admin_primary',
        ])->delete();
    }
};
