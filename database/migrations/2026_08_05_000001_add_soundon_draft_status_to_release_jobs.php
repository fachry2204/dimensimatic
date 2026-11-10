<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->string('soundon_draft_status')->nullable()->after('soundon_draft_url');
        });
    }

    public function down(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->dropColumn('soundon_draft_status');
        });
    }
};
