<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('metadata_field_syncs')->updateOrInsert(
            ['scope' => 'release', 'source_field' => 'original_release_date'],
            [
                'source_label' => 'Original Released Date',
                'target_field' => 'original_release_date',
                'target_label' => 'Original release date',
                'is_active' => true,
                'sort_order' => 14,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('metadata_field_syncs')
            ->where('scope', 'release')
            ->where('source_field', 'original_release_date')
            ->delete();
    }
};
