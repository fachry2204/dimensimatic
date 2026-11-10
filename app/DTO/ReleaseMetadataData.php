<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ReleaseMetadataData
{
    /** @param list<TrackMetadataData> $tracks */
    public function __construct(
        public string $title,
        public string $primaryArtist,
        public string $releaseType,
        public string $genre,
        public string $releaseDate,
        public array $cover,
        public array $tracks,
        public ?string $language = null,
        public ?string $titleLanguage = null,
        public ?string $version = null,
        public ?string $subgenre = null,
        public ?string $upc = null,
        public ?string $recordLabel = null,
        public ?string $preReleaseDate = null,
        public ?string $originalReleaseDate = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $tracks = [];
        foreach (array_values($data['tracks']) as $index => $track) {
            $tracks[] = TrackMetadataData::fromArray($track, $index + 1);
        }

        return new self(
            trim((string) $data['title']),
            trim((string) $data['primary_artist']),
            (string) $data['release_type'],
            (string) $data['genre'],
            (string) $data['release_date'],
            (array) $data['cover'],
            $tracks,
            $data['language'] ?? null,
            $data['title_language'] ?? null,
            $data['version'] ?? null,
            $data['subgenre'] ?? null,
            $data['upc'] ?? null,
            $data['record_label'] ?? null,
            $data['pre_release_date'] ?? null,
            $data['original_release_date'] ?? null,
        );
    }
}
