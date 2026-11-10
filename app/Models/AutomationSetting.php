<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AutomationSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'automatic_run_enabled' => 'boolean',
            'automatic_run_interval_minutes' => 'integer',
            'automatic_run_last_started_at' => 'datetime',
        ];
    }

    public function cmsLoginUrl(): string
    {
        return $this->cms_login_url ?: (string) config('automation.soundfresh.login_url', 'https://dimensisuara.id/login');
    }

    public function cmsBaseUrl(): string
    {
        $parts = parse_url($this->cmsLoginUrl());

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'automatic_run_enabled' => false,
            'automatic_run_interval_minutes' => 30,
            'automatic_run_start_time' => '08:00',
            'automatic_run_end_time' => '22:00',
        ]);
    }
}
