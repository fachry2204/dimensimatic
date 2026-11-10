<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AutomationSetting;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Schema;

final class SystemBranding
{
    private ?AutomationSetting $setting = null;

    private bool $loaded = false;

    private static function setting(): ?AutomationSetting
    {
        if (! Container::getInstance()->bound('db')) {
            return null;
        }
        $instance = app(self::class);
        if (! $instance->loaded) {
            $instance->setting = Schema::hasTable('automation_settings') ? AutomationSetting::current() : null;
            $instance->loaded = true;
        }

        return $instance->setting;
    }

    public static function refresh(): void
    {
        app(self::class)->loaded = false;
    }

    public static function name(): string
    {
        return self::setting()?->system_name ?: 'Dimensimatic';
    }

    public static function cmsName(): string
    {
        return in_array(strtolower(self::name()), ['dimensimatic', 'soundfresh', 'soundfresh.id', 'soundflow'], true) ? 'Dimensi Suara' : self::name();
    }

    public static function favicon(): ?string
    {
        return self::setting()?->favicon_data_uri;
    }

    public static function defaultFavicon(): string
    {
        $version = substr(hash_file('sha256', public_path('favicon.ico')), 0, 12);
        return asset('favicon.ico').'?v='.$version;
    }

    public static function text(?string $text): string
    {
        $value = preg_replace('/SOUNDFRESH_/', 'CMS_', $text ?? '') ?? '';
        return preg_replace_callback('/(?<!\\w)('.preg_quote(self::name(), '/').'|Soundfresh(?:\\.ID)?|Dimensi Suara|SoundFlow|Dimensimatic)(?!\\w)/iu',
            static fn (array $match): string => strcasecmp($match[0], self::name()) === 0 ? self::name() : (preg_match('/^(Soundfresh|Dimensi Suara)/i', $match[0]) ? self::cmsName() : self::name()), $value) ?? '';
    }
}
