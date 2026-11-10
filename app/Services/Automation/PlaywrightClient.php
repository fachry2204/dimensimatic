<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AutomationSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PlaywrightClient
{
    public function validateSession(string $platform, ?array $state): array
    {
        return $this->post('/v1/sessions/validate', ['platform' => $platform, 'session_state' => $state]);
    }

    public function login(string $platform, string $email, string $password): array
    {
        return $this->post('/v1/sessions/login', ['platform' => $platform, 'email' => $email, 'password' => $password]);
    }

    public function pending(?int $limit = null, ?array $sessionState = null, bool $includeMultiTrack = false): array
    {
        return $this->post('/v1/soundfresh/pending', [
            'action' => 'list_pending_releases',
            'session_state' => $sessionState,
            'options' => ['max_items' => $limit, 'include_multi_track' => $includeMultiTrack],
        ])['items'] ?? [];
    }

    public function underReview(?int $limit = null, ?array $sessionState = null): array
    {
        return $this->post('/v1/soundfresh/pending', [
            'action' => 'list_under_review_releases',
            'session_state' => $sessionState,
            'options' => ['max_items' => $limit, 'include_multi_track' => true, 'release_status' => 'under_review'],
        ])['items'] ?? [];
    }

    public function soundOnDrafts(?array $sessionState = null): array
    {
        return $this->post('/v1/soundon/drafts/list', ['session_state' => $sessionState])['items'] ?? [];
    }

    public function startDraftCollection(string $id, ?array $sessionState = null): array
    {
        return $this->post('/v1/soundon/drafts/collect/start', ['collection_id' => $id, 'session_state' => $sessionState]);
    }

    public function draftCollectionStatus(string $id): array
    {
        return $this->post('/v1/soundon/drafts/collect/status', ['collection_id' => $id]);
    }

    public function startCmsCollection(string $id, ?array $sessionState = null): array
    {
        return $this->post('/v1/soundfresh/under-review/collect/start', ['collection_id' => $id, 'session_state' => $sessionState]);
    }

    public function cmsCollectionStatus(string $id): array
    {
        return $this->post('/v1/soundfresh/under-review/collect/status', ['collection_id' => $id]);
    }

    public function runtimeInfo(): array
    {
        try {
            $info = $this->get('/v1/runtime', 5);
        } catch (\Throwable $error) {
            throw new RuntimeException('WORKER_UNAVAILABLE: Worker tidak aktif atau belum mendukung pemeriksaan versi. Tutup aplikasi dan pasang ulang pembaruan. Detail: '.$error->getMessage(), 0, $error);
        }
        $expected = (string) config('automation.bundled_app_version');
        if (($info['version'] ?? '') !== $expected || ! in_array('publish-reconcile', $info['capabilities'] ?? [], true)) {
            throw new RuntimeException('WORKER_VERSION_MISMATCH: Worker '.($info['version'] ?? 'tidak dikenal').' tidak sesuai aplikasi '.$expected.'. Tutup aplikasi, pasang pembaruan, lalu buka kembali.');
        }
        return $info;
    }

    public function reconcileSoundOnPublish(string $draftId, string $title, string $artist, ?array $sessionState): array
    {
        return $this->post('/v1/soundon/drafts/reconcile', ['draft_id' => $draftId, 'title' => $title, 'artist' => $artist, 'session_state' => $sessionState], null, 120);
    }

    public function publishSoundOnDraft(string $draftId, string $url, string $title, string $artist, ?array $sessionState = null, ?string $progressId = null, ?callable $onProgress = null): array
    {
        return $this->post('/v1/soundon/drafts/publish', ['action' => str_starts_with($draftId, 'cms-') ? 'publish_matching_draft' : 'publish_selected_draft', 'draft_id' => $draftId,
            'draft_url' => $url, 'title' => $title, 'artist' => $artist, 'session_state' => $sessionState, 'progress_id' => $progressId], $onProgress);
    }

    public function publishProgress(array $ids): array
    {
        return $this->post('/v1/soundon/drafts/publish/progress', ['ids' => array_values($ids)], null, 5)['progress'] ?? [];
    }

    public function soundOnReviewLastUpdated(array $items, ?array $sessionState = null): array
    {
        return $this->post('/v1/soundon/reviews', [
            'items' => $items,
            'session_state' => $sessionState,
        ])['matches'] ?? [];
    }

    public function uploading(?int $limit = null, ?array $sessionState = null): array
    {
        return $this->post('/v1/soundfresh/pending', [
            'action' => 'list_uploading_releases',
            'session_state' => $sessionState,
            'options' => ['max_items' => $limit, 'include_multi_track' => true, 'release_status' => 'uploading'],
        ])['items'] ?? [];
    }

    public function allSoundfreshReleases(?int $limit = null, ?array $sessionState = null): array
    {
        return $this->post('/v1/soundfresh/pending', [
            'action' => 'list_all_releases',
            'session_state' => $sessionState,
            'options' => ['max_items' => $limit, 'include_multi_track' => true, 'release_status' => 'all'],
        ])['items'] ?? [];
    }

    /** @return array<string, array{release_id: string, workflow_status: string}> */
    public function soundfreshDuplicates(array $items, ?array $sessionState = null): array
    {
        return (array) ($this->post('/v1/soundfresh/pending', [
            'action' => 'search_all_release_duplicates',
            'session_state' => $sessionState,
            'options' => ['release_status' => 'all', 'duplicate_lookups' => $items],
        ])['matches'] ?? []);
    }

    public function moveSoundfreshToUploading(string $releaseUrl, ?array $sessionState = null): array
    {
        return $this->post('/v1/soundfresh/releases/upload-release', [
            'release_url' => $releaseUrl,
            'session_state' => $sessionState,
        ]);
    }

    public function extract(string $jobId, string $url, ?array $sessionState = null): array
    {
        return $this->post('/v1/soundfresh/releases/extract', ['job_id' => $jobId, 'release_url' => $url, 'session_state' => $sessionState]);
    }

    public function prepareAttempt(string $jobId): array
    {
        return $this->post('/v1/jobs/'.rawurlencode($jobId).'/prepare', []);
    }

    public function createDraft(string $jobId, array $metadata, ?array $sessionState = null, bool $duplicatesChecked = false): array
    {
        return $this->post('/v1/soundon/drafts/create', ['job_id' => $jobId, 'target_action' => 'save_draft', 'metadata' => $metadata, 'session_state' => $sessionState, 'duplicates_checked' => $duplicatesChecked]);
    }

    public function findDraft(string $title, string $artist, ?array $sessionState = null): ?array
    {
        $result = $this->post('/v1/soundon/drafts/find', [
            'title' => $title,
            'artist' => $artist,
            'session_state' => $sessionState,
        ]);

        return ($result['found'] ?? false) ? $result : null;
    }

    /** @return array<string, array{found: bool, draft_id: string, draft_url: string}> */
    public function findDrafts(array $items, ?array $sessionState = null): array
    {
        return (array) ($this->post('/v1/soundon/drafts/find-many', [
            'items' => $items,
            'session_state' => $sessionState,
        ])['matches'] ?? []);
    }

    /** @return array<string, array{found: bool, status: string, release_url: string, upc: ?string, isrcs: array<int, string>, reason: ?string}> */
    public function releaseStatuses(array $items, ?array $sessionState = null): array
    {
        return (array) ($this->post('/v1/soundon/releases/statuses', [
            'items' => $items,
            'session_state' => $sessionState,
        ])['matches'] ?? []);
    }

    /** Exact title + artist lookup used only by the pre-upload duplicate gate. */
    public function releaseDuplicates(array $items, ?array $sessionState = null): array
    {
        return (array) ($this->post('/v1/soundon/releases/statuses', [
            'items' => $items,
            'strict_artist' => true,
            'session_state' => $sessionState,
        ])['matches'] ?? []);
    }

    public function verifySoundfreshIdentifiers(string $releaseUrl, string $upc, array $isrcs, ?array $sessionState = null): array
    {
        return (array) ($this->post('/v1/soundfresh/releases/verify-identifiers', [
            'release_url' => $releaseUrl,
            'upc' => $upc,
            'isrcs' => array_values($isrcs),
            'session_state' => $sessionState,
        ]) ?? []);
    }

    public function rejectSoundfreshRelease(string $releaseUrl, string $reason, ?array $sessionState = null): array
    {
        return (array) ($this->post('/v1/soundfresh/releases/reject', [
            'release_url' => $releaseUrl,
            'reason' => $reason,
            'session_state' => $sessionState,
        ]) ?? []);
    }

    public function review(string $jobId, string $url, string $draftId, ?array $sessionState = null): array
    {
        return $this->post('/v1/soundfresh/releases/review', ['job_id' => $jobId, 'release_url' => $url, 'aggregator' => 'SoundOn', 'draft_id' => $draftId, 'session_state' => $sessionState]);
    }

    public function discoverSelectors(string $platform, array $sessionState, string $url): array
    {
        return $this->post('/v1/selectors/discover', ['platform' => $platform, 'session_state' => $sessionState, 'url' => $url]);
    }

    public function status(string $jobId): array
    {
        return $this->get('/v1/jobs/'.rawurlencode($jobId));
    }

    public function cancel(string $jobId): array
    {
        return $this->post('/v1/jobs/'.rawurlencode($jobId).'/cancel', []);
    }

    public function cancelAll(): array
    {
        return $this->post('/v1/jobs/cancel-all', []);
    }

    private function post(string $path, array $payload, ?callable $onProgress = null, ?int $timeout = null): array
    {
        if (str_starts_with($path, '/v1/soundfresh/') || str_starts_with($path, '/v1/sessions/') || $path === '/v1/selectors/discover') {
            $payload['cms_login_url'] = AutomationSetting::current()->cmsLoginUrl();
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$json, $this->hmacKey());
        $request = $this->signed($timestamp, $nonce, $signature);
        if ($timeout !== null) $request->timeout($timeout)->connectTimeout($timeout);
        $response = $request->withBody($json, 'application/json')->post($path);
        $snapshot = $response->json('data.progress');
        if ($onProgress && is_array($snapshot)) $onProgress($snapshot);
        $this->throwWorkerError($response);

        return (array) $response->json('data', []);
    }

    private function get(string $path, ?int $timeout = null): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.', $this->hmacKey());
        $request = $this->signed($timestamp, $nonce, $signature);
        if ($timeout !== null) $request->timeout($timeout)->connectTimeout($timeout);
        $response = $request->get($path);
        $this->throwWorkerError($response);

        return (array) $response->json('data', []);
    }

    private function hmacKey(): string
    {
        $key = (string) config('automation.hmac_key');
        if ($key === '') {
            throw new RuntimeException('AUTOMATION_HMAC_KEY is not configured.');
        }

        return $key;
    }

    private function throwWorkerError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        // Laravel's default RequestException message truncates response bodies.
        // Preserve the worker's structured error so the classifier and admin UI
        // can identify the exact SoundOn control that failed.
        $workerError = $response->json('error');
        $workerMessage = $response->json('message');
        if (is_string($workerError) && $workerError !== '') {
            // Fastify's default 500 response uses the unhelpful error label
            // "Internal Server Error" while placing the Playwright/route cause
            // in `message`. Prefer that cause so failed automation runs can be
            // diagnosed from the release row without the worker log.
            if (
                is_string($workerMessage)
                && $workerMessage !== ''
                && in_array(strtolower($workerError), ['internal server error', 'error'], true)
            ) {
                $workerError = $workerMessage;
            }

            throw new RuntimeException($workerError, $response->status());
        }

        $response->throw();
    }

    private function signed(string $timestamp, string $nonce, string $signature): PendingRequest
    {
        return $this->request()->withHeaders([
            'X-Automation-Timestamp' => $timestamp,
            'X-Automation-Nonce' => $nonce,
            'X-Automation-Signature' => $signature,
        ]);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('automation.worker_url'))
            ->acceptJson()
            ->connectTimeout(10)
            // EP/album forms process each track sequentially. Keep the HTTP
            // connection longer than the worker's adaptive album watchdog.
            ->timeout(2700);
    }
}
