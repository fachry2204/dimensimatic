<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('metadata_mappings')
            ->where('mapping_type', 'subgenre')
            ->whereIn(DB::raw('lower(source_value)'), ['indie', 'indie pop'])
            ->update(['target_value' => 'Indie', 'is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('metadata_mappings')
            ->where('mapping_type', 'subgenre')
            ->whereIn(DB::raw('lower(source_value)'), ['indie', 'indie pop'])
            ->update(['target_value' => 'Indie Pop', 'updated_at' => now()]);
    }
};
