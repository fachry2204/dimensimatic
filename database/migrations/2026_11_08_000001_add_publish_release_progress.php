<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publish_releases', function (Blueprint $table): void {
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('progress_message')->nullable();
            $table->uuid('progress_attempt')->nullable()->index();
        });
        DB::table('publish_releases')->where('status', 'success')->update(['progress_percent' => 100, 'progress_message' => 'Selesai: SoundOn dan CMS berhasil']);
        DB::table('publish_releases')->where('status', 'updating_cms')->update(['progress_percent' => 90, 'progress_message' => 'Menjalankan Upload Release di CMS']);
        DB::table('publish_releases')->where('status', 'failed')->whereNotNull('soundon_published_at')->update(['progress_percent' => 85, 'progress_message' => 'SoundOn berhasil, pembaruan CMS perlu diulang']);
    }

    public function down(): void
    {
        Schema::table('publish_releases', fn (Blueprint $table) => $table->dropColumn(['progress_percent', 'progress_message', 'progress_attempt']));
    }
};
