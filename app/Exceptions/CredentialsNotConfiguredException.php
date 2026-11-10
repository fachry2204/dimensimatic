<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Platform;
use RuntimeException;

final class CredentialsNotConfiguredException extends RuntimeException
{
    public function __construct(public readonly Platform $platform, public readonly bool $keyMismatch = false)
    {
        $label = $platform === Platform::Soundfresh ? \App\Support\SystemBranding::cmsName() : 'SoundOn';
        parent::__construct($keyMismatch
            ? "Saved {$label} credentials cannot be decrypted with the current application key. Re-enter the email and password in Platform Settings."
            : "Credentials for {$label} are not configured.");
    }
}
