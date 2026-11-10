<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_settings', function (Blueprint $table): void {
            $table->text('cms_login_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('automation_settings', function (Blueprint $table): void {
            $table->dropColumn('cms_login_url');
        });
    }
};
