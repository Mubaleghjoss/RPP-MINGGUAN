<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CalendarWeek;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\SourceDocument;
use App\Models\SyllabusItem;
use App\Models\User;
use App\Services\CurriculumRevisionService;
use App\Services\RppPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RppMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_matrix_planner_distributes_weekly_material_and_preserves_locked_anchor(): void
    {
        [$plan, $weeklyA, $weeklyB, $tentative, $holiday] = $this->fixture();
        $planner = app(RppPlanner::class);
        $planner->generate($plan);

        $plan->refresh();
        $this->assertSame(2, $plan->level->matrixColumns()->whereHas('mappings')->count());
        $this->assertSame(2, $plan->items()->where('syllabus_item_id', $weeklyA->id)->count());
        $this->assertSame(1, $plan->items()->where('syllabus_item_id', $weeklyB->id)->count());
        $this->assertFalse($plan->items()->where('syllabus_item_id', $tentative->id)->exists());
        $this->assertFalse($plan->items()->where('calendar_week_id', $holiday->id)->exists());
        $this->assertSame('66.67', (string) $plan->coverage_percent);

        $anchor = $plan->items()->where('syllabus_item_id', $weeklyA->id)->orderBy('calendar_week_id')->firstOrFail();
        $anchor->update(['content' => 'Doa pilihan Admin', 'source' => 'manual', 'is_locked' => true]);
        $planner->generate($plan->fresh());

        $this->assertDatabaseHas('rpp_week_items', ['id' => $anchor->id, 'content' => 'Doa pilihan Admin', 'source' => 'manual', 'is_locked' => true]);
        $this->assertSame(2, $plan->items()->where('syllabus_item_id', $weeklyA->id)->count());
        $this->assertNotNull($plan->items()->where('syllabus_item_id', $weeklyA->id)->firstOrFail()->rpp_matrix_column_id);
    }

    public function test_layout_mapping_and_month_focus_save_as_one_versioned_batch(): void
    {
        [$plan, $weeklyA, , $tentative] = $this->fixture();
        app(RppPlanner::class)->generate($plan);
        $weeklyA->load('matrixMapping.column');
        $tentative->load('matrixMapping.column');
        $focus = $plan->monthFocuses()->firstOrFail();
        $user = User::factory()->create();

        $batch = app(CurriculumRevisionService::class)->applyBatch([
            ['domain' => 'matrix_column', 'id' => $weeklyA->matrixMapping->column->id, 'version' => 0, 'changes' => ['label' => 'Doa Harian Terpadu']],
            ['domain' => 'matrix_mapping', 'id' => $weeklyA->matrixMapping->id, 'version' => 0, 'changes' => ['rpp_matrix_column_id' => $tentative->matrixMapping->column->id]],
            ['domain' => 'month_focus', 'id' => $focus->id, 'version' => 0, 'changes' => ['focus_text' => 'Rukun dan kompak']],
        ], 'Sesuaikan matriks semester uji', $user);

        $this->assertSame(3, $batch->item_count);
        $this->assertSame($tentative->matrixMapping->column->id, $weeklyA->matrixMapping->fresh()->rpp_matrix_column_id);
        $this->assertDatabaseHas('rpp_month_focuses', ['id' => $focus->id, 'focus_text' => 'Rukun dan kompak', 'source' => 'manual', 'is_locked' => true]);
        $this->assertDatabaseHas('revision_items', ['revision_batch_id' => $batch->id, 'revisable_type' => 'matrix_column']);
        $this->assertDatabaseHas('revision_items', ['revision_batch_id' => $batch->id, 'revisable_type' => 'matrix_mapping']);
        $this->assertDatabaseHas('revision_items', ['revision_batch_id' => $batch->id, 'revisable_type' => 'month_focus']);
    }

    private function fixture(): array
    {
        $level = Level::query()->create(['code' => 'UJI', 'name' => 'Jenjang Uji', 'stage' => 'SD', 'grade' => 1, 'sort_order' => 1]);
        $document = SourceDocument::query()->create([
            'level_id' => $level->id, 'source_key' => 'silabus:uji', 'type' => 'silabus', 'title' => 'Silabus Uji',
            'path' => 'silabus-uji.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 2,
        ]);
        $weeklyA = $this->syllabus($level, $document, 1, 'Do’a harian', 'Doa pertama', '4 pertemuan / minggu', 'weekly');
        $weeklyB = $this->syllabus($level, $document, 2, 'Do’a harian', 'Doa kedua', '4 pertemuan / minggu', 'weekly');
        $tentative = $this->syllabus($level, $document, 3, 'Ekstrakurikuler (Olahraga dan Seni)', 'Futsal', 'Tentatif (Sabtu/Minggu)', 'tentative');
        $year = AcademicYear::query()->create(['label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2027-07-04', 'is_active' => true]);
        $weeks = collect();
        foreach ([1, 2, 3, 4] as $number) {
            $weeks->push(CalendarWeek::query()->create([
                'academic_year_id' => $year->id, 'week_number' => $number, 'semester' => 1,
                'starts_on' => '2026-07-'.str_pad((string) (6 + (($number - 1) * 7)), 2, '0', STR_PAD_LEFT),
                'month_label' => 'Juli', 'type' => $number === 4 ? 'holiday' : 'effective',
                'label' => $number === 4 ? 'Libur' : null, 'is_effective' => $number !== 4,
            ]));
        }
        $plan = RppPlan::query()->create(['academic_year_id' => $year->id, 'level_id' => $level->id, 'semester' => 1, 'status' => 'draft']);

        return [$plan, $weeklyA, $weeklyB, $tentative, $weeks->last()];
    }

    private function syllabus(Level $level, SourceDocument $document, int $order, string $category, string $title, string $allocation, string $pattern): SyllabusItem
    {
        return SyllabusItem::query()->create([
            'level_id' => $level->id, 'source_document_id' => $document->id, 'source_key' => 'silabus:uji:'.$order,
            'stable_code' => 'UJI / '.str_pad((string) $order, 3, '0', STR_PAD_LEFT), 'category' => $category,
            'title' => $title, 'description' => $title, 'allocation_text' => $allocation,
            'recommended_sessions' => 4, 'schedule_pattern' => $pattern, 'schedule_pattern_source' => 'auto',
            'needs_allocation' => false, 'is_duplicate' => false, 'source_page' => 1, 'sort_order' => $order,
            'group_number' => 1, 'source_semester' => '1', 'semester_scope' => '1',
        ]);
    }
}
