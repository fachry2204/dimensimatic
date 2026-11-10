<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Actions\Automation\CleanupTemporaryAssets;
use App\Actions\Automation\StopAutomationRun;
use App\Enums\Platform;
use App\Enums\ReleaseJobStatus;
use App\Exceptions\BrowserWorkerException;
use App\Exceptions\CredentialsNotConfiguredException;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\AutomationSetting;
use App\Models\ReleaseJob;
use App\Services\Automation\OperatorAudit;
use App\Services\Automation\SessionManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Symfony\Component\Process\Process;
use Throwable;

final class AccountSessions extends Component
{
    use \Livewire\WithFileUploads;

    public string $systemName = '';

    public $faviconUpload;

    public bool $removeFavicon = false;

    public ?string $message = null;

    public array $emails = ['soundfresh' => '', 'soundon' => ''];

    public array $passwords = ['soundfresh' => '', 'soundon' => ''];

    public string $cmsLoginUrl = '';

    public int $downloadFiles = 0;

    public string $downloadSize = '0 B';

    public bool $automaticRunEnabled = false;

    public int $automaticRunIntervalMinutes = 30;

    public string $automaticRunStartTime = '08:00';

    public string $automaticRunEndTime = '22:00';

    public function mount(): void
    {
        $setting = AutomationSetting::current();
        $this->systemName = \App\Support\SystemBranding::name();
        $this->cmsLoginUrl = $setting->cmsLoginUrl();
        $this->automaticRunEnabled = $setting->automatic_run_enabled;
        $this->automaticRunIntervalMinutes = $setting->automatic_run_interval_minutes;
        $this->automaticRunStartTime = substr((string) $setting->automatic_run_start_time, 0, 5);
        $this->automaticRunEndTime = substr((string) $setting->automatic_run_end_time, 0, 5);

        foreach (AutomationAccount::all() as $account) {
            try {
                $this->emails[$account->platform->value] = (string) ($account->email_encrypted ?? '');
            } catch (DecryptException) {
                $this->message = \App\Support\SystemBranding::text('Credential lama tidak dapat dibaca setelah pemulihan database. Masukkan ulang email dan password Dimensi Suara serta SoundOn, lalu simpan dan login kembali.');
            }
        }
        $this->refreshDownloadStats();
    }

    public function saveCredentials(string $platform): void
    {
        Gate::authorize('sessions.manage');
        $enum = Platform::from($platform);
        $account = AutomationAccount::firstOrCreate(['platform' => $enum], ['name' => $enum === Platform::Soundfresh ? \App\Support\SystemBranding::text('Dimensi Suara') : 'SoundOn', 'status' => 'expired']);
        try {
            $account->email_encrypted;
            $account->session_state_encrypted;
            $hasPassword = filled($account->password_encrypted);
        } catch (DecryptException) {
            $hasPassword = false;
        }
        $rules = ['email' => ['required', 'email', 'max:255'], 'password' => [$hasPassword ? 'nullable' : 'required', 'string', 'min:6', 'max:255']];
        $data = validator(['email' => $this->emails[$platform] ?? '', 'password' => $this->passwords[$platform] ?? ''], $rules)->validate();
        $changed = ! $hasPassword || $account->email_encrypted !== $data['email']
            || (filled($data['password']) && $account->password_encrypted !== $data['password']);
        $updates = ['email_encrypted' => $data['email']];
        if ($changed) {
            $updates += ['session_state_encrypted' => null, 'session_expires_at' => null, 'status' => 'expired'];
        }
        if (filled($data['password'])) {
            $updates['password_encrypted'] = $data['password'];
        }
        if (! $hasPassword) {
            // A restored database may contain ciphertext from an older APP_KEY.
            // Updating that model can decrypt its original values during dirty
            // checking, so replace only this account's fields atomically.
            DB::table('automation_accounts')->where('id', $account->id)->update([
                'email_encrypted' => Crypt::encryptString($data['email']),
                'password_encrypted' => Crypt::encryptString($data['password']),
                'session_state_encrypted' => null,
                'session_expires_at' => null,
                'status' => 'expired',
                'updated_at' => now(),
            ]);
        } else {
            $account->update($updates);
        }
        $this->passwords[$platform] = '';
        app(OperatorAudit::class)->record('platform_credentials_saved', 'Operator updated encrypted platform credentials.', context: ['platform' => $platform]);
        $this->message = $platform.': credential terenkripsi berhasil disimpan.';
    }

    public function saveAndLogin(string $platform, SessionManager $sessions): void
    {
        $this->saveCredentials($platform);
        $this->refresh($platform, $sessions);
    }

