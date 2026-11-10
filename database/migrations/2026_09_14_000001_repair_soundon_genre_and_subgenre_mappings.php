<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Earlier fallback logic persisted several legitimate Soundfresh
        // genres as World. Restore their actual SoundOn genre labels rather
        // than allowing a non-Pop release to inherit an unrelated choice.
        foreach ([
            'Blues' => 'Blues',
            'Classical' => 'Classical',
            'Country' => 'Country',
            'Devotional/Inspirational' => 'Devotional/Inspirational',
            'Folk' => 'Folk',
            'R&B/Soul' => 'R&B/Soul',
            'Reggae' => 'Reggae',
            'Soundtrack' => 'Soundtrack',
            'latin' => 'Latin',
        ] as $source => $target) {
            DB::table('metadata_mappings')->updateOrInsert(
                ['mapping_type' => 'genre', 'source_value' => $source],
                ['target_value' => $target, 'is_active' => true, 'updated_at' => now()],
            );
        }

        // Subgenre is optional, but it should be attempted when SoundOn makes
        // the matching option available for the selected parent genre. Reset
        // old empty fallbacks back to their Soundfresh source labels.
        DB::table('metadata_mappings')
            ->where('mapping_type', 'subgenre')
            ->where('target_value', '')
            ->update(['target_value' => DB::raw('source_value'), 'is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // These corrections intentionally remain on rollback: reverting to a
        // generic/empty mapping would reintroduce incorrect draft metadata.
    }
};
