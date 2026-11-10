<?php

namespace Tests\Feature;

use App\Exceptions\MetadataMappingMissingException;
use App\Models\MetadataMapping;
use App\Services\SoundOn\SoundOnMetadataMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoundOnMetadataMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_known_genre(): void
    {
        MetadataMapping::create(['mapping_type' => 'genre', 'source_value' => 'Pop Indonesia', 'target_value' => 'Pop']);
        $mapped = app(SoundOnMetadataMapper::class)->map(['genre' => 'Pop Indonesia', 'tracks' => []]);
        $this->assertSame('Pop', $mapped['genre']);
    }

    public function test_rejects_unknown_genre(): void
    {
        $this->expectException(MetadataMappingMissingException::class);
        app(SoundOnMetadataMapper::class)->map(['genre' => 'Unknown', 'tracks' => []]);
    }

    public function test_maps_available_language_on_each_track(): void
    {
        MetadataMapping::create([
            'mapping_type' => 'language',
            'source_value' => 'Indonesian',
            'target_value' => 'Bahasa Indonesia (Bahasa)',
        ]);

        $mapped = app(SoundOnMetadataMapper::class)->mapAvailable([
            'tracks' => [[
                'language' => 'Indonesian',
                'title_language' => 'Indonesian',
            ]],
        ]);

        $this->assertSame('Bahasa Indonesia (Bahasa)', $mapped['tracks'][0]['language']);
        $this->assertSame('Bahasa Indonesia (Bahasa)', $mapped['tracks'][0]['title_language']);
    }

    public function test_preserves_unmapped_available_values_for_worker_discovery(): void
    {
        $mapped = app(SoundOnMetadataMapper::class)->mapAvailable([
            'genre' => 'Adult Contemporary',
            'tracks' => [['genre' => 'Adult Contemporary']],
        ]);

        $this->assertSame('World', $mapped['genre']);
        $this->assertSame('World', $mapped['tracks'][0]['genre']);
        $this->assertDatabaseHas('metadata_mappings', [
            'mapping_type' => 'genre',
            'source_value' => 'Adult Contemporary',
            'target_value' => 'World',
            'is_active' => true,
        ]);
    }

    public function test_preserves_non_pop_soundon_genre_without_falling_back_to_pop(): void
    {
        $mapped = app(SoundOnMetadataMapper::class)->mapAvailable([
            'genre' => 'R&B/Soul',
            'tracks' => [['genre' => 'R&B/Soul']],
        ]);

        $this->assertSame('R&B/Soul', $mapped['genre']);
        $this->assertSame('R&B/Soul', $mapped['tracks'][0]['genre']);
        $this->assertDatabaseHas('metadata_mappings', [
            'mapping_type' => 'genre',
            'source_value' => 'R&B/Soul',
            'target_value' => 'R&B/Soul',
            'is_active' => true,
        ]);
    }

    public function test_maps_javanese_lyrics_language_to_soundon_indonesian(): void
    {
        MetadataMapping::create([
            'mapping_type' => 'language',
            'source_value' => 'Javanese',
            'target_value' => 'Bahasa Indonesia (Bahasa)',
        ]);

        $mapped = app(SoundOnMetadataMapper::class)->mapAvailable([
            'tracks' => [['language' => 'Javanese']],
        ]);

        $this->assertSame('Bahasa Indonesia (Bahasa)', $mapped['tracks'][0]['language']);
    }

    public function test_preserves_subgenre_for_the_genre_dependent_soundon_picker(): void
    {
        $mapped = app(SoundOnMetadataMapper::class)->mapAvailable([
            'title_language' => 'Filipino',
            'subgenre' => 'Local Experimental Style',
            'tracks' => [[
                'language' => 'Sundanese',
                'subgenre' => 'Local Experimental Style',
            ]],
        ]);

        $this->assertSame('English', $mapped['title_language']);
        $this->assertSame('Bahasa Indonesia (Bahasa)', $mapped['tracks'][0]['language']);
        $this->assertSame('Local Experimental Style', $mapped['subgenre']);
        $this->assertSame('Local Experimental Style', $mapped['tracks'][0]['subgenre']);
        $this->assertDatabaseHas('metadata_mappings', [
            'mapping_type' => 'subgenre',
            'source_value' => 'Local Experimental Style',
            'target_value' => 'Local Experimental Style',
            'is_active' => true,
        ]);
    }
}
