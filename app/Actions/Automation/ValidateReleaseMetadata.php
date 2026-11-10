<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use Illuminate\Validation\ValidationException;

final class ValidateReleaseMetadata
{
    public function handle(array $data): array
    {
        // Dimensi Suara sometimes exposes a short placeholder such as "01" in
        // the ISRC row. It is not an ISRC. Ignore it for a new release (no
        // UPC), while keeping ISRC mandatory for previously released content.
        foreach ($data['tracks'] ?? [] as $index => $track) {
            $isrc = strtoupper(preg_replace('/[\s-]+/', '', trim((string) ($track['isrc'] ?? ''))) ?? '');
            $data['tracks'][$index]['isrc'] = preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $isrc)
                ? $isrc
                : (filled($data['upc'] ?? null) ? $isrc : null);
        }

        $v = validator($data, [
            'title' => 'required|string|max:255',
            'version' => 'nullable|string|max:255',
            'primary_artist' => 'required|string|max:255',
            'release_type' => 'required|in:single,ep,album',
            'genre' => 'required|string|max:255',
            'subgenre' => 'nullable|string|max:255',
            'title_language' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:100',
            'release_date' => 'nullable|date',
            'pre_release_date' => 'nullable|date',
            'upc' => ['nullable', 'string', 'regex:/^(?:\d{12}|\d{13})$/'],
            'original_release_date' => 'nullable|date',
            'record_label' => 'nullable|string|max:255',
            'cover' => 'required|array',
            'cover.url' => 'required|url:https',
            'cover.filename' => 'required|string',
            'tracks' => 'required|array|min:1',
            'tracks.*.position' => 'required|integer|min:1',
            'tracks.*.title' => 'required|string|max:255',
            'tracks.*.title_language' => 'nullable|string|max:100',
            'tracks.*.language' => 'nullable|string|max:100',
            'tracks.*.version' => 'nullable|string|max:255',
            'tracks.*.genre' => 'nullable|string|max:255',
            'tracks.*.subgenre' => 'nullable|string|max:255',
            'tracks.*.isrc' => [filled($data['upc'] ?? null) ? 'required' : 'nullable', 'string', 'regex:/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/i'],
            'tracks.*.instrumental' => 'required|boolean',
            'tracks.*.explicit' => 'required|boolean',
            'tracks.*.primary_artist' => 'required|string|max:255',
            'tracks.*.featured_artists' => 'nullable|string|max:1000',
            'tracks.*.songwriters' => 'nullable|string|max:2000',
            'tracks.*.contributors' => 'nullable|string|max:2000',
            'tracks.*.production_contributors' => 'nullable|string|max:2000',
            'tracks.*.audio' => 'required|array',
            'tracks.*.audio.url' => 'required|url:https',
            'tracks.*.audio.filename' => 'required|string',
            // tiktok_audio is optional — not all Dimensi Suara releases have a TikTok preview
            'tracks.*.tiktok_audio' => 'nullable|array',
            'tracks.*.tiktok_audio.url' => 'nullable|url:https',
            'tracks.*.tiktok_audio.filename' => 'nullable|string',
        ], [
            'upc.regex' => \App\Support\SystemBranding::text('UPC ":input" tidak valid. UPC harus berisi tepat 12 atau 13 digit angka tanpa spasi atau tanda hubung. Perbaiki UPC di Dimensi Suara lalu tekan Retry.'),
            'tracks.*.isrc.required' => \App\Support\SystemBranding::text('ISRC wajib diisi karena UPC tersedia (rilis sebelumnya). Isi ISRC 12 karakter di Dimensi Suara, contoh IDABC2612345, lalu tekan Retry.'),
            'tracks.*.isrc.regex' => \App\Support\SystemBranding::text('ISRC ":input" tidak valid. ISRC harus 12 karakter: 2 huruf negara + 3 karakter registrant + 2 digit tahun + 5 digit nomor, contoh IDABC2612345. Perbaiki di Dimensi Suara lalu tekan Retry.'),
            'original_release_date.date' => \App\Support\SystemBranding::text('Original Released Date ":input" tidak valid. Gunakan tanggal yang valid di Dimensi Suara lalu tekan Retry.'),
            'release_date.date' => \App\Support\SystemBranding::text('Planned Release Date ":input" tidak valid. Gunakan tanggal yang valid di Dimensi Suara lalu tekan Retry.'),
            'pre_release_date.date' => \App\Support\SystemBranding::text('Tanggal pre-release ":input" tidak valid. Perbaiki tanggal TikTok & YouTube Music Pre-Release Date di Dimensi Suara lalu tekan Retry.'),
            'genre.required' => \App\Support\SystemBranding::text('Genre belum diisi. Pilih genre di Dimensi Suara lalu tekan Retry.'),
            'cover.required' => \App\Support\SystemBranding::text('Cover Art tidak ditemukan. Unggah cover di Dimensi Suara lalu tekan Retry.'),
            'cover.url' => \App\Support\SystemBranding::text('Link Cover Art tidak valid atau tidak dapat diakses. Unggah ulang cover di Dimensi Suara lalu tekan Retry.'),
            'tracks.*.audio.required' => \App\Support\SystemBranding::text('Audio Master tidak ditemukan. Unggah file Full Track di Dimensi Suara lalu tekan Retry.'),
            'tracks.*.audio.url' => \App\Support\SystemBranding::text('Link Audio Master tidak valid atau tidak dapat diakses. Unggah ulang audio di Dimensi Suara lalu tekan Retry.'),
            'tracks.*.primary_artist.required' => \App\Support\SystemBranding::text('Primary Artist belum diisi pada track. Isi artis utama di Dimensi Suara lalu tekan Retry.'),
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }

        // Return the original $data (not $v->validated()) so that fields outside
        // the explicit rule list (e.g. tiktok_audio, previously_released, etc.)
        // are preserved for downstream processing.
        return $data;
    }
}
