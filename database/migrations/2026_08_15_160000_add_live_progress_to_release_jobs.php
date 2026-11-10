<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('progress_label')->nullable();
            $table->timestamp('progress_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->dropColumn(['progress_percent', 'progress_label', 'progress_updated_at']);
        });
    }
};
