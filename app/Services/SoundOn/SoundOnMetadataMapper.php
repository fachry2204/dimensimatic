<?php

declare(strict_types=1);

namespace App\Services\SoundOn;

use App\Exceptions\MetadataMappingMissingException;
use App\Models\MetadataMapping;

final class SoundOnMetadataMapper
{
    public function mapAvailable(array $metadata): array
    {
        foreach (['genre', 'subgenre'] as $field) {
            if (! empty($metadata[$field])) {
                $metadata[$field] = $this->compatibleValue($field, (string) $metadata[$field]);
            }
        }
        foreach (['language', 'title_language'] as $field) {
            if (! empty($metadata[$field])) {
                $metadata[$field] = $this->compatibleValue('language', (string) $metadata[$field]);
            }
        }
        if (isset($metadata['tracks']) && is_array($metadata['tracks'])) {
            foreach ($metadata['tracks'] as &$track) {
                foreach (['genre', 'subgenre'] as $field) {
                    if (! empty($track[$field])) {
                        $track[$field] = $this->compatibleValue($field, (string) $track[$field]);
                    }
                }
                foreach (['language', 'title_language'] as $field) {
                    if (! empty($track[$field])) {
                        $track[$field] = $this->compatibleValue('language', (string) $track[$field]);
                    }
                }
            }
            unset($track);
        }

        return $metadata;
    }

    public function map(array $metadata): array
    {
        $sourceGenre = (string) $metadata['genre'];
        $metadata['genre'] = $this->value('genre', $sourceGenre);

        if (! empty($metadata['subgenre'])) {
            $metadata['subgenre'] = $this->value('subgenre', (string) $metadata['subgenre']);
        }

        if (! empty($metadata['language'])) {
            $metadata['language'] = $this->value('language', (string) $metadata['language']);
        }
        if (! empty($metadata['title_language'])) {
            $metadata['title_language'] = $this->value('language', (string) $metadata['title_language']);
        }

        foreach ($metadata['tracks'] as &$track) {
            if (! empty($track['genre'])) {
                $track['genre'] = trim((string) $track['genre']) === trim($sourceGenre)
                    ? $metadata['genre']
                    : $this->value('genre', (string) $track['genre']);
            }
            if (! empty($track['subgenre'])) {
                $track['subgenre'] = $this->value('subgenre', (string) $track['subgenre']);
            }
            if (! empty($track['language'])) {
                $track['language'] = $this->value('language', (string) $track['language']);
            }
            if (! empty($track['title_language'])) {
                $track['title_language'] = $this->value('language', (string) $track['title_language']);
            }
        }

        return $metadata;
    }

    private function value(string $type, string $source): string
    {
        $target = MetadataMapping::query()
            ->where('mapping_type', $type)
            ->where('source_value', trim($source))
            ->where('is_active', true)
            ->value('target_value');

        if (! $target) {
            throw new MetadataMappingMissingException($type, $source);
        }

        return $target;
    }

    private function optionalValue(string $type, string $source): ?string
    {
        return MetadataMapping::query()
            ->where('mapping_type', $type)
            ->where('source_value', trim($source))
            ->where('is_active', true)
            ->value('target_value');
    }

    /**
     * Repair values before Playwright opens SoundOn. The selected fallback is
     * persisted as an active mapping, so the correction is visible to the
     * operator and reused by subsequent releases instead of failing late in
     * the SoundOn form.
     */
    private function compatibleValue(string $type, string $source): string
    {
        $source = trim($source);
        $mapped = $this->optionalValue($type, $source);
        if ($mapped !== null && ($type !== 'subgenre' || trim($mapped) !== '')) {
            return $mapped;
        }

        $target = match ($type) {
            'language' => $this->languageFallback($source),
            // Let the browser try the exact source value after selecting the
            // parent genre. It will skip only an unavailable optional option.
            'subgenre' => $source,
            'genre' => $this->genreFallback($source),
            default => $source,
        };

        MetadataMapping::updateOrCreate(
            ['mapping_type' => $type, 'source_value' => $source],
            ['target_value' => $target, 'is_active' => true],
        );

        return $target;
    }

    private function languageFallback(string $source): string
    {
        $value = mb_strtolower($source);
        if (preg_match('/indones|bahasa|javan|jawa|sunda|batak|toba|bali|madura|minang|aceh|bugis|banjar|dayak|sasak|melayu|malay/u', $value)) {
            return 'Bahasa Indonesia (Bahasa)';
        }
        if (preg_match('/thai|[\x{0E00}-\x{0E7F}]/u', $value)) {
            return 'ไทย (Thai)';
        }

        // SoundOn does not currently expose every regional language. English
        // is its supported neutral fallback for title/lyrics language fields.
        return 'English';
    }

    private function genreFallback(string $source): string
    {
        $value = mb_strtolower($source);
        return match (true) {
            // These are already SoundOn genre labels. Preserve them exactly;
            // they must never fall through to Pop or a generic category.
            in_array($value, ['blues', 'classical', 'country', 'folk', 'r&b/soul', 'reggae', 'soundtrack', 'latin', 'world'], true) => match ($value) {
                'r&b/soul' => 'R&B/Soul',
                default => ucfirst($value),
            },
            str_contains($value, 'dangdut') => 'Dangdut',
            str_contains($value, 'hip hop'), str_contains($value, 'hip-hop'), str_contains($value, 'rap') => 'Hip Hop/Rap',
            str_contains($value, 'rock') => 'Rock',
            str_contains($value, 'pop') => 'Pop',
            str_contains($value, 'jazz') => 'Jazz',
            str_contains($value, 'electro'), str_contains($value, 'dance') => 'Electronic',
            str_contains($value, 'religi'), str_contains($value, 'religious'), str_contains($value, 'gospel'), str_contains($value, 'islam') => 'Devotional/Inspirational',
            str_contains($value, 'alternative'), str_contains($value, 'indie') => 'Alternative/Indie',
            default => 'World',
        };
    }
}
