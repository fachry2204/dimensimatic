<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class TrackMetadataData
{
    public function __construct(
        public int $position,
        public string $title,
        public string $primaryArtist,
        public array $audio,
        public ?string $isrc = null,
        public ?string $language = null,
        public bool $explicit = false,
        public bool $instrumental = false,
        public ?string $titleLanguage = null,
        public ?string $version = null,
        public ?string $genre = null,
        public ?string $subgenre = null,
        public ?string $featuredArtists = null,
        public ?string $songwriters = null,
        public ?string $contributors = null,
        public ?string $productionContributors = null,
    ) {}

    public static function fromArray(array $data, int $position): self
    {
        return new self(
            $position,
            trim((string) $data['title']),
            trim((string) $data['primary_artist']),
            (array) $data['audio'],
            $data['isrc'] ?? null,
            $data['language'] ?? null,
            (bool) ($data['explicit'] ?? false),
            (bool) ($data['instrumental'] ?? false),
            $data['title_language'] ?? null,
            $data['version'] ?? null,
            $data['genre'] ?? null,
            $data['subgenre'] ?? null,
            $data['featured_artists'] ?? null,
            $data['songwriters'] ?? null,
            $data['contributors'] ?? null,
            $data['production_contributors'] ?? null,
        );
    }
}
