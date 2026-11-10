<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\AutomationAccount;
use App\Models\ReleaseAsset;
use App\Services\Automation\PlaywrightClient;
use Illuminate\Console\Command;

final class DiscoverSoundOnAlbumForm extends Command
{
    protected $signature = 'dimensimatic:discover-album-form';

    protected $description = 'Inspect the current SoundOn EP/Album draft form without saving a release.';

    public function handle(PlaywrightClient $worker): int
    {
        $account = AutomationAccount::query()->where('platform', Platform::SoundOn)->firstOrFail();
        $cover = ReleaseAsset::query()->where('type', 'cover')->whereNotNull('temporary_path')->latest('updated_at')->firstOrFail();
        $audios = ReleaseAsset::query()->where('type', 'audio')->whereNotNull('temporary_path')->latest('updated_at')->limit(2)->get();
        if ($audios->count() < 2) {
            $this->error('Two distinct downloaded audio assets are required.');

            return self::FAILURE;
        }
        $coverPath = \Storage::disk('local')->path((string) $cover->temporary_path);
        $audioPath = \Storage::disk('local')->path((string) $audios[0]->temporary_path);
        $audioPath2 = \Storage::disk('local')->path((string) $audios[1]->temporary_path);
        $query = http_build_query(['cover' => $coverPath, 'audio' => $audioPath, 'audio2' => $audioPath2]);

        $result = $worker->discoverSelectors(
            Platform::SoundOn->value,
            (array) $account->session_state_encrypted,
            rtrim((string) config('automation.soundon.base_url'), '/').'/library?'.$query.'#discover-album-track',
        );

        $path = storage_path('logs/album-form-discovery.json');
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info($path);

        return self::SUCCESS;
    }
}
