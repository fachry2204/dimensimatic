<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_settings', function (Blueprint $table): void {
            $table->string('system_name', 80)->nullable();
            $table->text('favicon_data_uri')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('automation_settings', fn (Blueprint $table) => $table->dropColumn(['system_name', 'favicon_data_uri']));
    }
};
