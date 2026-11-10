<?php

declare(strict_types=1);

namespace App\Services\Automation;

use RuntimeException;
use Symfony\Component\Process\Process;

final class AssetValidator
{
    public function audio(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('AUDIO_INVALID: file does not exist.');
        }

        $process = new Process([(string) config('automation.ffprobe_binary', 'ffprobe'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $absolutePath]);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('AUDIO_INVALID: ffprobe could not read the file.');
        }

        $probe = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $stream = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio');
        if (! $stream || ! str_starts_with((string) ($stream['codec_name'] ?? ''), 'pcm_')) {
            throw new RuntimeException('AUDIO_INVALID: audio master must be uncompressed PCM WAV.');
        }

        $bitDepth = (int) (($stream['bits_per_raw_sample'] ?? null) ?: ($stream['bits_per_sample'] ?? 0));
        if ($bitDepth < 16 || (int) ($stream['sample_rate'] ?? 0) < 44100) {
            throw new RuntimeException('AUDIO_INVALID: minimum is 16-bit / 44.1kHz.');
        }

        return ['codec' => $stream['codec_name'], 'sample_rate' => (int) $stream['sample_rate'], 'channels' => (int) $stream['channels'], 'bit_depth' => $bitDepth, 'duration' => (float) ($probe['format']['duration'] ?? 0)];
    }

    public function cover(string $absolutePath): array
    {
        $info = @getimagesize($absolutePath);
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
            throw new RuntimeException('COVER_INVALID: cover must be JPEG or PNG.');
        }
        if ($info[0] !== $info[1] || $info[0] < 3000) {
            throw new RuntimeException('COVER_INVALID: cover must be square and at least 3000x3000.');
        }

        return ['width' => $info[0], 'height' => $info[1], 'mime_type' => $info['mime'], 'file_size' => filesize($absolutePath), 'checksum_sha256' => hash_file('sha256', $absolutePath)];
    }
}
