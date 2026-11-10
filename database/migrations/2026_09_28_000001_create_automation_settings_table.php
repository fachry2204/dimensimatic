<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('automatic_run_enabled')->default(false);
            $table->unsignedInteger('automatic_run_interval_minutes')->default(30);
            $table->time('automatic_run_start_time')->default('08:00');
            $table->time('automatic_run_end_time')->default('22:00');
            $table->timestamp('automatic_run_last_started_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_settings');
    }
};
