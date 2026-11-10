<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Illuminate\Console\Command;

final class PendingListCommand extends Command
{
    protected $signature = 'soundon:pending:list {--limit=10} {--json}';

    protected $description = 'List CMS pending releases without changing either platform';

    public function handle(SessionManager $sessions, PlaywrightClient $worker): int
    {
        $account = $sessions->ensure(Platform::Soundfresh);
        if ($account->status !== 'active') {
            $this->error('CMS session requires manual authentication.');

            return self::FAILURE;
        }
        $items = $worker->pending(min(max((int) $this->option('limit'), 1), 100), $account->session_state_encrypted);
        if ($this->option('json')) {
            $this->line(json_encode($items, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Release ID', 'Title', 'Detail URL'], array_map(fn (array $item) => [$item['release_id'], $item['title'] ?? '', $item['detail_url']], $items));
        }

        return self::SUCCESS;
    }
}
