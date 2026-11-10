<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('metadata_field_syncs')->updateOrInsert(
            ['scope' => 'release', 'source_field' => 'pre_release_date'],
            [
                'source_label' => 'TikTok & YouTube Music Pre-Release Date',
                'target_field' => 'pre_release_date',
                'target_label' => 'TikTok & YouTube pre-release date',
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('metadata_field_syncs')
            ->where('scope', 'release')
            ->where('source_field', 'pre_release_date')
            ->delete();
    }
};
