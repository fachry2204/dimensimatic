<?php

namespace Tests\Feature;

use App\Enums\ReleaseCheckpoint;
use App\Enums\ReleaseJobStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResumeCmsReviewTest extends TestCase
{
    use RefreshDatabase;

    private function savedDraft(): ReleaseJob
    {
        AutomationAccount::create([
            'platform' => 'soundfresh', 'name' => 'Dimensimatic', 'status' => 'active',
            'last_authenticated_at' => now(), 'session_state_encrypted' => ['cookies' => []],
        ]);
        $run = AutomationRun::create(['status' => 'running']);

        return ReleaseJob::create([
            'automation_run_id' => $run->id, 'status' => 'queued',
            'soundfresh_release_id' => '3833', 'soundfresh_release_url' => 'https://dimensisuara.id/admin/releases/3833',
            'idempotency_key' => 'resume-review-3833', 'release_title' => 'The Sweetness of a Rose',
            'soundon_draft_id' => '7705073981900032001', 'soundon_draft_status' => 'created',
            'checkpoint' => 'draft_saved', 'metadata_snapshot_json' => null,
        ]);
    }

    public function test_saved_draft_resumes_cms_review_without_soundon_login_metadata_extraction_or_upload(): void
    {
        $release = $this->savedDraft();
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['reviewed' => true, 'status' => 'under_review']])]);
        app()->call([new ProcessReleaseJob($release->id), 'handle']);

        $this->assertSame(ReleaseJobStatus::Completed, $release->fresh()->status);
        $this->assertSame('under_review', $release->fresh()->soundfresh_workflow_status);
        $this->assertSame('7705073981900032001', $release->fresh()->soundon_draft_id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/soundfresh/releases/review')
            && $request['aggregator'] === 'SoundOn' && $request['draft_id'] === '7705073981900032001');
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue(str_ends_with($request->url(), '/prepare') || str_ends_with($request->url(), '/v1/soundfresh/releases/review'));
        }
        $this->assertDatabaseCount('automation_accounts', 1);
    }

    public function test_review_failure_retains_saved_draft_for_a_review_only_retry(): void
    {
        $release = $this->savedDraft();
        $reviewFails = true;
        Http::fake(function ($request) use (&$reviewFails) {
            if ($reviewFails && str_ends_with($request->url(), '/v1/soundfresh/releases/review')) {
                return Http::response(['success' => false, 'error' => 'SOUNDFRESH_REVIEW_NOT_CONFIRMED:status remained pending'], 422);
            }
            return Http::response(['success' => true, 'data' => ['reviewed' => true, 'status' => 'under_review']]);
        });
        app()->call([new ProcessReleaseJob($release->id), 'handle']);

        $this->assertSame(ReleaseJobStatus::NeedsAttention, $release->fresh()->status);
        $this->assertSame(ReleaseCheckpoint::DraftSaved, $release->fresh()->checkpoint);
        $this->assertSame('CMS_REVIEW_FAILED', $release->fresh()->error_code);
        $this->assertSame('7705073981900032001', $release->fresh()->soundon_draft_id);
        $this->assertStringContainsString('tanpa membuat draft baru', $release->fresh()->error_message);

        $release->refresh()->update(['status' => 'queued']);
        $reviewFails = false;
        app()->call([new ProcessReleaseJob($release->id), 'handle']);
        $this->assertSame(ReleaseJobStatus::Completed, $release->fresh()->status);
        $this->assertSame(1, $release->automationRun->fresh()->completed);
    }
}
