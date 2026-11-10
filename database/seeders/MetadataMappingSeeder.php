<?php

namespace Database\Seeders;

use App\Models\MetadataMapping;
use Illuminate\Database\Seeder;

class MetadataMappingSeeder extends Seeder
{
    public function run(): void
    {
        $genres = ['Pop' => 'Pop', 'Pop Indonesia' => 'Pop', 'Pop Melayu' => 'Pop', 'Rock' => 'Rock', 'Rock Alternative' => 'Rock', 'Alternative' => 'Alternative/Indie', 'alternative/indie' => 'Alternative/Indie', 'Dangdut' => 'Dangdut', 'Religi' => 'Devotional/Inspirational', 'Devotional/Inspirational' => 'Devotional/Inspirational', 'Hip Hop' => 'Hip Hop/Rap', 'Hip Hop/Rap' => 'Hip Hop/Rap', 'Hip-Hop/Rap' => 'Hip Hop/Rap', 'Electronic' => 'Electronic', 'Jazz' => 'Jazz', 'Blues' => 'Blues', 'Classical' => 'Classical', 'Country' => 'Country', 'Folk' => 'Folk', 'R&B/Soul' => 'R&B/Soul', 'Reggae' => 'Reggae', 'Soundtrack' => 'Soundtrack', 'latin' => 'Latin', 'World' => 'World'];
        foreach ($genres as $source => $target) {
            MetadataMapping::updateOrCreate(['mapping_type' => 'genre', 'source_value' => $source], ['target_value' => $target, 'is_active' => true]);
        }

        foreach (['Indie' => 'Indie', 'Indie Pop' => 'Indie'] as $source => $target) {
            MetadataMapping::updateOrCreate(['mapping_type' => 'subgenre', 'source_value' => $source], ['target_value' => $target, 'is_active' => true]);
        }

        foreach (['Indonesian' => 'Bahasa Indonesia (Bahasa)', 'Batak' => 'Bahasa Indonesia (Bahasa)', 'Batak Toba' => 'Bahasa Indonesia (Bahasa)', 'English' => 'English', 'Thai' => 'ไทย (Thai)', 'Tagalog' => 'Filipino', 'Filipino' => 'Filipino'] as $source => $target) {
            MetadataMapping::updateOrCreate(['mapping_type' => 'language', 'source_value' => $source], ['target_value' => $target, 'is_active' => true]);
        }
        MetadataMapping::updateOrCreate(
            ['mapping_type' => 'language', 'source_value' => 'Javanese'],
            ['target_value' => 'Bahasa Indonesia (Bahasa)', 'is_active' => true],
        );
    }
}
