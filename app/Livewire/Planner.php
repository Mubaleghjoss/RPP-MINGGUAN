<?php

namespace App\Livewire;

use App\Models\AcademicYear;
use App\Models\CalendarWeek;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Services\RppPlanner;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

#[Layout('layouts.app')]
#[Title('Penyusun RPP Mingguan')]
class Planner extends Component
{
    public Level $level;
    public RppPlan $plan;
    public string $notice = '';

    public function mount(Level $level): void
    {
        $this->level = $level;
        $year = AcademicYear::query()->where('is_active', true)->firstOrFail();
        $this->plan = RppPlan::query()->firstOrCreate(
            ['academic_year_id' => $year->id, 'level_id' => $level->id],
            ['status' => 'draft']
        );
    }

    public function generate(RppPlanner $planner): void
    {
        $this->plan = $planner->generate($this->plan);
        $this->log('rpp.generated', ['plan_id' => $this->plan->id, 'level' => $this->level->code]);
        $this->notice = 'Draf otomatis diperbarui. Materi terkunci tetap dipertahankan.';
    }

    public function generateAll(RppPlanner $planner): void
    {
        $planner->generateAll();
        $this->plan->refresh();
        $this->log('rpp.generated_all', ['academic_year_id' => $this->plan->academic_year_id]);
        $this->notice = 'Semua 17 jenjang disusun ulang. Koreksi yang dikunci tetap dipertahankan.';
    }

    public function fillEmpty(RppPlanner $planner): void
    {
        $this->generate($planner);
        $this->notice = 'Minggu efektif yang kosong telah diisi sejauh alokasi sumber tersedia.';
    }

    public function rebalance(RppPlanner $planner): void
    {
        $this->generate($planner);
        $this->notice = 'Beban otomatis diratakan kembali tanpa mengubah materi terkunci.';
    }

    public function restartFromSyllabus(RppPlanner $planner): void
    {
        $this->generate($planner);
        $this->notice = 'Bagian otomatis diulang dari urutan silabus; koreksi terkunci tetap aman.';
    }

    public function validatePlan(RppPlanner $planner): void
    {
        $valid = $planner->validate($this->plan);
        $this->plan->refresh();
        $this->log('rpp.validation_attempted', ['plan_id' => $this->plan->id, 'valid' => $valid]);
        $this->notice = $valid ? 'RPP dinyatakan tervalidasi.' : 'Validasi ditahan karena masih ada materi yang belum dijadwalkan.';
    }

    public function toggleLock(int $placementId): void
    {
        $item = RppWeekItem::query()->where('rpp_plan_id', $this->plan->id)->findOrFail($placementId);
        $item->update(['is_locked' => ! $item->is_locked, 'source' => 'manual']);
        $this->log('rpp.lock_toggled', ['placement_id' => $item->id, 'is_locked' => $item->fresh()->is_locked]);
        $this->notice = $item->fresh()->is_locked ? 'Materi dikunci.' : 'Kunci materi dilepas.';
    }

    public function movePlacement(int $placementId, int $weekId, RppPlanner $planner): void
    {
        $week = CalendarWeek::query()->where('academic_year_id', $this->plan->academic_year_id)->where('is_effective', true)->findOrFail($weekId);
        $item = RppWeekItem::query()->where('rpp_plan_id', $this->plan->id)->findOrFail($placementId);
        $item->update(['calendar_week_id' => $week->id, 'source' => 'manual', 'is_locked' => true]);
        $planner->refreshCoverage($this->plan);
        $this->log('rpp.moved', ['placement_id' => $item->id, 'calendar_week_id' => $week->id]);
        $this->notice = 'Materi dipindahkan dan otomatis dikunci.';
    }

    public function render()
    {
        $this->plan->load(['academicYear.weeks' => fn ($query) => $query->orderBy('week_number'), 'items.syllabusItem']);
        $weeks = $this->plan->academicYear->weeks;
        $itemsByWeek = $this->plan->items->sortBy(['strand', 'position'])->groupBy('calendar_week_id');
        $unplanned = $this->level->syllabusItems()->where('is_duplicate', false)->whereDoesntHave('placements', fn ($query) => $query->where('rpp_plan_id', $this->plan->id))->count();
        $needsAllocation = $this->level->syllabusItems()->where('is_duplicate', false)->where('needs_allocation', true)->count();
        return view('livewire.planner', compact('weeks', 'itemsByWeek', 'unplanned', 'needsAllocation'));
    }

    private function log(string $action, array $details): void
    {
        DB::table('activity_logs')->insert([
            'user_id' => auth()->id(),
            'action' => $action,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
