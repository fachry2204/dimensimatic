<?php

namespace Tests\Unit;

use App\DTO\ReleaseMetadataData;
use Tests\TestCase;

class MetadataDtoTest extends TestCase
{
    public function test_builds_ordered_track_dtos(): void
    {
        $dto = ReleaseMetadataData::fromArray(['title' => ' Single ', 'primary_artist' => 'Artist', 'release_type' => 'single', 'genre' => 'Pop', 'release_date' => '2026-08-10', 'cover' => [], 'tracks' => [['title' => 'Track', 'primary_artist' => 'Artist', 'audio' => []]]]);
        $this->assertSame('Single', $dto->title);
        $this->assertSame(1, $dto->tracks[0]->position);
    }
}
