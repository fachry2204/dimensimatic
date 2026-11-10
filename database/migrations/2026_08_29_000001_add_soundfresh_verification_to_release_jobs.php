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
            $table->string('soundfresh_verify_status')->nullable()->after('soundon_check_finished_at');
            $table->text('soundfresh_verify_error')->nullable()->after('soundfresh_verify_status');
            $table->timestamp('soundfresh_verified_at')->nullable()->after('soundfresh_verify_error');
        });
    }

    public function down(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->dropColumn(['soundfresh_verify_status', 'soundfresh_verify_error', 'soundfresh_verified_at']);
        });
    }
};
