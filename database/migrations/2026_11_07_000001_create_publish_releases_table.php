<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_releases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('draft_id')->unique();
            $table->text('draft_url');
            $table->text('title');
            $table->text('artist');
            $table->text('cms_release_url')->nullable();
            $table->string('cms_release_id')->nullable();
            $table->string('status')->default('draft')->index();
            $table->text('error')->nullable();
            $table->timestamp('soundon_published_at')->nullable();
            $table->timestamp('cms_uploaded_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_releases');
    }
};
