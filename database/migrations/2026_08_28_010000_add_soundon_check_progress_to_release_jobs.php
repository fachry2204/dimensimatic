<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->string('soundon_check_status')->nullable()->after('soundon_status_checked_at');
            $table->unsignedTinyInteger('soundon_check_progress')->default(0)->after('soundon_check_status');
            $table->text('soundon_check_error')->nullable()->after('soundon_check_progress');
            $table->timestamp('soundon_check_started_at')->nullable()->after('soundon_check_error');
            $table->timestamp('soundon_check_finished_at')->nullable()->after('soundon_check_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('release_jobs', fn (Blueprint $table) => $table->dropColumn([
            'soundon_check_status', 'soundon_check_progress', 'soundon_check_error',
            'soundon_check_started_at', 'soundon_check_finished_at',
        ]));
    }
};
