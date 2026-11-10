<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->text('soundon_rejection_reason')->nullable()->after('soundon_release_status');
            $table->timestamp('soundfresh_rejected_at')->nullable()->after('soundfresh_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('release_jobs', fn (Blueprint $table) => $table->dropColumn([
            'soundon_rejection_reason', 'soundfresh_rejected_at',
        ]));
    }
};
