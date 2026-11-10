<?php

namespace Tests\Feature;

use App\Jobs\PublishSoundOnRelease;
use App\Livewire\Automation\SoundOnReviews;
use App\Models\AutomationAccount;
use App\Models\PublishRelease;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class PublishReleasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        $this->actingAs($user);
        foreach (['soundon', 'soundfresh'] as $platform) {
            AutomationAccount::create(['platform' => $platform, 'name' => $platform, 'status' => 'active',
                'last_authenticated_at' => now(), 'session_state_encrypted' => ['cookies' => []]]);
        }
    }

    private function fakeWorker($callback = null): void
    {
        $runtime = Http::response(['data' => ['version' => config('automation.bundled_app_version'), 'capabilities' => ['publish-reconcile']]]);
        if (is_array($callback)) {
            Http::fake(['*/v1/runtime' => $runtime] + $callback);
        } else {
            Http::fake(fn ($request) => str_ends_with($request->url(), '/v1/runtime') ? $runtime : ($callback ? $callback($request) : Http::response()));
        }
    }

    private function draft(string $key = '1', array $extra = []): PublishRelease
    {
        return PublishRelease::create(array_merge(['draft_id' => $key, 'draft_url' => 'https://www.soundon.global/library/publish/single?source=draft&id='.$key,
            'title' => 'Track '.$key, 'artist' => 'Artist', 'cms_release_url' => 'https://dimensisuara.id/admin/releases/'.$key, 'cms_release_id' => $key, 'status' => 'draft'], $extra));
    }

    public function test_long_running_publish_is_not_reserved_again_after_ninety_seconds(): void
    {
        $item = $this->draft('lease');
        $job = new PublishSoundOnRelease($item->id);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $queue = Queue::connection('database');
        $queue->push($job, '', 'cms-verification');
        $reserved = $queue->pop('cms-verification');
        $this->assertNotNull($reserved);
        $this->travel(120)->seconds();
        $this->assertNull($queue->pop('cms-verification'));
        $this->travelBack();
        $this->assertSame(1, $reserved->attempts());
    }

    public function test_concurrent_duplicate_publish_message_does_not_submit_or_consume_a_retry(): void
    {
        $item = $this->draft('locked', ['status' => 'publishing']);
        $job = new PublishSoundOnRelease($item->id);
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 3300);
        $this->assertTrue($lock->get());
        try {
            $called = false;
            $middleware->handle($job, function () use (&$called) { $called = true; });
            $this->assertFalse($called);
            $this->assertNull($middleware->releaseAfter);
            $this->assertSame('publishing', $item->fresh()->status);
        } finally { $lock->release(); }
    }

    public function test_collection_uses_cms_only_and_blocks_duplicate_title_artist(): void
    {
        $this->fakeWorker(['*' => Http::response(['data' => ['items' => [
            ['release_id' => '11', 'detail_url' => 'https://dimensisuara.id/admin/releases/11', 'title' => 'Track 1', 'primary_artist' => 'Artist'],
            ['release_id' => '21', 'detail_url' => 'https://dimensisuara.id/admin/releases/21', 'title' => 'Track 2', 'primary_artist' => 'Artist'],
            ['release_id' => '22', 'detail_url' => 'https://dimensisuara.id/admin/releases/22', 'title' => 'Track 2', 'primary_artist' => 'Artist'],
        ]]])]);
        Livewire::test(SoundOnReviews::class)->call('fetchDrafts')->assertSee('Track 1')->assertSee('Track 2')->call('toggleAll')
            ->assertSet('selectedIds', fn ($ids) => count($ids) === 1);
        $this->assertDatabaseCount('publish_releases', 3);
        $this->assertDatabaseHas('publish_releases', ['draft_id' => 'cms-21', 'status' => 'blocked']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/soundfresh/pending') && $request['options']['release_status'] === 'under_review');
    }

    public function test_cms_refresh_preserves_published_checkpoint_and_retries_only_cms(): void
    {
        $old = $this->draft('11', ['soundon_published_at' => now(), 'status' => 'failed']);
        $this->fakeWorker(['*' => Http::response(['data' => ['items' => [[
            'release_id' => '11', 'detail_url' => $old->cms_release_url, 'title' => $old->title, 'primary_artist' => $old->artist,
        ]]]])]);
        Livewire::test(SoundOnReviews::class)->call('fetchDrafts');
        $this->assertDatabaseCount('publish_releases', 1);
        $this->assertNotNull($old->fresh()->soundon_published_at);
        $this->fakeWorker(['*' => Http::response(['data' => ['status' => 'uploading']])]);
        app()->call([new PublishSoundOnRelease($old->id), 'handle']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/drafts/publish'));
    }

    public function test_cms_first_publish_requests_title_artist_matching_and_updates_cms_after_success(): void
    {
        $item = $this->draft('cms-11');
        $this->fakeWorker(function ($request) {
            return Http::response(['data' => str_ends_with($request->url(), '/drafts/publish') ? ['published' => true] : ['status' => 'uploading']]);
        });
        app()->call([new PublishSoundOnRelease($item->id), 'handle']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/drafts/publish') && $request['action'] === 'publish_matching_draft' && $request['title'] === $item->title && $request['artist'] === $item->artist);
        $this->assertSame('success', $item->fresh()->status);
    }

    public function test_background_collection_reports_real_progress_and_imports_only_when_complete(): void
    {
        $poll = 0;
        $this->fakeWorker(function ($request) use (&$poll) {
            if (str_ends_with($request->url(), '/collect/start')) {
                return Http::response(['data' => ['collection_id' => $request['collection_id']]]);
            }
            if (str_ends_with($request->url(), '/collect/status')) {
                $poll++;

                return Http::response(['data' => ['status' => $poll === 1 ? 'running' : 'completed', 'collected' => $poll === 1 ? 20 : 53, 'total' => 53, 'page' => $poll === 1 ? 1 : 3, 'message' => 'Membaca halaman',
                    'items' => [['release_id' => '47', 'detail_url' => 'https://dimensisuara.id/admin/releases/47', 'title' => 'Track 47', 'primary_artist' => 'Artist']]]]);
            }

            return Http::response(['data' => ['items' => [['release_id' => '47', 'detail_url' => 'https://dimensisuara.id/admin/releases/47', 'title' => 'Track 47', 'primary_artist' => 'Artist']]]]);
        });
        $page = Livewire::test(SoundOnReviews::class)->call('startCollection')->assertSet('collectionStatus', 'queued')->assertSee('Sedang mengambil Under Review CMS');
        $page->call('pollCollection')->assertSet('collectedDrafts', 20)->assertSet('totalDrafts', 53)->assertSet('collectionPage', 1)->assertSee('20 / 53 rilisan');
        $this->assertDatabaseCount('publish_releases', 0);
        $page->call('pollCollection')->assertSet('collectionStatus', 'completed')->assertSet('collectionId', null)->assertSee('Track 47');
        $this->assertDatabaseHas('publish_releases', ['draft_id' => 'cms-47']);
    }

    public function test_partial_collection_failure_does_not_replace_existing_drafts_and_can_restart(): void
    {
        $item = $this->draft();
        $this->fakeWorker(function ($request) {
            if (str_ends_with($request->url(), '/collect/start')) {
                return Http::response(['data' => ['collection_id' => $request['collection_id']]]);
            }

            return Http::response(['data' => ['status' => 'failed', 'collected' => 20, 'total' => 53, 'page' => 1, 'error' => 'Pagination belum dikenali']]);
        });
        Livewire::test(SoundOnReviews::class)->call('startCollection')->call('pollCollection')->assertSet('collectionStatus', 'failed')
            ->assertSet('collectionId', null)->assertSee('Pagination belum dikenali')->assertSee('20 / 53 rilisan')->call('startCollection')->assertSet('collectionStatus', 'queued');
        $this->assertSame('draft', $item->fresh()->status);
    }

    public function test_reopening_page_resumes_progress_and_duplicate_click_does_not_start_another_scan(): void
    {
        $this->fakeWorker(function ($request) {
            if (str_ends_with($request->url(), '/collect/start')) {
                return Http::response(['data' => ['collection_id' => $request['collection_id']]]);
            }

            return Http::response(['data' => ['status' => 'running', 'collected' => 40, 'total' => 53, 'page' => 2, 'message' => 'Membaca halaman 2']]);
        });
        $first = Livewire::test(SoundOnReviews::class)->call('startCollection');
        $id = $first->get('collectionId');
        Livewire::test(SoundOnReviews::class)->assertSet('collectionId', $id)->call('startCollection')->call('pollCollection')
            ->assertSet('collectedDrafts', 40)->assertSee('40 / 53 rilisan');
        Http::assertSentCount(2);
        Cache::forget('draft-collection:'.auth()->id());
    }

    public function test_new_collection_clears_visible_table_and_keeps_it_empty_on_failure(): void
    {
        $item = $this->draft();
        $this->fakeWorker(function ($request) {
            if (str_ends_with($request->url(), '/collect/start')) {
                return Http::response(['data' => ['collection_id' => $request['collection_id']]]);
            }

            return Http::response(['data' => ['status' => 'failed', 'collected' => 20, 'total' => 185, 'page' => 2, 'error' => 'Data berikutnya belum siap']]);
        });
        Queue::fake();
        $page = Livewire::test(SoundOnReviews::class)->assertSee($item->title)->call('startCollection')
            ->assertSet('showDrafts', false)->assertDontSee($item->title)->assertSee('Tabel telah dikosongkan');
        $page->set('selectedIds', [$item->id])->call('publishSelected')->assertSet('selectedIds', []);
        Queue::assertNothingPushed();
        $page->call('pollCollection')->assertDontSee($item->title)->assertSee('20 / 185 rilisan');
        Livewire::test(SoundOnReviews::class)->assertSet('showDrafts', false)->assertDontSee($item->title);
        $this->assertDatabaseHas('publish_releases', ['id' => $item->id]);
    }

    public function test_completed_refresh_shows_only_the_newly_collected_drafts(): void
    {
        $old = $this->draft('old', ['status' => 'success', 'title' => 'Old finished release']);
        $this->fakeWorker(function ($request) {
            if (str_ends_with($request->url(), '/collect/start')) {
                return Http::response(['data' => ['collection_id' => $request['collection_id']]]);
            }
            if (str_ends_with($request->url(), '/collect/status')) {
                return Http::response(['data' => ['status' => 'completed', 'collected' => 1, 'total' => 1, 'page' => 1, 'items' => [
                    ['release_id' => 'new', 'detail_url' => 'https://dimensisuara.id/admin/releases/99', 'title' => 'New draft', 'primary_artist' => 'Artist'],
                ]]]);
            }

            return Http::response(['data' => ['items' => []]]);
        });
        Livewire::test(SoundOnReviews::class)->assertSee($old->title)->call('startCollection')->assertDontSee($old->title)
            ->call('pollCollection')->assertSet('showDrafts', true)->assertSee('New draft')->assertDontSee($old->title);
        Livewire::test(SoundOnReviews::class)->assertSee('New draft')->assertDontSee($old->title);
    }

    public function test_select_one_or_all_and_queue_only_once(): void
    {
        Queue::fake();
        $first = $this->draft();
        $second = $this->draft('2');
        $blocked = $this->draft('3', ['cms_release_url' => null]);
        $page = Livewire::test(SoundOnReviews::class)->set('selectedIds', [$first->id])->call('publishSelected');
        Queue::assertPushed(PublishSoundOnRelease::class, 1);
        $page->set('selectedIds', [$first->id])->call('publishSelected');
        Queue::assertPushed(PublishSoundOnRelease::class, 1);
        $page->call('toggleAll')->assertSet('selectedIds', [$second->id])->call('publishSelected');
        Queue::assertPushedOn('cms-verification', PublishSoundOnRelease::class);
        Queue::assertPushed(PublishSoundOnRelease::class, 2);
        $this->assertSame('draft', $blocked->fresh()->status);
    }

    public function test_soundon_failure_never_changes_cms(): void
    {
        $item = $this->draft();
        $this->fakeWorker(['*' => Http::response(['data' => ['published' => false]])]);
        app()->call([new PublishSoundOnRelease($item->id), 'handle']);
        Http::assertSentCount(2);
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertNull($item->fresh()->soundon_published_at);
    }

    public function test_cms_retry_does_not_republish_soundon_and_success_is_idempotent(): void
    {
        $item = $this->draft();
        $cmsAttempt = 0;
        $this->fakeWorker(function ($request) use (&$cmsAttempt) {
            if (str_ends_with($request->url(), '/drafts/publish')) {
                return Http::response(['data' => ['published' => true]]);
            }
            $cmsAttempt++;

            return $cmsAttempt === 1 ? Http::response(['error' => 'CMS unavailable'], 422) : Http::response(['data' => ['status' => 'uploading']]);
        });
        $job = new PublishSoundOnRelease($item->id);
        app()->call([$job, 'handle']);
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertNotNull($item->fresh()->soundon_published_at);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame('success', $item->fresh()->status);
        $this->assertSame(100, $item->fresh()->progress_percent);
        $this->assertNotNull($item->fresh()->cms_uploaded_at);
        Http::assertSentCount(4);
        $this->assertSame(1, count(Http::recorded(fn ($request) => str_ends_with($request->url(), '/drafts/publish'))));
    }

    public function test_next_submit_reason_is_saved_and_shown_on_the_release(): void
    {
        $item = $this->draft();
        $reason = 'SOUNDON_PUBLISH_SUBMIT_FAILED: Tahap 3 (Submit). Pesan SoundOn: Audio file is still processing.';
        $this->fakeWorker(['*' => Http::response(['error' => $reason], 422)]);
        app()->call([new PublishSoundOnRelease($item->id), 'handle']);
        $this->assertSame($reason, $item->fresh()->error);
        $this->assertNull($item->fresh()->soundon_published_at);
        Livewire::test(SoundOnReviews::class)->assertSee('Alasan gagal / perlu diperiksa:')->assertSee($reason);
        Http::assertSentCount(2);
    }

    public function test_expired_soundon_session_is_refreshed_once_before_publishing(): void
    {
        $item = $this->draft('cms-11');
        AutomationAccount::where('platform', 'soundon')->first()->update(['email_encrypted' => 'test@example.test', 'password_encrypted' => 'test-password']);
        $publishAttempts = 0;
        $this->fakeWorker(function ($request) use (&$publishAttempts) {
            if (str_ends_with($request->url(), '/sessions/login')) return Http::response(['data' => ['session_state' => ['cookies' => [], 'origins' => []], 'manual_auth_required' => false]]);
            if (str_ends_with($request->url(), '/drafts/publish')) {
                $publishAttempts++;
                return $publishAttempts === 1 ? Http::response(['error' => 'AUTH_SESSION_EXPIRED: login redirect'], 422) : Http::response(['data' => ['published' => true]]);
            }
            return Http::response(['data' => ['status' => 'uploading']]);
        });
        app()->call([new PublishSoundOnRelease($item->id), 'handle']);
        $this->assertSame(2, $publishAttempts);
        $this->assertSame('success', $item->fresh()->status);
        Http::assertSentCount(5);
    }

    public function test_live_poll_updates_only_the_publishing_release_progress(): void
    {
        $attempt = (string) \Illuminate\Support\Str::uuid();
        $item = $this->draft('progress', ['status' => 'publishing', 'progress_attempt' => $attempt, 'progress_percent' => 25]);
        $queued = $this->draft('queued', ['status' => 'queued', 'progress_percent' => 1]);
        $this->fakeWorker(['*' => Http::response(['data' => ['progress' => [$attempt => ['percent' => 55, 'message' => 'Next — tahap 3']]]])]);
        Livewire::test(SoundOnReviews::class)->call('pollCollection')->assertSee('Next — tahap 3')->assertSee('55%')
            ->assertSee('aria-valuenow="55"', false);
        $this->assertSame(55, $item->fresh()->progress_percent);
        $this->assertSame(1, $queued->fresh()->progress_percent);
        Http::assertSentCount(1);
    }

    public function test_old_polling_snapshot_cannot_regress_progress_or_overwrite_success(): void
    {
        $attempt = (string) \Illuminate\Support\Str::uuid();
        $item = $this->draft('progress', ['status' => 'publishing', 'progress_attempt' => $attempt, 'progress_percent' => 55, 'progress_message' => 'Next']);
        $item->syncWorkerProgress(['percent' => 25, 'message' => 'Old search'], $attempt);
        $item->syncWorkerProgress(['percent' => 72, 'message' => 'Old attempt'], (string) \Illuminate\Support\Str::uuid());
        $this->assertSame(55, $item->fresh()->progress_percent);
        $item->update(['status' => 'success', 'progress_percent' => 100]);
        $item->syncWorkerProgress(['percent' => 80, 'message' => 'Late worker'], $attempt);
        $this->assertSame(100, $item->fresh()->progress_percent);
    }

    public function test_submit_failure_keeps_last_worker_progress_and_red_bar(): void
    {
        $item = $this->draft('progress');
        $this->fakeWorker(['*' => Http::response(['error' => 'SOUNDON_PUBLISH_SUBMIT_FAILED: Audio processing', 'data' => ['progress' => ['percent' => 72, 'message' => 'Submit — tahap 4']]], 422)]);
        app()->call([new PublishSoundOnRelease($item->id), 'handle']);
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertSame(72, $item->fresh()->progress_percent);
        Livewire::test(SoundOnReviews::class)->assertSee('72%')->assertSee('Submit — tahap 4')->assertSee('bg-red-500', false);
        $this->assertNull($item->fresh()->soundon_published_at);
    }

    public function test_worker_outage_keeps_progress_and_retry_starts_with_a_new_attempt(): void
    {
        $item = $this->draft('progress', ['status' => 'publishing', 'progress_attempt' => (string) \Illuminate\Support\Str::uuid(), 'progress_percent' => 55, 'progress_message' => 'Next']);
        $this->fakeWorker(['*' => Http::response(['error' => 'worker offline'], 503)]);
        $page = Livewire::test(SoundOnReviews::class)->call('pollCollection')->assertSee('55%');
        $this->assertSame('publishing', $item->fresh()->status);
        $item->update(['status' => 'failed']);
        Queue::fake();
        $page->set('selectedIds', [$item->id])->call('publishSelected');
        $this->assertSame(1, $item->fresh()->progress_percent);
        $this->assertNull($item->fresh()->progress_attempt);
    }

    public function test_wrong_worker_version_stops_before_any_submit(): void
    {
        $item = $this->draft();
        Http::fake(['*' => Http::response(['data' => ['version' => '1.1.85', 'capabilities' => []]])]);
        app()->call([new PublishSoundOnRelease($item->id), 'handle']);
        $this->assertStringContainsString('WORKER_VERSION_MISMATCH', $item->fresh()->error);
        Http::assertSentCount(1);
        $this->assertNull($item->fresh()->soundon_published_at);
    }

    public function test_connection_reset_reconciles_without_another_submit(): void
    {
        $item = $this->draft('cms-55');
        $attempts = 0;
        $this->fakeWorker(function ($request) use (&$attempts) {
            if (str_ends_with($request->url(), '/drafts/publish')) { $attempts++; throw new \Illuminate\Http\Client\ConnectionException('cURL error 56: reset'); }
            if (str_ends_with($request->url(), '/drafts/reconcile')) return Http::response(['data' => ['published' => true, 'status' => 'under_review']]);
            return Http::response(['data' => ['status' => 'uploading']]);
        });
        app()->call([new PublishSoundOnRelease($item->id), 'handle']);
        $this->assertSame(1, $attempts);
        $this->assertSame('success', $item->fresh()->status);
        $this->assertSame(config('automation.bundled_app_version'), $item->fresh()->worker_version);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/drafts/reconcile'));
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/releases/upload-release')));
    }

    public function test_unconfirmed_connection_reset_does_not_change_cms(): void
    {
        $item = $this->draft('cms-55');
        $this->fakeWorker(function ($request) {
            if (str_ends_with($request->url(), '/drafts/publish')) throw new \Illuminate\Http\Client\ConnectionException('cURL error 56: reset');
            return Http::response(['data' => ['published' => false]]);
        });
        app()->call([new PublishSoundOnRelease($item->id), 'handle']);
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertStringContainsString('Submit tidak diulang otomatis', $item->fresh()->error);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/soundfresh/'));
    }

    public function test_legacy_cms_names_and_error_codes_use_current_branding(): void
    {
        $this->assertSame('ke Dimensi Suara CMS_UI_CHANGED', \App\Support\SystemBranding::text('ke Soundfresh SOUNDFRESH_UI_CHANGED'));
        \App\Models\AutomationSetting::current()->update(['system_name' => 'Sistem Saya']);
        \App\Support\SystemBranding::refresh();
        $this->assertSame('ke Sistem Saya', \App\Support\SystemBranding::text('ke Soundfresh'));
    }

    public function test_unmatched_draft_never_publishes(): void
    {
        $item = $this->draft('1', ['cms_release_url' => null]);
        $this->fakeWorker();
        app()->call([new PublishSoundOnRelease($item->id), 'handle']);
        Http::assertNothingSent();
        $this->assertSame('failed', $item->fresh()->status);
    }
}
