<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metadata_field_syncs', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 20);
            $table->string('source_field');
            $table->string('source_label');
            $table->string('target_field');
            $table->string('target_label');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['scope', 'source_field']);
        });

        $release = [
            ['title', 'Release title', 'title', 'Release title'],
            ['primary_artist', 'Primary artists', 'primary_artist', 'Primary artists'],
            ['release_type', 'Release type', 'release_type', 'Upload type'],
            ['version', 'Version', 'version', 'Version'],
            ['genre', 'Genre', 'genre', 'Genre'],
            ['subgenre', 'Subgenre', 'subgenre', 'Subgenre'],
            ['title_language', 'Title language', 'title_language', 'Title language'],
            ['language', 'Language', 'language', 'Language'],
            ['release_date', 'Planned release date', 'release_date', 'Release date'],
            ['pre_release_date', 'TikTok & YouTube Music Pre-Release Date', 'pre_release_date', 'TikTok & YouTube pre-release date'],
            ['upc', 'UPC', 'upc', 'UPC'],
            ['record_label', 'Record label', 'record_label', 'Record label'],
            ['cover', 'Album cover', 'cover', 'Cover art'],
        ];
        $track = [
            ['position', 'Track number', 'position', 'Track position'],
            ['title', 'Title', 'title', 'Title'],
            ['primary_artist', 'Primary artists', 'primary_artist', 'Primary artists'],
            ['featured_artists', 'Featured artists', 'featured_artists', 'Featured artists'],
            ['version', 'Version', 'version', 'Version'],
            ['genre', 'Genre', 'genre', 'Genre'],
            ['subgenre', 'Subgenre', 'subgenre', 'Subgenre'],
            ['title_language', 'Title language', 'title_language', 'Title language'],
            ['language', 'Lyrics language', 'language', 'Lyrics language'],
            ['isrc', 'ISRC', 'isrc', 'ISRC'],
            ['instrumental', 'Instrumental', 'instrumental', 'Instrumental'],
            ['explicit', 'Explicit content', 'explicit', 'Explicit content'],
            ['songwriters', 'Songwriter', 'songwriters', 'Songwriters'],
            ['contributors', 'Contributors', 'contributors', 'Contributors'],
            ['production_contributors', 'Production / Engineering', 'production_contributors', 'Production contributors'],
            ['audio', 'Full Track / Track', 'audio', 'Upload the full song file'],
            ['tiktok_audio', 'Trimmed Track / Trimmed', 'tiktok_audio', 'Official sounds for TikTok'],
        ];
        $now = now();
        foreach (['release' => $release, 'track' => $track] as $scope => $rows) {
            foreach ($rows as $index => [$source, $sourceLabel, $target, $targetLabel]) {
                DB::table('metadata_field_syncs')->insert([
                    'scope' => $scope, 'source_field' => $source, 'source_label' => $sourceLabel,
                    'target_field' => $target, 'target_label' => $targetLabel,
                    'is_active' => true, 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_field_syncs');
    }
};
