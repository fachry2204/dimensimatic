<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->json('asset_progress_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->dropColumn('asset_progress_json');
        });
    }
};
