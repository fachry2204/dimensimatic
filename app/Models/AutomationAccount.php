<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Model;

final class AutomationAccount extends Model
{
    protected $guarded = [];

    protected $hidden = ['email_encrypted', 'password_encrypted', 'session_state_encrypted'];

    protected function casts(): array
    {
        return ['platform' => Platform::class, 'email_encrypted' => 'encrypted', 'password_encrypted' => 'encrypted', 'session_state_encrypted' => 'encrypted:array', 'session_expires_at' => 'datetime', 'last_authenticated_at' => 'datetime'];
    }
}
