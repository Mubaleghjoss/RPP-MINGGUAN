<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CalendarWeek;
use App\Models\GgbItem;
use App\Models\Level;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppMatrixColumn;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SourceDocument;
use App\Models\SyllabusItem;
use App\Models\User;
use App\Services\GgbOutlineService;
use App\Services\RppMaterialCatalogService;
use App\Services\RppMaterialPlacementService;
use App\Services\RppMatrixFillService;
use App\Services\RppMatrixPresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RppOutlineAndMatrixFillTest extends TestCase
{
    use RefreshDatabase;

    public function test_outline_classifies_headings_artifacts_and_separate_descendant_materials(): void
    {
        [$level, $document] = $this->levelAndDocument();
        $rows = collect([
            ['1. Pribadi', '1. Pribadi'],
            ['Mengenal sifat jujur', 'a. Mengenal sifat jujur'],
            ['2. Keluarga', '2. Keluarga'],
            ['Berbuat baik kepada orangtua', 'a. Berbuat baik kepada orangtua'],
            ['PAUD', 'PAUD'],
        ])->map(fn ($values, $index) => $this->ggb($level, $document, $index + 1, $values[0], $values[1]));

        $counts = app(GgbOutlineService::class)->classifyLevel($level, false);

        $this->assertSame(['heading', 'material', 'heading', 'material', 'artifact'], $rows->map(fn ($row) => $row->fresh()->rpp_role)->all());
        $this->assertSame(2, $counts['material']);
        $this->assertSame(2, $counts['heading']);
        $this->assertSame(1, $counts['artifact']);
        $this->assertSame($rows->pluck('stable_code')->all(), $rows->map(fn ($row) => $row->fresh()->stable_code)->all());
    }

    public function test_matrix_fill_reinforces_sources_rotates_activities_and_is_idempotent(): void
    {
        [$level] = $this->levelAndDocument();
        $year = AcademicYear::query()->create(['label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2027-07-04', 'is_active' => true]);
        $weekOne = $this->week($year, 1, '2026-07-06', true);
        $weekTwo = $this->week($year, 2, '2026-07-13', true);
        $holiday = $this->week($year, 3, '2026-07-20', false);
        $plan = RppPlan::query()->create(['academic_year_id' => $year->id, 'level_id' => $level->id, 'semester' => 1, 'status' => 'draft']);
        $learning = RppMatrixColumn::query()->create(['level_id' => $level->id, 'stable_key' => 'learning', 'aspect_label' => 'I. Alim', 'subaspect_label' => 'A. Alim', 'label' => 'Materi', 'sort_order' => 1, 'width' => 24, 'is_active' => true]);
        $activityColumn = RppMatrixColumn::query()->create(['level_id' => $level->id, 'stable_key' => 'activity', 'aspect_label' => 'IV. Pengembangan', 'subaspect_label' => 'B. Ekstrakurikuler', 'label' => 'Ekstrakurikuler', 'sort_order' => 2, 'width' => 24, 'is_active' => true]);
        $manual = RppWeekItem::query()->create([
            'rpp_plan_id' => $plan->id, 'calendar_week_id' => $weekOne->id, 'syllabus_item_id' => null,
            'source_fingerprint' => 'manual:anchor', 'occurrence_no' => 1, 'rpp_matrix_column_id' => $learning->id,
            'strand' => $learning->label, 'content' => 'Materi pilihan Admin', 'source' => 'manual',
            'is_locked' => true, 'position' => 1, 'lock_version' => 1,
        ]);
        $activity = RppMaterialCatalogItem::query()->create([
            'level_id' => $level->id, 'rpp_matrix_column_id' => $activityColumn->id,
            'source_kind' => 'activity', 'is_schedulable' => true, 'display_code' => 'Ekstrakurikuler 01',
            'title' => 'Pramuka', 'semester_scope' => 'both', 'source_semester_scope' => 'general',
            'semester_confirmed' => true, 'auto_include' => false, 'is_active' => true,
            'rotation_enabled' => true, 'origin_key' => 'activity:test:pramuka', 'mapping_status' => 'mapped',
            'sort_order' => 1,
        ]);

        $first = app(RppMatrixFillService::class)->fill($plan);
        $this->assertSame(4, $first['total']);
        $this->assertSame(0, $first['missing']);
        $this->assertSame(1, $plan->items()->where('source', 'reinforcement_auto')->count());
        $this->assertSame(2, $plan->items()->where('source', 'activity_auto')->count());
        $this->assertSame(0, $plan->items()->where('calendar_week_id', $holiday->id)->count());
        $this->assertDatabaseHas('rpp_week_items', ['id' => $manual->id, 'content' => 'Materi pilihan Admin', 'is_locked' => true]);
        $this->assertSame(2, $activity->placements()->count());

        app(RppMatrixFillService::class)->fill($plan->fresh());
        $this->assertSame(4, $plan->items()->count());
        $this->assertSame(1, $plan->items()->where('source', 'reinforcement_auto')->count());
        $this->assertSame(2, $plan->items()->where('source', 'activity_auto')->count());
    }

    public function test_admin_can_create_reusable_activity_and_add_one_off_manual_entry(): void
    {
        [$level] = $this->levelAndDocument();
        $year = AcademicYear::query()->create(['label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2027-07-04', 'is_active' => true]);
        $week = $this->week($year, 1, '2026-07-06', true);
        $plan = RppPlan::query()->create(['academic_year_id' => $year->id, 'level_id' => $level->id, 'semester' => 1, 'status' => 'draft']);
        $column = RppMatrixColumn::query()->create(['level_id' => $level->id, 'stable_key' => 'activity', 'aspect_label' => 'IV. Pengembangan', 'subaspect_label' => 'B. Ekstrakurikuler', 'label' => 'Ekstrakurikuler', 'sort_order' => 1, 'width' => 24, 'is_active' => true]);
        $user = User::factory()->create();

        $activity = app(RppMaterialCatalogService::class)->createActivity(
            $level, $column->id, 'Senam Barokah', 'both', true, 'Tambahkan kegiatan PAUD', $user->id
        );
        $manual = app(RppMaterialPlacementService::class)->addOneOffToCell(
            $plan, $week->id, $column->id, 'Kegiatan kebersihan kelas', 'Agenda khusus pekan ini', $user->id
        );

        $this->assertSame('activity', $activity->source_kind);
        $this->assertTrue($activity->rotation_enabled);
        $this->assertSame('manual', $manual->source);
        $this->assertTrue($manual->is_locked);
        $this->assertDatabaseHas('revision_items', ['revisable_type' => 'material_catalog', 'revisable_id' => $activity->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'rpp.one_off_activity_added']);
    }

    public function test_empty_semester_column_uses_sister_semester_source_as_reinforcement_without_syllabus_coverage(): void
    {
        [$level] = $this->levelAndDocument();
        $year = AcademicYear::query()->create(['label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2027-07-04', 'is_active' => true]);
        $semesterOneWeek = $this->week($year, 1, '2026-07-06', true);
        $semesterTwoWeek = CalendarWeek::query()->create([
            'academic_year_id' => $year->id, 'week_number' => 27, 'semester' => 2,
            'starts_on' => '2027-01-04', 'month_label' => 'Januari', 'type' => 'effective', 'is_effective' => true,
        ]);
        $one = RppPlan::query()->create(['academic_year_id' => $year->id, 'level_id' => $level->id, 'semester' => 1, 'status' => 'draft']);
        $two = RppPlan::query()->create(['academic_year_id' => $year->id, 'level_id' => $level->id, 'semester' => 2, 'status' => 'draft']);
        $column = RppMatrixColumn::query()->create(['level_id' => $level->id, 'stable_key' => 'character', 'aspect_label' => 'II. Akhlaq', 'subaspect_label' => 'A. Akhlaq', 'label' => 'Akhlaq', 'sort_order' => 1, 'width' => 24, 'is_active' => true]);
        RppWeekItem::query()->create([
            'rpp_plan_id' => $two->id, 'calendar_week_id' => $semesterTwoWeek->id, 'syllabus_item_id' => null,
            'source_fingerprint' => 'semester-two:source', 'occurrence_no' => 1,
            'rpp_matrix_column_id' => $column->id, 'strand' => $column->label,
            'content' => 'Sopan santun', 'source' => 'ggb_auto', 'is_locked' => false, 'position' => 1,
        ]);

        $stats = app(RppMatrixFillService::class)->fill($one);
        $reinforcement = $one->items()->firstOrFail();

        $this->assertSame(0, $stats['missing']);
        $this->assertSame($semesterOneWeek->id, $reinforcement->calendar_week_id);
        $this->assertSame('reinforcement_auto', $reinforcement->source);
        $this->assertSame('penguatan', $reinforcement->progress_kind);
        $this->assertNull($reinforcement->syllabus_item_id);
    }

    public function test_pdf_weekday_header_is_marked_as_source_artifact_and_never_creates_an_rpp_column(): void
    {
        [$level, $document] = $this->levelAndDocument();
        $artifact = $this->syllabus($level, $document, 1, [
            'category' => 'SENIN',
            'title' => 'RABU',
            'allocation_text' => 'JUM’AT',
            'reference_text' => 'SABTU',
            'assessment_text' => 'MINGGU',
        ]);
        $material = $this->syllabus($level, $document, 2, [
            'category' => 'Akhlaq',
            'title' => 'Mengenal sifat jujur',
            'allocation_text' => 'Setiap minggu',
        ]);

        app(RppMatrixPresetService::class)->syncLevel($level);

        $this->assertTrue($artifact->fresh()->is_source_artifact);
        $this->assertFalse($material->fresh()->is_source_artifact);
        $this->assertNull($artifact->fresh()->matrixMapping);
        $this->assertNotNull($material->fresh()->matrixMapping);
        $this->assertDatabaseMissing('rpp_matrix_columns', [
            'level_id' => $level->id,
            'stable_key' => 'tambahan-senin',
        ]);
    }

    private function levelAndDocument(): array
    {
        $level = Level::query()->create(['code' => 'PAUD', 'name' => 'PAUD', 'stage' => 'PAUD', 'sort_order' => 1]);
        $document = SourceDocument::query()->create([
            'level_id' => $level->id, 'source_key' => 'ggb:paud:test', 'type' => 'ggb',
            'title' => 'GGB PAUD', 'path' => 'ggb.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 2,
        ]);

        return [$level, $document];
    }

    private function ggb(Level $level, SourceDocument $document, int $order, string $title, string $raw): GgbItem
    {
        return GgbItem::query()->create([
            'level_id' => $level->id, 'source_document_id' => $document->id,
            'source_key' => 'ggb:paud:'.$order, 'stable_code' => 'PAUD / AKHLAQ / '.str_pad((string) $order, 3, '0', STR_PAD_LEFT),
            'kind' => 'topic', 'aspect' => 'Akhlaqul Karimah', 'subaspect' => 'Akhlaq',
            'title' => $title, 'raw_text' => $raw, 'source_page' => 2, 'sort_order' => $order,
        ]);
    }

    private function syllabus(Level $level, SourceDocument $document, int $order, array $values): SyllabusItem
    {
        return SyllabusItem::query()->create([
            'level_id' => $level->id,
            'source_document_id' => $document->id,
            'source_key' => 'syllabus:paud:'.$order,
            'stable_code' => 'PAUD / SILABUS / '.str_pad((string) $order, 3, '0', STR_PAD_LEFT),
            'category' => $values['category'],
            'title' => $values['title'],
            'description' => $values['title'],
            'allocation_text' => $values['allocation_text'] ?? null,
            'recommended_sessions' => 1,
            'reference_text' => $values['reference_text'] ?? null,
            'assessment_text' => $values['assessment_text'] ?? null,
            'source_page' => 1,
            'sort_order' => $order,
            'group_number' => 1,
            'semester_scope' => 'both',
            'source_semester' => 'both',
            'needs_allocation' => false,
            'is_duplicate' => false,
        ]);
    }

    private function week(AcademicYear $year, int $number, string $date, bool $effective): CalendarWeek
    {
        return CalendarWeek::query()->create([
            'academic_year_id' => $year->id, 'week_number' => $number, 'semester' => 1,
            'starts_on' => $date, 'month_label' => 'Juli', 'type' => $effective ? 'effective' : 'holiday',
            'label' => $effective ? null : 'Libur', 'is_effective' => $effective,
        ]);
    }
}
