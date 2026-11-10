<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->string('soundon_release_status')->nullable()->after('soundon_draft_status');
            $table->string('soundon_upc', 13)->nullable()->after('soundon_release_status');
            $table->json('soundon_isrcs_json')->nullable()->after('soundon_upc');
            $table->timestamp('soundon_status_checked_at')->nullable()->after('soundon_isrcs_json');
        });
    }

    public function down(): void
    {
        Schema::table('release_jobs', fn (Blueprint $table) => $table->dropColumn([
            'soundon_release_status', 'soundon_upc', 'soundon_isrcs_json', 'soundon_status_checked_at',
        ]));
    }
};
