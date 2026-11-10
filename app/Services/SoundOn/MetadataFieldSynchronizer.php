<?php

declare(strict_types=1);

namespace App\Services\SoundOn;

use App\Models\MetadataFieldSync;

final class MetadataFieldSynchronizer
{
    public function synchronize(array $source): array
    {
        $target = ['tracks' => []];
        foreach (MetadataFieldSync::where('scope', 'release')->where('is_active', true)->orderBy('sort_order')->get() as $sync) {
            if (array_key_exists($sync->source_field, $source)) {
                data_set($target, $sync->target_field, $source[$sync->source_field]);
            }
        }

        $active = in_array(strtolower(trim((string) ($source['whitelabel_active'] ?? ''))), ['1', 'true', 'yes', 'active', 'aktif'], true);
        $label = $active ? trim((string) ($source['label_name'] ?? $source['record_label'] ?? '')) : 'Dimensi Suara';
        if ($label === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['record_label' => 'Nama Label wajib diisi untuk akun Whitelabel aktif.']);
        }
        $target['whitelabel_active'] = $active;
        $target['label_name'] = $label;
        $target['record_label'] = $label;
        $target['p_line'] = $label;
        $target['c_line'] = $label;

        $trackSyncs = MetadataFieldSync::where('scope', 'track')->where('is_active', true)->orderBy('sort_order')->get();
        foreach (array_values($source['tracks'] ?? []) as $index => $sourceTrack) {
            $targetTrack = [];
            foreach ($trackSyncs as $sync) {
                if (array_key_exists($sync->source_field, $sourceTrack)) {
                    data_set($targetTrack, $sync->target_field, $sourceTrack[$sync->source_field]);
                }
            }
            $primaryArtist = $targetTrack['primary_artist']
                ?? $sourceTrack['primary_artist']
                ?? $source['primary_artist']
                ?? null;
            $isInstrumental = filter_var(
                $targetTrack['instrumental'] ?? $sourceTrack['instrumental'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            );
            // SoundOn rejects Vocalist for an instrumental track. Keep the
            // contributor roles that Dimensi Suara supplies instead of making one.
            if (filled($primaryArtist) && ! $isInstrumental) {
                $contributors = trim((string) ($targetTrack['contributors'] ?? ''));
                foreach ($this->artistNames($primaryArtist) as $artist) {
                    $artistVocalist = $artist.' (Vocalist)';
                    $hasPrimaryVocalist = preg_match(
                        '/(?:^|[\r\n,;])\s*'.preg_quote($artist, '/').'\s*\(\s*Vocalist\s*\)(?=$|[\r\n,;])/iu',
                        $contributors,
                    ) === 1;
                    if (! $hasPrimaryVocalist) {
                        $contributors = $contributors === ''
                            ? $artistVocalist
                            : $contributors."\n".$artistVocalist;
                    }
                }
                $targetTrack['contributors'] = $contributors;
            }
            if (blank($targetTrack['production_contributors'] ?? null) && filled($primaryArtist) && ! $isInstrumental) {
                $targetTrack['production_contributors'] = implode("\n", array_map(
                    static fn (string $artist): string => $artist.' (Producer)',
                    $this->artistNames($primaryArtist),
                ));
            }
            $target['tracks'][$index] = $targetTrack;
        }

        return $target;
    }

    /** @return list<string> */
    private function artistNames(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $artist): string => trim($artist),
            preg_split('/\s*(?:,|;|\||\r?\n|\s\/\s)\s*/u', trim((string) $value)) ?: [],
        ))));
    }
}
