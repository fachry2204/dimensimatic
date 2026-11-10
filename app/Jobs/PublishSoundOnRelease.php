<?php

namespace App\Jobs;

use App\Enums\Platform;
use App\Models\PublishRelease;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PublishSoundOnRelease implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 2700;

    public function __construct(public readonly string $publishReleaseId)
    {
        $this->onQueue('cms-verification');
    }

    public function middleware(): array
    {
        // Drop duplicate queue messages while this release is already owned.
        // Releasing them would consume attempts before the first Submit ends.
        return [(new WithoutOverlapping('publish-soundon-'.$this->publishReleaseId))->shared()->dontRelease()->expireAfter(3300)];
    }

    public function handle(PlaywrightClient $worker, SessionManager $sessions): void
    {
        $item = PublishRelease::findOrFail($this->publishReleaseId);
        if ($item->status === 'success') {
            return;
        }
        try {
            if (! $item->cms_release_url) {
                throw new RuntimeException('Rilisan Under Review CMS belum cocok secara unik dengan judul dan artis draft ini. Ambil ulang daftar atau periksa metadata.');
            }
            if (! $item->soundon_published_at) {
                $attempt = (string) Str::uuid();
                $item->update(['status' => 'publishing', 'error' => null, 'worker_version' => null, 'progress_attempt' => $attempt, 'progress_percent' => 5, 'progress_message' => 'Menyiapkan sesi dan menunggu browser SoundOn']);
                $runtime = $worker->runtimeInfo();
                $item->update(['worker_version' => $runtime['version']]);
                $account = $sessions->ensure(Platform::SoundOn);
                if ($account->status === 'manual_auth_required') {
                    throw new RuntimeException('SoundOn perlu login manual di Pengaturan Platform.');
                }
                try {
                    $result = $worker->publishSoundOnDraft($item->draft_id, $item->draft_url, $item->title, $item->artist, $account->session_state_encrypted, $attempt, fn (array $snapshot) => $item->syncWorkerProgress($snapshot, $attempt));
                } catch (Throwable $error) {
                    if ($error instanceof ConnectionException) {
                        $item->update(['progress_message' => 'Koneksi terputus; memeriksa penerimaan SoundOn tanpa mengulang Submit']);
                        try {
                            $result = $worker->reconcileSoundOnPublish($item->draft_id, $item->title, $item->artist, $account->session_state_encrypted);
                        } catch (Throwable $reconcileError) {
                            throw new RuntimeException('SOUNDON_CONNECTION_LOST: Koneksi worker terputus. Penerimaan Submit belum dapat diperiksa; status Dimensi Suara belum diubah. Buka kembali aplikasi dan cek status SoundOn sebelum mencoba ulang. Detail: '.$reconcileError->getMessage(), 0, $error);
                        }
                        if (($result['published'] ?? false) !== true) {
                            throw new RuntimeException('SOUNDON_CONNECTION_LOST: Koneksi worker terputus dan status publish belum terkonfirmasi. Submit tidak diulang otomatis. Periksa rilisan di SoundOn lalu coba lagi.', 0, $error);
                        }
                    } else {
                        if (! str_contains($error->getMessage(), 'AUTH_SESSION_EXPIRED')) throw $error;
                        // The SPA can redirect after initial validation. Refresh once;
                        // the worker checks existing published status before any retry.
                        $item->update(['progress_message' => 'Memperbarui sesi login SoundOn']);
                        $sessions->clear(Platform::SoundOn);
                        $account = $sessions->ensure(Platform::SoundOn);
                        if ($account->status !== 'active') {
                            throw new RuntimeException('Sesi SoundOn berakhir dan memerlukan login manual di Pengaturan Platform.');
                        }
                        $result = $worker->publishSoundOnDraft($item->draft_id, $item->draft_url, $item->title, $item->artist, $account->session_state_encrypted, $attempt, fn (array $snapshot) => $item->syncWorkerProgress($snapshot, $attempt));
                    }
                }
                if (($result['published'] ?? false) !== true) {
                    throw new RuntimeException('SoundOn belum mengonfirmasi publish. Status CMS belum diubah.');
                }
                $item->update(['soundon_published_at' => now(), 'progress_percent' => 85, 'progress_message' => 'Publish SoundOn terkonfirmasi']);
            }
            $item->update(['status' => 'updating_cms', 'error' => null, 'progress_percent' => 90, 'progress_message' => 'Menjalankan Upload Release dan memeriksa status CMS']);
            $account = $sessions->ensure(Platform::Soundfresh);
            if ($account->status === 'manual_auth_required') {
                throw new RuntimeException('CMS perlu login manual di Pengaturan Platform.');
            }
            $result = $worker->moveSoundfreshToUploading($item->cms_release_url, $account->session_state_encrypted);
            if (($result['status'] ?? null) !== 'uploading') {
                throw new RuntimeException('CMS belum mengonfirmasi Upload Release.');
            }
            $item->update(['status' => 'success', 'cms_uploaded_at' => now(), 'error' => null, 'progress_percent' => 100, 'progress_message' => 'Selesai: SoundOn dan CMS berhasil']);
            ReleaseJob::query()->where('soundfresh_release_id', $item->cms_release_id)
                ->where('soundfresh_workflow_status', 'under_review')->update(['soundfresh_workflow_status' => 'uploading']);
        } catch (Throwable $error) {
            report($error);
            $item->refresh();
            $item->update(['status' => 'failed', 'error' => $error->getMessage(), 'progress_message' => 'Gagal pada tahap: '.($item->progress_message ?: 'Persiapan publish')]);
        }
    }

    public function failed(?Throwable $error): void
    {
        PublishRelease::whereKey($this->publishReleaseId)->whereIn('status', ['queued', 'publishing', 'updating_cms'])
            ->update(['status' => 'failed', 'error' => 'Proses publish terputus: '.($error?->getMessage() ?? 'worker berhenti')]);
    }
}
