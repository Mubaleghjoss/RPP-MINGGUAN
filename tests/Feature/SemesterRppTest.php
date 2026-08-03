<?php

namespace Tests\Feature;

use App\Livewire\ExportPreview;
use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\User;
use App\Services\CurriculumRevisionService;
use App\Services\RppPlanner;
use Database\Seeders\CurriculumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SemesterRppTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_builds_thirty_four_independent_semester_plans(): void
    {
        $this->seed(CurriculumSeeder::class);

        $this->assertDatabaseCount('levels', 17);
        $this->assertDatabaseCount('rpp_plans', 34);
        $this->assertSame(17, RppPlan::query()->where('semester', 1)->count());
        $this->assertSame(17, RppPlan::query()->where('semester', 2)->count());
        $this->assertSame(26, AcademicYear::query()->where('is_active', true)->firstOrFail()->weeks()->where('semester', 1)->count());
        $this->assertSame(26, AcademicYear::query()->where('is_active', true)->firstOrFail()->weeks()->where('semester', 2)->count());
        $this->assertSame(0, RppPlan::query()->whereHas('items.week', fn ($query) => $query->whereColumn('calendar_weeks.semester', '!=', 'rpp_plans.semester'))->count());
    }

    public function test_paud_tilawati_reaches_twenty_two_units_each_semester_and_uses_reinforcement(): void
    {
        $this->seed(CurriculumSeeder::class);
        $paud = Level::query()->where('code', 'PAUD')->firstOrFail();

        foreach ([1 => [1, 22], 2 => [23, 44]] as $semester => [$start, $end]) {
            $plan = RppPlan::query()->where('level_id', $paud->id)->where('semester', $semester)->firstOrFail();
            $target = $plan->progressTargets()->whereHas('syllabusItem', fn ($query) => $query->where('title', 'like', '%Tilawati%'))->firstOrFail();
            $this->assertSame($start, (int) $target->range_start);
            $this->assertSame($end, (int) $target->range_end);
            $this->assertSame($start, (int) $target->placements()->min('progress_start'));
            $this->assertSame($end, (int) $target->placements()->max('progress_end'));
            $this->assertTrue($target->placements()->where('progress_kind', 'penguatan')->exists());
            $this->assertFalse($target->placements()->whereHas('week', fn ($query) => $query->where('semester', '!=', $semester)->orWhere('is_effective', false))->exists());
        }
    }

    public function test_overlapping_manual_progress_anchors_are_rejected_atomically(): void
    {
        $this->seed(CurriculumSeeder::class);
        $plan = RppPlan::query()->whereHas('level', fn ($query) => $query->where('code', 'PAUD'))->where('semester', 1)->firstOrFail();
        $target = $plan->progressTargets()->whereHas('syllabusItem', fn ($query) => $query->where('title', 'like', '%Tilawati%'))->firstOrFail();
        $anchors = $target->placements()->with('week')->orderBy('calendar_week_id')->limit(2)->get();
        $user = User::factory()->create();

        try {
            app(CurriculumRevisionService::class)->applyBatch([
                ['domain' => 'rpp', 'id' => $anchors[0]->id, 'version' => $anchors[0]->lock_version, 'changes' => ['progress_start' => 1, 'progress_end' => 5]],
                ['domain' => 'rpp', 'id' => $anchors[1]->id, 'version' => $anchors[1]->lock_version, 'changes' => ['progress_start' => 5, 'progress_end' => 7]],
            ], 'uji konflik rentang', $user);
            $this->fail('Batch tumpang tindih seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tumpang tindih', collect($exception->errors())->flatten()->first());
        }
        $this->assertDatabaseHas('rpp_week_items', ['id' => $anchors[0]->id, 'source' => 'auto', 'is_locked' => false]);
        $this->assertDatabaseHas('rpp_week_items', ['id' => $anchors[1]->id, 'source' => 'auto', 'is_locked' => false]);

        $anchors[0]->update(['source' => 'manual', 'is_locked' => true, 'progress_start' => 1, 'progress_end' => 5]);
        $anchors[1]->update(['source' => 'manual', 'is_locked' => true, 'progress_start' => 5, 'progress_end' => 7]);

        try {
            app(RppPlanner::class)->generate($plan);
            $this->fail('Rentang tumpang tindih seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tumpang tindih', collect($exception->errors())->flatten()->first());
        }

        $this->assertDatabaseHas('rpp_week_items', ['id' => $anchors[0]->id, 'progress_start' => 1, 'progress_end' => 5, 'is_locked' => true]);
        $this->assertDatabaseHas('rpp_week_items', ['id' => $anchors[1]->id, 'progress_start' => 5, 'progress_end' => 7, 'is_locked' => true]);
    }

    public function test_export_preview_renders_selected_level_and_semester(): void
    {
        $this->seed(CurriculumSeeder::class);
        $user = User::factory()->create();
        $paud = Level::query()->where('code', 'PAUD')->firstOrFail();

        $component = Livewire::actingAs($user)
            ->test(ExportPreview::class)
            ->set('levelId', $paud->id)
            ->call('selectSemester', 2)
            ->assertSee('RPP PAUD · Semester 2')
            ->assertSee('23–44')
            ->assertSee('Matriks 26 minggu');

        $material = $paud->syllabusItems()->where('is_duplicate', false)->whereDoesntHave('progressTargets')->firstOrFail();
        $component
            ->set('targetSyllabusId', $material->id)
            ->set('targetUnit', 'ayat')
            ->set('targetStart', 1)
            ->set('targetEnd', 12)
            ->set('targetReason', 'target terukur admin')
            ->call('saveTarget')
            ->assertHasNoErrors()
            ->assertSee('Target disimpan dalam revisi');
        $this->assertDatabaseHas('rpp_progress_targets', ['syllabus_item_id' => $material->id, 'unit_label' => 'ayat', 'range_start' => 1, 'range_end' => 12, 'source' => 'manual']);
        $this->assertDatabaseHas('revision_items', ['revisable_type' => 'progress_target']);
    }
}
