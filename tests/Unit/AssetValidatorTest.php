<?php

namespace Tests\Unit;

use App\Services\Automation\AssetValidator;
use RuntimeException;
use Tests\TestCase;

class AssetValidatorTest extends TestCase
{
    public function test_rejects_missing_audio(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AUDIO_INVALID');
        app(AssetValidator::class)->audio(__DIR__.'/missing.wav');
    }

    public function test_rejects_non_image_cover(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cover');
        file_put_contents($path, 'not an image');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('COVER_INVALID');
            app(AssetValidator::class)->cover($path);
        } finally {
            @unlink($path);
        }
    }
}
