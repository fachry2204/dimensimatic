<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('metadata_mappings')
            ->where('mapping_type', 'subgenre')
            ->whereRaw('lower(source_value) = ?', ['indie'])
            ->update(['target_value' => 'Indie Pop', 'is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('metadata_mappings')
            ->where('mapping_type', 'subgenre')
            ->whereRaw('lower(source_value) = ?', ['indie'])
            ->update(['target_value' => 'Indie', 'updated_at' => now()]);
    }
};
