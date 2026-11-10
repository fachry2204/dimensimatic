<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseAsset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_url_encrypted' => 'encrypted', 'validation_json' => 'array', 'uploaded_to_soundon_at' => 'datetime'];
    }
}
