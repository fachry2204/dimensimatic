<?php

namespace App\Livewire\Automation;

use App\Enums\Platform;
use App\Jobs\PublishSoundOnRelease;
use App\Models\PublishRelease;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class SoundOnReviews extends Component
{
    public array $selectedIds = [];

    public ?string $message = null;

    #[Locked]
    public ?string $collectionId = null;

    public string $collectionStatus = 'idle';

    public int $collectedDrafts = 0;

    public ?int $totalDrafts = null;

    public int $collectionPage = 0;

    public string $collectionMessage = '';

    #[Locked]
    public bool $showDrafts = true;

    public function mount(): void
    {
        $this->showDrafts = ! Cache::get('draft-table-hidden:'.auth()->id(), false);
        $this->collectionId = Cache::get('draft-collection:'.auth()->id());
        if ($this->collectionId) {
            $this->collectionStatus = 'running';
        }
    }

    public function startCollection(SessionManager $sessions, PlaywrightClient $worker): void
    {
        if ($this->collectionId) {
            return;
        }
        $this->showDrafts = false;
        $this->selectedIds = [];
        Cache::put('draft-table-hidden:'.auth()->id(), true, now()->addDay());
        try {
            $account = $sessions->ensure(Platform::Soundfresh);
            if ($account->status !== 'active') {
                throw new \RuntimeException('Login CMS diperlukan di Pengaturan Platform.');
            }
            $id = (string) Str::uuid();
            $worker->startCmsCollection($id, $account->session_state_encrypted);
            $this->collectionId = $id;
            Cache::put('draft-collection:'.auth()->id(), $id, now()->addHour());
            $this->collectionStatus = 'queued';
            $this->selectedIds = [];
            $this->collectedDrafts = 0;
            $this->totalDrafts = null;
            $this->collectionPage = 0;
            $this->collectionMessage = 'Membuka CMS dan membaca seluruh Under Review...';
            $this->message = null;
        } catch (Throwable $error) {
            $this->message = 'Gagal memulai pengambilan: '.$error->getMessage();
        }
    }

    public function pollCollection(SessionManager $sessions, PlaywrightClient $worker): void
    {
        $this->pollPublishProgress($worker);
        if (! $this->collectionId) {
            return;
        }
        try {
            $result = $worker->cmsCollectionStatus($this->collectionId);
            $this->collectionStatus = (string) ($result['status'] ?? 'running');
            $this->collectedDrafts = (int) ($result['collected'] ?? 0);
            $this->totalDrafts = isset($result['total']) ? (int) $result['total'] : null;
            $this->collectionPage = (int) ($result['page'] ?? 0);
            $this->collectionMessage = (string) ($result['message'] ?? 'Mengambil draft');
            if ($this->collectionStatus === 'failed') {
                throw new \RuntimeException((string) ($result['error'] ?? 'Pengambilan draft gagal'));
            }
            if ($this->collectionStatus !== 'completed') {
                return;
            }
            $this->storeCmsReleases((array) ($result['items'] ?? []));
            $this->clearCollection();
        } catch (Throwable $error) {
            report($error);
            $this->collectionStatus = 'failed';
            $this->message = 'Gagal mengambil seluruh draft: '.$error->getMessage();
            $this->clearCollection();
        }
    }

    private function clearCollection(): void
    {
        $this->collectionId = null;
        Cache::forget('draft-collection:'.auth()->id());
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)));
    }

    public function fetchDrafts(SessionManager $sessions, PlaywrightClient $worker): void
    {
        try {
            $cms = $sessions->ensure(Platform::Soundfresh);
            if ($cms->status !== 'active') {
                throw new \RuntimeException('Login CMS diperlukan.');
            }
            $this->storeCmsReleases($worker->underReview(null, $cms->session_state_encrypted));
        } catch (Throwable $error) {
            report($error);
            $this->message = 'Gagal mengambil draft: '.$error->getMessage();
        }
    }

    private function storeCmsReleases(array $releases): void
    {
        $ids = [];
        $pairs = [];
        foreach ($releases as $release) {
            $key = $this->normalize((string) ($release['title'] ?? '')).'|'.$this->normalize((string) ($release['primary_artist'] ?? ''));
            $pairs[$key] = ($pairs[$key] ?? 0) + 1;
        }
        foreach ($releases as $release) {
            $cmsId = (string) ($release['release_id'] ?? '');
            if ($cmsId === '' || empty($release['detail_url'])) continue;
            // Reuse an earlier SoundOn checkpoint when switching to CMS-first collection.
            $item = PublishRelease::where('cms_release_id', $cmsId)->orderByRaw('soundon_published_at IS NULL')->first()
                ?? new PublishRelease(['draft_id' => 'cms-'.$cmsId, 'draft_url' => 'https://www.soundon.global/library/list']);
            $ids[] = $item->id;
            if (in_array($item->status, ['queued', 'publishing', 'updating_cms', 'success'], true)) continue;
            $item->fill(['title' => $release['title'], 'artist' => $release['primary_artist'],
                'cms_release_id' => $cmsId, 'cms_release_url' => $release['detail_url'], 'collected_at' => now()]);
            $key = $this->normalize($item->title).'|'.$this->normalize($item->artist);
            if ($pairs[$key] > 1 || trim($item->artist) === '') {
                $item->status = 'blocked';
                $item->error = 'Judul dan artis CMS kosong atau memiliki pasangan ganda. Periksa metadata sebelum publish.';
            } elseif (! $item->exists || in_array($item->status, ['unavailable', 'blocked'], true)) {
                $item->status = 'draft'; $item->error = null;
            }
            // Every refreshed item resolves its SoundOn draft by title + artist.
            $item->draft_id = 'cms-'.$cmsId;
            $item->save();
            $ids[array_key_last($ids)] = $item->id;
        }
        $this->selectedIds = [];
        Cache::forever('draft-table-snapshot:'.auth()->id(), array_values(array_filter($ids)));
        $this->showDrafts = true;
        Cache::forget('draft-table-hidden:'.auth()->id());
        $this->message = count($releases).' rilisan Under Review CMS berhasil diambil. Pilih rilisan untuk mencari draft SoundOn dan publish.';
    }

    public function toggleAll(): void
    {
        if (! $this->showDrafts) {
            $this->selectedIds = [];

            return;
        }
        $ids = $this->visibleDraftQuery()->whereIn('status', ['draft', 'failed'])->whereNotNull('cms_release_url')->pluck('id')->all();
        $this->selectedIds = count($ids) > 0 && count(array_intersect($ids, $this->selectedIds)) === count($ids) ? [] : $ids;
    }

    public function publishSelected(): void
    {
        if (! $this->showDrafts) {
            $this->selectedIds = [];
            $this->message = 'Selesaikan pengambilan daftar baru sebelum memilih rilisan.';

            return;
        }
        $count = 0;
        foreach (array_unique($this->selectedIds) as $id) {
            $count += DB::transaction(function () use ($id): int {
                $item = $this->visibleDraftQuery()->lockForUpdate()->find($id);
                if (! $item || ! in_array($item->status, ['draft', 'failed'], true) || ! $item->cms_release_url) {
                    return 0;
                }
                $item->update(['status' => 'queued', 'error' => null, 'progress_attempt' => null, 'progress_percent' => $item->soundon_published_at ? 85 : 1, 'progress_message' => $item->soundon_published_at ? 'Menunggu antrean untuk melanjutkan CMS' : 'Menunggu antrean publish']);
                PublishSoundOnRelease::dispatch($item->id)->beforeCommit();

                return 1;
            }, 3);
        }
        $this->selectedIds = [];
        $this->message = $count.' rilisan diantrikan. Setelah SoundOn berhasil, status CMS diubah melalui Upload Release.';
    }

    private function pollPublishProgress(PlaywrightClient $worker): void
    {
        $items = $this->visibleDraftQuery()->where('status', 'publishing')->whereNotNull('progress_attempt')->get();
        if ($items->isEmpty()) return;
        try {
            $snapshots = $worker->publishProgress($items->pluck('progress_attempt')->all());
            foreach ($items as $item) {
                $snapshot = $snapshots[$item->progress_attempt] ?? null;
                if (is_array($snapshot)) $item->syncWorkerProgress($snapshot, $item->progress_attempt);
            }
        } catch (Throwable) {
            // Keep the last saved progress during a transient worker outage.
        }
    }

    private function visibleDraftQuery(): Builder
    {
        $query = PublishRelease::query();
        $ids = Cache::get('draft-table-snapshot:'.auth()->id());
        if (is_array($ids)) {
            $query->whereIn('id', $ids);
        }

        return $query;
    }

    public function render()
    {
        return view('livewire.automation.soundfresh-reviews', ['items' => $this->showDrafts ? $this->visibleDraftQuery()->latest()->get() : collect()])->layout('components.layouts.app');
    }
}
