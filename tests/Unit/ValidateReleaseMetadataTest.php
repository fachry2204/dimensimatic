<?php

namespace Tests\Unit;

use App\Actions\Automation\ValidateReleaseMetadata;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ValidateReleaseMetadataTest extends TestCase
{
    public function test_short_isrc_placeholder_is_ignored_when_upc_is_empty(): void
    {
        $result = app(ValidateReleaseMetadata::class)->handle($this->metadata(null, '01'));

        $this->assertNull($result['tracks'][0]['isrc']);
    }

    public function test_isrc_is_normalized_when_valid(): void
    {
        $result = app(ValidateReleaseMetadata::class)->handle($this->metadata(null, 'ID-ABC-26-12345'));

        $this->assertSame('IDABC2612345', $result['tracks'][0]['isrc']);
    }

    public function test_valid_isrc_is_required_when_upc_exists(): void
    {
        $this->expectException(ValidationException::class);

        app(ValidateReleaseMetadata::class)->handle($this->metadata('123456789012', '01'));
    }

    public function test_invalid_upc_has_an_informative_message(): void
    {
        try {
            app(ValidateReleaseMetadata::class)->handle($this->metadata('123', 'IDABC2612345'));
            $this->fail('Validation exception was not thrown.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('12 atau 13 digit', $error->errors()['upc'][0]);
            $this->assertStringContainsString('Perbaiki UPC di Dimensi Suara', $error->errors()['upc'][0]);
        }
    }

    public function test_invalid_isrc_has_an_informative_message(): void
    {
        try {
            app(ValidateReleaseMetadata::class)->handle($this->metadata('123456789012', 'INVALID'));
            $this->fail('Validation exception was not thrown.');
        } catch (ValidationException $error) {
            $message = collect($error->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('ISRC', $message);
            $this->assertStringContainsString('contoh IDABC2612345', $message);
        }
    }

    private function metadata(?string $upc, string $isrc): array
    {
        return [
            'title' => 'Test', 'primary_artist' => 'Artist', 'release_type' => 'single',
            'genre' => 'Pop', 'release_date' => '2026-08-13', 'upc' => $upc,
            'cover' => ['url' => 'https://cms.soundfresh.id/cover.png', 'filename' => 'cover.png'],
            'tracks' => [[
                'position' => 1, 'title' => 'Test', 'primary_artist' => 'Artist',
                'isrc' => $isrc, 'instrumental' => false, 'explicit' => false,
                'audio' => ['url' => 'https://cms.soundfresh.id/audio.wav', 'filename' => 'audio.wav'],
            ]],
        ];
    }
}
