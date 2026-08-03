<?php

namespace Tests\Feature;

use App\Livewire\CurriculumEditor;
use App\Models\AcademicYear;
use App\Models\CalendarWeek;
use App\Models\GgbItem;
use App\Models\GgbSyllabusLink;
use App\Models\Level;
use App\Models\RevisionBatch;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SourceDocument;
use App\Models\SyllabusItem;
use App\Models\User;
use App\Services\CurriculumRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class CurriculumRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Level $level;
    private GgbItem $ggb;
    private SyllabusItem $syllabus;
    private GgbSyllabusLink $link;
    private RppPlan $plan;
    private CalendarWeek $effective;
    private CalendarWeek $holiday;
    private RppWeekItem $placement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->level = Level::query()->create(['code' => 'UJI', 'name' => 'Kelas Uji', 'stage' => 'Uji', 'sort_order' => 1]);
        $ggbDocument = $this->document('ggb');
        $syllabusDocument = $this->document('silabus');
        $this->ggb = GgbItem::query()->create([
            'level_id' => $this->level->id, 'source_document_id' => $ggbDocument->id, 'source_key' => 'ggb:1',
            'stable_code' => 'UJI / FAQIH / 001', 'kind' => 'item', 'aspect' => 'Faqih', 'subaspect' => 'Ibadah',
            'title' => 'Materi asli', 'target_text' => null, 'raw_text' => 'Materi asli', 'source_page' => 2, 'sort_order' => 1,
            'source_payload' => ['aspect' => 'Faqih', 'title' => 'Materi asli', 'source_page' => 2],
        ]);
        $this->syllabus = SyllabusItem::query()->create([
            'level_id' => $this->level->id, 'source_document_id' => $syllabusDocument->id, 'source_key' => 'silabus:1',
            'stable_code' => 'UJI / SILABUS / 001', 'category' => 'Faqih', 'title' => 'Materi silabus',
            'description' => 'Penjabaran', 'allocation_text' => '1 pertemuan', 'recommended_sessions' => 1,
            'reference_text' => null, 'assessment_text' => null, 'needs_allocation' => false, 'is_duplicate' => false,
            'source_page' => 3, 'sort_order' => 1, 'group_number' => 1, 'source_payload' => ['title' => 'Materi silabus'],
        ]);
        $this->link = GgbSyllabusLink::query()->create([
            'ggb_item_id' => $this->ggb->id, 'syllabus_item_id' => $this->syllabus->id,
            'status' => 'sesuai', 'confidence' => 1, 'notes' => null,
        ]);
        $year = AcademicYear::query()->create(['label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2027-07-04', 'is_active' => true]);
        $this->effective = $this->week($year, 1, true);
        $this->holiday = $this->week($year, 2, false);
        $this->plan = RppPlan::query()->create(['academic_year_id' => $year->id, 'level_id' => $this->level->id, 'status' => 'validated', 'validated_at' => now()]);
        $this->placement = RppWeekItem::query()->create([
            'rpp_plan_id' => $this->plan->id, 'calendar_week_id' => $this->effective->id,
            'syllabus_item_id' => $this->syllabus->id, 'strand' => 'Faqih', 'content' => 'Materi silabus',
            'source' => 'auto', 'is_locked' => false, 'position' => 1,
        ]);
    }

    public function test_livewire_grid_saves_multiple_cells_and_keeps_provenance_locked(): void
    {
        Livewire::actingAs($this->admin)->test(CurriculumEditor::class, ['level' => $this->level])
            ->call('savePatches', [[
                'domain' => 'ggb', 'id' => $this->ggb->id, 'version' => 0,
                'changes' => ['title' => 'Materi terkoreksi', 'target_text' => 'Target baru', 'stable_code' => 'DILARANG'],
            ]], 'Koreksi hasil validasi kurikulum')
            ->assertSet('errorMessage', '');

        $this->ggb->refresh();
        $this->assertSame('Materi terkoreksi', $this->ggb->title);
        $this->assertSame('Target baru', $this->ggb->target_text);
        $this->assertSame('UJI / FAQIH / 001', $this->ggb->stable_code);
        $this->assertSame(['aspect' => 'Faqih', 'title' => 'Materi asli', 'source_page' => 2], $this->ggb->source_payload);
        $this->assertSame(1, $this->ggb->lock_version);
        $this->assertSame('draft', $this->plan->fresh()->status);
        $this->assertDatabaseHas('revision_batches', ['item_count' => 1, 'reason' => 'Koreksi hasil validasi kurikulum']);
    }

    public function test_batch_is_atomic_when_a_version_conflicts(): void
    {
        $service = app(CurriculumRevisionService::class);
        try {
            $service->applyBatch([
                ['domain' => 'ggb', 'id' => $this->ggb->id, 'version' => 0, 'changes' => ['title' => 'Tidak boleh tersimpan']],
                ['domain' => 'syllabus', 'id' => $this->syllabus->id, 'version' => 99, 'changes' => ['title' => 'Konflik']],
            ], 'Pengujian transaksi atomik', $this->admin);
            $this->fail('Konflik versi seharusnya membatalkan batch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Konflik', $exception->getMessage());
        }

        $this->assertSame('Materi asli', $this->ggb->fresh()->title);
        $this->assertDatabaseCount('revision_batches', 0);
    }

    public function test_restore_creates_a_new_revision_and_rejects_stale_restore(): void
    {
        $service = app(CurriculumRevisionService::class);
        $source = $service->applyBatch([[
            'domain' => 'syllabus', 'id' => $this->syllabus->id, 'version' => 0, 'changes' => ['title' => 'Judul revisi'],
        ]], 'Perbaikan judul pertama', $this->admin);
        $restored = $service->restoreBatch($source, 'Batalkan perbaikan judul', $this->admin);

        $this->assertSame('Materi silabus', $this->syllabus->fresh()->title);
        $this->assertSame('restore', $restored->action);
        $this->assertSame($source->uuid, $restored->source_batch_uuid);
        $this->assertDatabaseCount('revision_batches', 2);

        $this->expectException(RuntimeException::class);
        $service->restoreBatch($source, 'Coba pulihkan batch usang', $this->admin);
    }

    public function test_rpp_edit_only_accepts_effective_week_and_becomes_manual_locked(): void
    {
        $service = app(CurriculumRevisionService::class);
        try {
            $service->applyBatch([[
                'domain' => 'rpp', 'id' => $this->placement->id, 'version' => 0,
                'changes' => ['calendar_week_id' => $this->holiday->id],
            ]], 'Pindah ke minggu libur', $this->admin);
            $this->fail('Minggu non-efektif seharusnya ditolak.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $service->applyBatch([[
            'domain' => 'rpp', 'id' => $this->placement->id, 'version' => 0,
            'changes' => ['content' => 'Isi manual yang dikoreksi'],
        ]], 'Koreksi isi RPP manual', $this->admin);

        $this->placement->refresh();
        $this->assertSame($this->effective->id, $this->placement->calendar_week_id);
        $this->assertSame('manual', $this->placement->source);
        $this->assertTrue($this->placement->is_locked);
    }

    public function test_relation_archive_and_restore_are_both_versioned(): void
    {
        $service = app(CurriculumRevisionService::class);
        $archived = $service->deleteLink($this->link, 'Relasi tidak lagi sesuai', $this->admin);

        $this->assertSoftDeleted('ggb_syllabus_links', ['id' => $this->link->id]);
        $this->assertSame(1, GgbSyllabusLink::withTrashed()->findOrFail($this->link->id)->lock_version);

        $service->restoreBatch($archived, 'Pulihkan relasi setelah verifikasi', $this->admin);
        $this->assertDatabaseHas('ggb_syllabus_links', ['id' => $this->link->id, 'deleted_at' => null, 'lock_version' => 2]);
        $this->assertDatabaseCount('revision_batches', 2);
    }

    private function document(string $type): SourceDocument
    {
        return SourceDocument::query()->create([
            'level_id' => $this->level->id, 'source_key' => $type.':uji', 'type' => $type,
            'title' => ucfirst($type).' Uji', 'path' => $type.'.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 3,
        ]);
    }

    private function week(AcademicYear $year, int $number, bool $effective): CalendarWeek
    {
        return CalendarWeek::query()->create([
            'academic_year_id' => $year->id, 'week_number' => $number, 'starts_on' => '2026-07-'.($number === 1 ? '06' : '13'),
            'month_label' => 'Juli', 'type' => $effective ? 'effective' : 'holiday', 'is_effective' => $effective,
        ]);
    }
}
