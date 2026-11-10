<?php

namespace Tests\Feature;

use App\Services\SoundOn\MetadataFieldSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetadataFieldSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_whitelabel_identity_is_applied_to_all_three_rights_fields(): void
    {
        foreach ([true, 'aktif', 'true', 1] as $active) {
            $result = app(MetadataFieldSynchronizer::class)->synchronize([
                'whitelabel_active' => $active, 'label_name' => '  PT RUANG DIMENSI RECORDS  ',
            ]);
            foreach (['record_label', 'p_line', 'c_line'] as $field) {
                $this->assertSame('PT RUANG DIMENSI RECORDS', $result[$field]);
            }
            $this->assertTrue($result['whitelabel_active']);
        }
        foreach ([false, 'false', 'tidak aktif', 0, null] as $active) {
            $result = app(MetadataFieldSynchronizer::class)->synchronize([
                'whitelabel_active' => $active, 'record_label' => 'Other Label',
            ]);
            foreach (['record_label', 'p_line', 'c_line'] as $field) {
                $this->assertSame('Dimensi Suara', $result[$field]);
            }
            $this->assertFalse($result['whitelabel_active']);
        }
    }

    public function test_active_whitelabel_without_a_name_requires_metadata_correction(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(MetadataFieldSynchronizer::class)->synchronize(['whitelabel_active' => true, 'label_name' => '']);
    }

    public function test_release_track_and_trimmed_audio_fields_are_synchronized(): void
    {
        $source = [
            'title' => 'Song', 'primary_artist' => 'Artist', 'record_label' => 'Other Label', 'cover' => ['url' => 'cover'],
            'pre_release_date' => '2026-08-13',
            'tracks' => [[
                'position' => 1, 'title' => 'Song', 'primary_artist' => 'Artist',
                'language' => 'Indonesian', 'explicit' => true,
                'songwriters' => 'Author Name', 'production_contributors' => 'Producer Name',
                'audio' => ['url' => 'full'], 'tiktok_audio' => ['url' => 'trimmed'],
            ]],
        ];

        $result = app(MetadataFieldSynchronizer::class)->synchronize($source);

        $this->assertSame('Artist', $result['primary_artist']);
        $this->assertSame('Artist', $result['tracks'][0]['primary_artist']);
        $this->assertSame('Artist (Vocalist)', $result['tracks'][0]['contributors']);
        $this->assertSame('Author Name', $result['tracks'][0]['songwriters']);
        $this->assertSame('Producer Name', $result['tracks'][0]['production_contributors']);
        $this->assertSame('Dimensi Suara', $result['record_label']);
        $this->assertSame('2026-08-13', $result['pre_release_date']);
        $this->assertSame('Indonesian', $result['tracks'][0]['language']);
        $this->assertTrue($result['tracks'][0]['explicit']);
        $this->assertSame('full', $result['tracks'][0]['audio']['url']);
        $this->assertSame('trimmed', $result['tracks'][0]['tiktok_audio']['url']);
    }

    public function test_primary_artist_is_used_when_contributors_and_production_are_empty(): void
    {
        $source = [
            'title' => 'Song',
            'primary_artist' => 'Fallback Artist',
            'tracks' => [[
                'title' => 'Song',
                'primary_artist' => 'Fallback Artist',
                'contributors' => '',
                'production_contributors' => null,
            ]],
        ];

        $result = app(MetadataFieldSynchronizer::class)->synchronize($source);

        $this->assertSame('Fallback Artist (Vocalist)', $result['tracks'][0]['contributors']);
        $this->assertSame('Fallback Artist (Producer)', $result['tracks'][0]['production_contributors']);
    }

    public function test_each_comma_separated_primary_artist_receives_an_individual_credit(): void
    {
        $source = [
            'title' => 'Collaborative Song',
            'primary_artist' => 'MARCIANO, BLEK, T3ZNO',
            'tracks' => [[
                'title' => 'Collaborative Song',
                'primary_artist' => 'MARCIANO, BLEK, T3ZNO',
                'contributors' => '',
                'production_contributors' => null,
            ]],
        ];

        $result = app(MetadataFieldSynchronizer::class)->synchronize($source);

        $this->assertSame("MARCIANO (Vocalist)\nBLEK (Vocalist)\nT3ZNO (Vocalist)", $result['tracks'][0]['contributors']);
        $this->assertSame("MARCIANO (Producer)\nBLEK (Producer)\nT3ZNO (Producer)", $result['tracks'][0]['production_contributors']);
    }

    public function test_primary_artist_is_added_as_vocalist_when_other_contributors_exist(): void
    {
        $source = [
            'title' => 'Song',
            'primary_artist' => 'Primary Artist',
            'tracks' => [[
                'title' => 'Song',
                'primary_artist' => 'Primary Artist',
                'contributors' => 'Guest Singer (Vocalist)',
            ]],
        ];

        $result = app(MetadataFieldSynchronizer::class)->synchronize($source);

        $this->assertSame("Guest Singer (Vocalist)\nPrimary Artist (Vocalist)", $result['tracks'][0]['contributors']);
    }

    public function test_primary_artist_vocalist_is_not_duplicated(): void
    {
        $source = [
            'title' => 'Song',
            'primary_artist' => 'Primary Artist',
            'tracks' => [[
                'title' => 'Song',
                'primary_artist' => 'Primary Artist',
                'contributors' => "Guest Singer (Vocalist)\nPrimary Artist (Vocalist)",
            ]],
        ];

        $result = app(MetadataFieldSynchronizer::class)->synchronize($source);

        $this->assertSame("Guest Singer (Vocalist)\nPrimary Artist (Vocalist)", $result['tracks'][0]['contributors']);
    }

    public function test_instrumental_track_keeps_soundfresh_contributors_without_a_synthetic_vocalist(): void
    {
        $source = [
            'title' => 'Instrumental Song',
            'primary_artist' => 'Majestic Malay',
            'tracks' => [[
                'title' => 'Instrumental Song',
                'primary_artist' => 'Majestic Malay',
                'instrumental' => true,
                'contributors' => 'Majestic Malay (Tenor Saxophone)',
                'production_contributors' => 'Majestic Malay (Producer)',
            ]],
        ];

        $result = app(MetadataFieldSynchronizer::class)->synchronize($source);

        $this->assertSame('Majestic Malay (Tenor Saxophone)', $result['tracks'][0]['contributors']);
        $this->assertSame('Majestic Malay (Producer)', $result['tracks'][0]['production_contributors']);
        $this->assertStringNotContainsString('Vocalist', $result['tracks'][0]['contributors']);
    }
}
