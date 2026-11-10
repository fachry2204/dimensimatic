<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_accounts', fn (Blueprint $t) => $this->account($t));
        Schema::create('automation_runs', fn (Blueprint $t) => $this->run($t));
        Schema::create('release_jobs', fn (Blueprint $t) => $this->job($t));
        Schema::create('release_assets', fn (Blueprint $t) => $this->asset($t));
        Schema::create('metadata_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('mapping_type');
            $t->string('source_value');
            $t->string('target_value');
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['mapping_type', 'source_value']);
        });
        Schema::create('automation_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('automation_run_id')->nullable()->constrained('automation_runs')->cascadeOnDelete();
            $t->foreignUuid('release_job_id')->nullable()->constrained('release_jobs')->cascadeOnDelete();
            $t->string('level')->default('info');
            $t->string('event');
            $t->text('message');
            $t->json('context_json')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('automation_artifacts', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('automation_run_id')->nullable()->constrained('automation_runs')->cascadeOnDelete();
            $t->foreignUuid('release_job_id')->nullable()->constrained('release_jobs')->cascadeOnDelete();
            $t->string('type');
            $t->string('path');
            $t->timestamp('expires_at');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    private function account(Blueprint $t): void
    {
        $t->id();
        $t->string('platform')->unique();
        $t->string('name');
        $t->text('email_encrypted')->nullable();
        $t->text('password_encrypted')->nullable();
        $t->longText('session_state_encrypted')->nullable();
        $t->timestamp('session_expires_at')->nullable();
        $t->timestamp('last_authenticated_at')->nullable();
        $t->string('status')->default('expired');
        $t->timestamps();
    }

    private function run(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
        $t->string('status')->default('queued');
        $t->timestamp('started_at')->nullable();
        $t->timestamp('finished_at')->nullable();
        foreach (['pending_found', 'processed', 'completed', 'failed', 'skipped'] as $c) {
            $t->unsignedInteger($c)->default(0);
        }$t->string('current_release_id')->nullable();
        $t->timestamp('stop_requested_at')->nullable();
        $t->json('summary_json')->nullable();
        $t->timestamps();
    }

    private function job(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('automation_run_id')->constrained('automation_runs')->cascadeOnDelete();
        $t->string('soundfresh_release_id');
        $t->text('soundfresh_release_url');
        $t->string('soundon_draft_id')->nullable();
        $t->text('soundon_draft_url')->nullable();
        $t->string('idempotency_key')->unique();
        $t->string('release_title')->nullable();
        $t->string('artist_name')->nullable();
        $t->string('release_type')->nullable();
        $t->unsignedInteger('track_count')->default(0);
        $t->string('status')->default('queued');
        $t->string('checkpoint')->default('discovered');
        $t->unsignedInteger('attempts')->default(0);
        $t->string('error_code')->nullable();
        $t->text('error_message')->nullable();
        $t->json('metadata_snapshot_json')->nullable();
        $t->timestamp('started_at')->nullable();
        $t->timestamp('finished_at')->nullable();
        $t->timestamps();
    }

    private function asset(Blueprint $t): void
    {
        $t->id();
        $t->foreignUuid('release_job_id')->constrained('release_jobs')->cascadeOnDelete();
        $t->string('type');
        $t->unsignedInteger('track_position')->nullable();
        $t->text('source_url_encrypted')->nullable();
        $t->string('temporary_path')->nullable();
        $t->string('original_filename');
        $t->string('mime_type')->nullable();
        $t->unsignedBigInteger('file_size')->nullable();
        $t->string('checksum_sha256', 64)->nullable();
        $t->json('validation_json')->nullable();
        $t->timestamp('uploaded_to_soundon_at')->nullable();
        $t->timestamp('deleted_at_source_cache')->nullable();
        $t->timestamps();
    }

    public function down(): void
    {
        foreach (['automation_artifacts', 'automation_events', 'metadata_mappings', 'release_assets', 'release_jobs', 'automation_runs', 'automation_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