    public function saveSystemIdentity(): void
    {
        Gate::authorize('sessions.manage');
        $this->systemName = trim($this->systemName);
        $this->validate([
            'systemName' => ['required', 'string', 'max:80', 'not_regex:/[<>\\x00-\\x1F\\x7F]/u'],
            'faviconUpload' => ['nullable', 'file', 'max:512', 'mimes:png,ico', 'mimetypes:image/png,image/x-icon,image/vnd.microsoft.icon,application/ico,image/ico'],
            'removeFavicon' => ['boolean'],
        ]);
        $setting = AutomationSetting::current();
        $favicon = $this->removeFavicon ? null : $setting->favicon_data_uri;
        if ($this->faviconUpload) {
            $contents = file_get_contents($this->faviconUpload->getRealPath());
            $dimensions = @getimagesizefromstring($contents);
            $png = str_starts_with($contents, "\x89PNG\r\n\x1a\n") && $dimensions !== false
                && $dimensions[0] <= 1024 && $dimensions[1] <= 1024;
            $ico = str_starts_with($contents, "\x00\x00\x01\x00") && strlen($contents) >= 22 && unpack('v', substr($contents, 4, 2))[1] > 0;
            if ($ico) {
                $count = unpack('v', substr($contents, 4, 2))[1];
                $ico = $count <= 256 && strlen($contents) >= 6 + $count * 16;
                for ($index = 0; $ico && $index < $count; $index++) {
                    $entry = unpack('Vsize/Voffset', substr($contents, 6 + $index * 16 + 8, 8));
                    $ico = $entry['size'] > 0 && $entry['offset'] >= 6 + $count * 16
                        && $entry['offset'] + $entry['size'] <= strlen($contents);
                }
            }
            if (! $png && ! $ico) {
                $this->addError('faviconUpload', 'Gunakan PNG maksimal 1024 × 1024 piksel atau ICO yang valid.');
                return;
            }
            $favicon = 'data:'.($png ? 'image/png' : 'image/x-icon').';base64,'.base64_encode($contents);
        }
        $setting->update(['system_name' => $this->systemName, 'favicon_data_uri' => $favicon]);
        AutomationAccount::where('platform', Platform::Soundfresh)->update(['name' => $this->systemName]);
        \App\Support\SystemBranding::refresh();
        $this->reset('faviconUpload', 'removeFavicon');
        app(OperatorAudit::class)->record('system_identity_saved', 'Operator updated system name and favicon.');
        $this->dispatch('system-branding-updated', name: $this->systemName, favicon: $favicon ?: \App\Support\SystemBranding::defaultFavicon());
        $this->message = 'Nama sistem dan favicon berhasil disimpan.';
    }

    public function saveCmsLoginUrl(): void
    {
        Gate::authorize('sessions.manage');
        $this->cmsLoginUrl = trim($this->cmsLoginUrl);
        $this->validate(['cmsLoginUrl' => ['required', 'string', 'max:2048', 'url:https', function ($attribute, $value, $fail): void {
            if (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
                $fail('Link login CMS tidak boleh memuat username atau password.');
            }
        }]]);

        $setting = AutomationSetting::current();
        if ($setting->cmsLoginUrl() !== $this->cmsLoginUrl) {
            $setting->update(['cms_login_url' => $this->cmsLoginUrl]);
            app(SessionManager::class)->clear(Platform::Soundfresh);
        }
        app(OperatorAudit::class)->record('cms_login_url_saved', 'Operator updated CMS login URL.');
        $this->message = \App\Support\SystemBranding::text('Link login CMS berhasil disimpan. Login kembali ke Dimensi Suara jika alamat berubah.');
    }

