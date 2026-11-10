<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_jobs', function (Blueprint $table): void {
            $table->string('soundfresh_workflow_status')->nullable()->after('soundfresh_release_url')->index();
        });
    }

    public function down(): void
    {
        Schema::table('release_jobs', fn (Blueprint $table) => $table->dropColumn('soundfresh_workflow_status'));
    }
};
