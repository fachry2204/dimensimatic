<?php

namespace Tests\Unit;

use App\Services\Automation\AutomationErrorClassifier;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AutomationErrorClassifierTest extends TestCase
{
    public function test_monetization_failure_is_not_misclassified_as_changed_ui(): void
    {
        $this->assertSame(['code' => 'SOUNDON_MONETIZATION_NOT_ENABLED', 'disposition' => 'needs_attention'],
            (new AutomationErrorClassifier)->classify(new \RuntimeException('SOUNDON_MONETIZATION_NOT_ENABLED:YouTube Content ID')));
    }
    public function test_soundon_form_errors_need_attention_not_blind_retry(): void
    {
        $this->assertSame(
            ['code' => 'SOUNDON_FORM_VALIDATION', 'disposition' => 'needs_attention'],
            (new AutomationErrorClassifier)->classify(new \RuntimeException('SOUNDON_FORM_VALIDATION:4 errors detected:Primary artist')),
        );
    }
    public function test_playwright_locator_wait_timeout_is_not_reported_as_network_failure(): void
    {
        $result = (new AutomationErrorClassifier)->classify(
            new \RuntimeException('locator.waitFor: Timeout 10000ms exceeded while waiting for TikTok pre-release'),
        );

        $this->assertSame('UI_INTERACTION_TIMEOUT', $result['code']);
        $this->assertSame('retry', $result['disposition']);
    }

    public function test_classifies_upc_and_isrc_validation_separately(): void
    {
        $classifier = app(AutomationErrorClassifier::class);

        $upc = ValidationException::withMessages(['upc' => ['UPC invalid']]);
        $isrc = ValidationException::withMessages(['tracks.0.isrc' => ['ISRC invalid']]);

        $this->assertSame('UPC_INVALID', $classifier->classify($upc)['code']);
        $this->assertSame('ISRC_INVALID', $classifier->classify($isrc)['code']);
    }

    public function test_classifies_common_worker_failures_informatively(): void
    {
        $classifier = app(AutomationErrorClassifier::class);

        $this->assertSame('CONNECTION_INTERRUPTED', $classifier->classify(new \RuntimeException('cURL error 56: recv failure'))['code']);
        $this->assertSame('WORKER_UNAVAILABLE', $classifier->classify(new \RuntimeException('ECONNREFUSED 127.0.0.1:3100'))['code']);
        $this->assertSame('ASSET_MISSING', $classifier->classify(new \RuntimeException('ASSET_MISSING:audio'))['code']);
        $this->assertSame('PRE_RELEASE_INVALID', $classifier->classify(new \RuntimeException('SOUNDON_TIKTOK_PRE_RELEASE_NOT_SKIPPED'))['code']);
        $this->assertSame('SOUNDON_RELEASE_TYPE_NOT_SELECTED', $classifier->classify(new \RuntimeException('SOUNDON_RELEASE_TYPE_NOT_SELECTED:EP/Album'))['code']);
        $this->assertSame('SOUNDON_ALBUM_TIMEOUT', $classifier->classify(new \RuntimeException('SOUNDON_AUTOMATION_TIMEOUT: exceeded for 12 track(s)'))['code']);
        $this->assertSame('ALBUM_TRACK_UPLOAD_FAILED', $classifier->classify(new \RuntimeException('ASSET_UPLOAD_FAILED:album_track_not_added:test.wav'))['code']);
    }
}
