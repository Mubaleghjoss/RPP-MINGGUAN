<?php

namespace App\Livewire;

use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Services\RppBulkActionService;
use App\Services\RppPlanner;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('layouts.app')]
#[Title('Penyusun RPP Mingguan')]
class Planner extends Component
{
    use WithPagination;

    public Level $level;

    public RppPlan $plan;

    public string $notice = '';

    public string $errorMessage = '';

    #[Url]
    public string $detail = '';

    public array $selectedPlacements = [];

    public array $selectedSyllabus = [];

    public string $bulkReason = '';

    public ?int $bulkWeekId = null;

    public function mount(Level $level): void
    {
        $this->level = $level;
        $year = AcademicYear::query()->where('is_active', true)->firstOrFail();
        $this->plan = RppPlan::query()->firstOrCreate(
            ['academic_year_id' => $year->id, 'level_id' => $level->id],
            ['status' => 'draft']
        );
        if (! in_array($this->detail, ['', 'unplanned', 'allocation'], true)) {
            $this->detail = '';
        }
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

    public function toggleLock(int $placementId, RppBulkActionService $bulk): void
    {
        $item = RppWeekItem::query()->where('rpp_plan_id', $this->plan->id)->findOrFail($placementId);
        $action = $item->is_locked ? 'unlock' : 'lock';
        $bulk->updatePlacements($this->plan, [$item->id], $action, null, 'Aksi satuan dari planner', Auth::id());
        $this->afterBulk($item->is_locked ? 'Kunci materi dilepas.' : 'Materi dikunci.');
    }

    public function movePlacement(int $placementId, int $weekId, RppBulkActionService $bulk): void
    {
        $bulk->updatePlacements($this->plan, [$placementId], 'move', $weekId, 'Aksi satuan dari planner', Auth::id());
        $this->afterBulk('Materi dipindahkan dan otomatis dikunci.');
    }

    public function applyPlacementBulk(string $action, RppBulkActionService $bulk): void
    {
        $this->runBulk(function () use ($action, $bulk) {
            $count = $bulk->updatePlacements($this->plan, $this->selectedPlacements, $action, $this->bulkWeekId, $this->bulkReason, Auth::id());
            $labels = ['move' => 'dipindahkan dan dikunci', 'lock' => 'dikunci', 'unlock' => 'dibuka kuncinya'];
            $this->afterBulk("{$count} materi {$labels[$action]}.");
            $this->selectedPlacements = [];
        });
    }

    public function scheduleSelected(RppBulkActionService $bulk): void
    {
        $this->runBulk(function () use ($bulk) {
            $count = $bulk->scheduleUnplanned($this->plan, $this->selectedSyllabus, $this->bulkWeekId, $this->bulkReason, Auth::id());
            $this->afterBulk("{$count} materi dijadwalkan manual dan dikunci.");
            $this->selectedSyllabus = [];
        });
    }

    public function selectAllPlacements(): void
    {
        $this->selectedPlacements = $this->plan->items()->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function selectVisibleSyllabus(array $ids): void
    {
        $allowed = $this->unplannedQuery()->where('needs_allocation', false)->whereIn('id', $ids)->pluck('id');
        $this->selectedSyllabus = collect($this->selectedSyllabus)->merge($allowed)->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    public function clearPlacementSelection(): void
    {
        $this->selectedPlacements = [];
    }

    public function clearSyllabusSelection(): void
    {
        $this->selectedSyllabus = [];
    }

    public function closeDetail(): void
    {
        $this->detail = '';
        $this->selectedSyllabus = [];
        $this->resetPage('detailPage');
    }

    public function updatedDetail(): void
    {
        $this->selectedSyllabus = [];
        $this->resetPage('detailPage');
    }

    public function render()
    {
        $this->plan->load(['academicYear.weeks' => fn ($query) => $query->orderBy('week_number'), 'items.syllabusItem']);
        $weeks = $this->plan->academicYear->weeks;
        $itemsByWeek = $this->plan->items->sortBy(['strand', 'position'])->groupBy('calendar_week_id');
        $unplanned = $this->unplannedQuery()->count();
        $needsAllocation = $this->level->syllabusItems()->where('is_duplicate', false)->where('needs_allocation', true)->count();
        $detailItems = $this->detailItems();

        return view('livewire.planner', compact('weeks', 'itemsByWeek', 'unplanned', 'needsAllocation', 'detailItems'));
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

    private function unplannedQuery()
    {
        return $this->level->syllabusItems()
            ->where('is_duplicate', false)
            ->whereDoesntHave('placements', fn ($query) => $query->where('rpp_plan_id', $this->plan->id));
    }

    private function detailItems(): ?LengthAwarePaginator
    {
        return match ($this->detail) {
            'unplanned' => $this->unplannedQuery()->orderBy('sort_order')->paginate(25, ['*'], 'detailPage'),
            'allocation' => $this->level->syllabusItems()->where('is_duplicate', false)->where('needs_allocation', true)->orderBy('sort_order')->paginate(25, ['*'], 'detailPage'),
            default => null,
        };
    }

    private function runBulk(callable $callback): void
    {
        $this->errorMessage = '';
        $this->notice = '';
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first() ?? 'Data bulk tidak valid.';
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'Bulk action gagal. Tidak ada perubahan yang diterapkan.';
        }
    }

    private function afterBulk(string $notice): void
    {
        $this->plan->refresh();
        $this->bulkReason = '';
        $this->bulkWeekId = null;
        $this->notice = $notice;
    }
}
