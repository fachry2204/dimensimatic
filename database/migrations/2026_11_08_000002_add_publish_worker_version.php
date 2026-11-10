<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('publish_releases', fn (Blueprint $table) => $table->string('worker_version', 40)->nullable()); }
    public function down(): void { Schema::table('publish_releases', fn (Blueprint $table) => $table->dropColumn('worker_version')); }
};
