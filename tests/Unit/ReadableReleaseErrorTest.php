<?php
namespace Tests\Unit;
use App\Services\Automation\ReadableReleaseError;
use PHPUnit\Framework\TestCase;

class ReadableReleaseErrorTest extends TestCase
{
    public function test_old_review_button_failure_reports_saved_draft_and_review_only_retry(): void
    {
        $result = ReadableReleaseError::explain('AUTOMATION_FAILED', 'SOUNDFRESH_UI_CHANGED:review_release_button:url=https://dimensisuara.id/admin/releases/3833');
        $this->assertSame('Draft SoundOn tersimpan; review CMS belum selesai', $result['title']);
        $this->assertStringContainsString('aggregator SoundOn', $result['action']);
        $this->assertStringContainsString('tanpa upload ulang', $result['action']);
    }

    public function test_soundon_name_validation_is_not_reported_as_missing_profile(): void
    {
        $result = ReadableReleaseError::explain('SOUNDON_CONTRIBUTOR_NOT_PERSISTED',
            'SOUNDON_CONTRIBUTOR_NOT_PERSISTED:Primary artists:M.Vaganzaee:stage=submit_verification:errors=Artist name cannot contain URLs or website links.');
        $this->assertSame('Nama artis ditolak validasi SoundOn', $result['title']);
        $this->assertStringContainsString('M.Vaganzaee', $result['reason']);
        $this->assertStringContainsString('tanda titik', $result['action']);
        $this->assertStringContainsString('status Dimensi Suara belum diubah', $result['action']);
    }

    public function test_monetization_failure_has_specific_explanation_even_for_old_ui_changed_code(): void
    {
        $result = ReadableReleaseError::explain('UI_CHANGED', 'Detail teknis: SOUNDON_MONETIZATION_NOT_ENABLED:YouTube Content ID');
        $this->assertSame('Pengaturan monetisasi SoundOn belum tersimpan', $result['title']);
        $this->assertStringContainsString('YouTube Content ID', $result['reason']);
        $this->assertStringContainsString('kelayakan genre', $result['action']);
    }
    public function test_credit_persistence_error_identifies_the_field_and_name_without_claiming_the_profile_is_missing(): void
    {
        $result = ReadableReleaseError::explain(
            'SOUNDON_CONTRIBUTOR_NOT_PERSISTED',
            'Detail teknis: SOUNDON_CONTRIBUTOR_NOT_PERSISTED:Primary artists:Zenal Farell:stage=verify_after_submit:role=none',
        );
        $this->assertSame('Kredit SoundOn belum tersimpan', $result['title']);
        $this->assertStringContainsString('SoundOn', $result['reason']);
        $this->assertStringContainsString('Zenal Farell', $result['reason']);
        $this->assertStringContainsString('Artis utama', $result['reason']);
        $this->assertStringNotContainsString('stage=', $result['reason']);
        $this->assertStringContainsString('Dimensi Suara', $result['action']);
    }
    public function test_old_saved_dialog_errors_are_explained_without_claiming_upload_failed(): void
    {
        $result = ReadableReleaseError::explain('UI_CHANGED', 'Pesan lama. Detail teknis: SOUNDON_UI_CHANGED:blocking_dialog:Primary artists');
        $this->assertStringContainsString('artis utama', $result['reason']);
        $this->assertStringContainsString('tidak membuktikan', $result['action']);
        $this->assertStringNotContainsString('UI_CHANGED', $result['title']);
    }

    public function test_missing_field_error_names_the_form_stage_and_field(): void
    {
        $result = ReadableReleaseError::explain(
            'UI_CHANGED',
            'Field tidak ditemukan. Detail teknis: SOUNDON_UI_CHANGED:field:Previously released?',
        );

        $this->assertSame('Pengisian metadata SoundOn terhenti', $result['title']);
        $this->assertStringContainsString('Previously released?', $result['reason']);
        $this->assertStringContainsString('belum mulai diunggah', $result['action']);
    }

    public function test_soundon_form_validation_explains_form_filling_failure(): void
    {
        $result = ReadableReleaseError::explain(
            'SOUNDON_FORM_VALIDATION',
            'SoundOn menolak metadata pada form. Detail teknis: Missing required field: Genre',
        );

        $this->assertSame('Pengisian form SoundOn gagal', $result['title']);
        $this->assertStringContainsString('bukan file rusak', $result['reason']);
        $this->assertStringContainsString('Genre', $result['action']);
        $this->assertStringContainsString('Perbaiki metadata', $result['action']);
    }
}
