<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CalendarWeek;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\SourceDocument;
use App\Models\SyllabusItem;
use App\Services\RppPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RppPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_is_idempotent_respects_non_effective_weeks_and_preserves_locks(): void
    {
        $level = Level::query()->create(['code' => 'UJI', 'name' => 'Kelas Uji', 'stage' => 'Uji', 'sort_order' => 1]);
        $document = SourceDocument::query()->create([
            'level_id' => $level->id, 'source_key' => 'silabus:uji', 'type' => 'silabus',
            'title' => 'Silabus Uji', 'path' => 'uji.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 1,
        ]);
        $year = AcademicYear::query()->create([
            'label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2027-07-04', 'is_active' => true,
        ]);
        $effective = CalendarWeek::query()->create([
            'academic_year_id' => $year->id, 'week_number' => 1, 'starts_on' => '2026-07-06',
            'month_label' => 'Juli', 'type' => 'effective', 'is_effective' => true,
        ]);
        $holiday = CalendarWeek::query()->create([
            'academic_year_id' => $year->id, 'week_number' => 2, 'starts_on' => '2026-07-13',
            'month_label' => 'Juli', 'type' => 'holiday', 'is_effective' => false,
        ]);
        $base = [
            'level_id' => $level->id, 'source_document_id' => $document->id,
            'description' => 'Uji', 'source_page' => 1, 'group_number' => 1,
        ];
        $scheduled = SyllabusItem::query()->create($base + [
            'source_key' => 's1', 'stable_code' => 'UJI / FAQIH / 001', 'category' => 'Faqih',
            'title' => 'Materi terjadwal', 'allocation_text' => '1 jam/minggu', 'recommended_sessions' => 1,
            'needs_allocation' => false, 'is_duplicate' => false, 'sort_order' => 1,
        ]);
        SyllabusItem::query()->create($base + [
            'source_key' => 's2', 'stable_code' => 'UJI / FAQIH / 002', 'category' => 'Faqih',
            'title' => 'Materi tanpa alokasi', 'allocation_text' => null, 'recommended_sessions' => null,
            'needs_allocation' => true, 'is_duplicate' => false, 'sort_order' => 2,
        ]);
        SyllabusItem::query()->create($base + [
            'source_key' => 's3', 'stable_code' => 'UJI / FAQIH / 003', 'category' => 'Faqih',
            'title' => 'Materi terjadwal', 'allocation_text' => '1 jam/minggu', 'recommended_sessions' => 1,
            'needs_allocation' => false, 'is_duplicate' => true, 'sort_order' => 3,
        ]);
        $plan = RppPlan::query()->create(['academic_year_id' => $year->id, 'level_id' => $level->id]);
        $planner = app(RppPlanner::class);

        $planner->generate($plan);
        $this->assertDatabaseCount('rpp_week_items', 1);
        $this->assertDatabaseHas('rpp_week_items', [
            'calendar_week_id' => $effective->id, 'syllabus_item_id' => $scheduled->id,
        ]);
        $this->assertDatabaseMissing('rpp_week_items', ['calendar_week_id' => $holiday->id]);

        $placement = $plan->items()->firstOrFail();
        $placement->update(['is_locked' => true, 'source' => 'manual']);
        $planner->generate($plan);
        $this->assertDatabaseCount('rpp_week_items', 1);
        $this->assertDatabaseHas('rpp_week_items', ['id' => $placement->id, 'is_locked' => true]);
        $this->assertFalse($planner->validate($plan->fresh()));
    }
}