    public function saveAutomaticRunSettings(): void
    {
        Gate::authorize('sessions.manage');
        $data = validator([
            'enabled' => $this->automaticRunEnabled,
            'interval' => $this->automaticRunIntervalMinutes,
            'start' => $this->automaticRunStartTime,
            'end' => $this->automaticRunEndTime,
        ], [
            'enabled' => ['boolean'],
            'interval' => ['required', 'integer', 'min:5', 'max:1440'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
        ])->validate();

        AutomationSetting::current()->update([
            'automatic_run_enabled' => (bool) $data['enabled'],
            'automatic_run_interval_minutes' => (int) $data['interval'],
            'automatic_run_start_time' => $data['start'],
            'automatic_run_end_time' => $data['end'],
        ]);

        app(OperatorAudit::class)->record('automatic_run_settings_saved', 'Operator updated automatic automation schedule.', context: [
            'enabled' => (bool) $data['enabled'],
            'interval_minutes' => (int) $data['interval'],
            'start_time' => $data['start'],
            'end_time' => $data['end'],
        ]);
        $this->message = 'Jadwal otomatis berhasil disimpan.';
    }

    public function clearCredentials(string $platform): void
    {
        Gate::authorize('sessions.manage');
        $enum = Platform::from($platform);
        AutomationAccount::where('platform', $enum)->update(['email_encrypted' => null, 'password_encrypted' => null, 'session_state_encrypted' => null, 'session_expires_at' => null, 'status' => 'disabled']);
        $this->emails[$platform] = '';
        $this->passwords[$platform] = '';
        app(OperatorAudit::class)->record('platform_credentials_cleared', 'Operator removed encrypted platform credentials.', context: ['platform' => $platform]);
        $this->message = $platform.': credential dan cached session dihapus.';
    }

    public function refresh(string $platform, SessionManager $sessions): void
    {
        Gate::authorize('sessions.manage');
        $enum = Platform::from($platform);
        try {
            $account = $sessions->ensure($enum);
            app(OperatorAudit::class)->record('platform_session_refreshed', 'Operator refreshed platform session.', context: ['platform' => $enum->value, 'status' => $account->status]);
            $this->message = $account->status === 'manual_auth_required'
                ? $enum->value.': login manual belum selesai. Tekan Login / refresh sesi lagi, lalu selesaikan CAPTCHA/OTP di jendela browser yang otomatis terbuka dalam waktu 3 menit.'
                : $enum->value.': '.$account->status;
        } catch (BrowserWorkerException $e) {
            logger()->warning('platform_session_refresh_failed', ['platform' => $enum->value, 'exception_class' => $e::class]);
            $this->message = match ($e->reason) {
                'invalid_credentials' => $enum->value.': email atau password ditolak oleh platform. Masukkan ulang password, simpan credential, lalu coba login kembali.',
                'worker_unavailable' => $enum->value.': layanan browser worker tidak dapat dihubungi.',
                default => $enum->value.': proses autentikasi gagal. Silakan coba kembali atau periksa status worker.',
            };
        } catch (CredentialsNotConfiguredException $e) {
            $this->message = $e->keyMismatch
                ? $enum->value.': credential lama tidak dapat dibaca. Masukkan ulang email dan password, simpan, lalu login kembali.'
                : $enum->value.': masukkan email dan password, simpan, lalu login kembali.';
        } catch (Throwable $e) {
            logger()->warning('platform_session_refresh_failed', ['platform' => $enum->value, 'exception_class' => $e::class]);
            $this->message = $enum->value.': proses autentikasi gagal.';
        }
    }

    public function clear(string $platform, SessionManager $sessions): void
    {
        Gate::authorize('sessions.manage');
        $enum = Platform::from($platform);
        $sessions->clear($enum);
        app(OperatorAudit::class)->record('platform_session_cleared', 'Operator cleared cached platform session.', context: ['platform' => $enum->value]);
        $this->message = $enum->value.': cached session dihapus.';
    }

    public function openDownloadFolder(): void
    {
        Gate::authorize('sessions.manage');
        $path = $this->downloadFolderPath();
        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->message = 'Folder download: '.$path;

            return;
        }

        try {
            (new Process(['explorer.exe', $path]))->setTimeout(10)->mustRun();
            $this->message = 'Folder download dibuka: '.$path;
        } catch (Throwable $error) {
            report($error);
            $this->message = 'Folder download gagal dibuka. Lokasi: '.$path;
        }
    }

    private function downloadFolderPath(): string
    {
        return Storage::disk('local')->path('automation/downloads');
    }

    public function clearDownloadData(CleanupTemporaryAssets $cleanup, StopAutomationRun $stop): void
    {
        Gate::authorize('sessions.manage');
        $active = ReleaseJob::query()->whereIn('status', [ReleaseJobStatus::Running, ReleaseJobStatus::Queued])->exists();
        if ($active) {
            $run = AutomationRun::query()->whereIn('status', ['running', 'queued', 'paused'])->first();
            if ($run) {
                $stop->handle($run);
            }
        }
        $count = $cleanup->purgeDownloadDirectory();
        app(OperatorAudit::class)->record('download_data_cleared', 'Operator deleted locally downloaded release assets.', context: ['asset_records' => $count]);
        $this->refreshDownloadStats();
        $this->message = ($active ? 'Proses aktif dihentikan. ' : '').'Data download berhasil dihapus dari aplikasi dan cache worker ('.$count.' catatan aset dibersihkan).';
    }

    private function refreshDownloadStats(): void
    {
        $files = Storage::disk('local')->allFiles('automation/downloads');
        $bytes = collect($files)->sum(fn (string $file): int => (int) Storage::disk('local')->size($file));
        $workerRoot = base_path('automation-worker/storage/extracted-assets');
        // Unit tests fake Laravel's local disk but must never count or delete
        // real browser-worker assets from the developer workspace.
        $workerFiles = ! app()->runningUnitTests() && is_dir($workerRoot)
            ? File::allFiles($workerRoot)
            : [];
        $bytes += collect($workerFiles)->sum(fn (\SplFileInfo $file): int => $file->getSize());
        $this->downloadFiles = count($files) + count($workerFiles);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $level = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;
        $this->downloadSize = number_format($bytes / (1024 ** $level), $level === 0 ? 0 : 2).' '.$units[$level];
    }

    public function render()
    {
        $accounts = AutomationAccount::all()->keyBy(fn ($account) => $account->platform->value);
        $credentialAvailability = [];
        foreach ($accounts as $platform => $account) {
            try {
                $credentialAvailability[$platform] = filled($account->email_encrypted) && filled($account->password_encrypted);
            } catch (DecryptException) {
                $credentialAvailability[$platform] = false;
            }
        }

        return view('livewire.automation.account-sessions', compact('accounts', 'credentialAvailability'));
    }
}
