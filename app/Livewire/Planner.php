<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithPersistentNotifications;
use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Services\AcademicCalendarService;
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
    use InteractsWithPersistentNotifications;
    use WithPagination;

    public Level $level;

    public RppPlan $plan;

    public string $notice = '';

    public string $errorMessage = '';

    #[Url]
    public string $detail = '';

    #[Url]
    public int $semester = 1;

    public array $selectedPlacements = [];

    public array $selectedSyllabus = [];

    public string $bulkReason = '';

    public ?int $bulkWeekId = null;

    public ?int $manualSyllabusId = null;

    public ?int $manualWeekId = null;

    public string $manualReason = '';

    public function mount(Level $level): void
    {
        $this->level = $level;
        abort_unless(in_array($this->semester, [1, 2], true), 404);
        $year = AcademicYear::query()->where('is_active', true)->firstOrFail();
        $this->plan = RppPlan::query()->firstOrCreate(
            ['academic_year_id' => $year->id, 'level_id' => $level->id, 'semester' => $this->semester],
            ['status' => 'draft']
        );
        if (! in_array($this->detail, ['', 'unplanned', 'allocation'], true)) {
            $this->detail = '';
        }
    }

    public function selectSemester(int $semester): void
    {
        abort_unless(in_array($semester, [1, 2], true), 422);
        $this->semester = $semester;
        $this->plan = RppPlan::query()->firstOrCreate(
            ['academic_year_id' => $this->plan->academic_year_id, 'level_id' => $this->level->id, 'semester' => $semester],
            ['status' => 'draft']
        );
        $this->detail = '';
        $this->selectedPlacements = [];
        $this->selectedSyllabus = [];
        $this->resetManualScheduling();
        $this->resetPage('detailPage');
    }

    public function generate(RppPlanner $planner): void
    {
        $this->runGenerate($planner, 'Draf otomatis diperbarui. Materi terkunci tetap dipertahankan.');
    }

    public function generateAll(RppPlanner $planner): void
    {
        $this->errorMessage = '';
        try {
            $planner->generateAll();
            $this->plan->refresh();
            $this->log('rpp.generated_all', ['academic_year_id' => $this->plan->academic_year_id]);
            $this->notifySuccess('Semua 34 RPP semester disusun ulang. Koreksi yang dikunci tetap dipertahankan.', 'Penyusunan semua kelas selesai');
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception, 'Penyusunan semua kelas ditahan', ['Periksa minggu efektif, alokasi, dan pemetaan materi pada jenjang yang disebutkan.'], null, 'Penyusunan seluruh semester gagal.');
        } catch (Throwable $exception) {
            $this->notifyTechnicalFailure($exception, 'Penyusunan seluruh kelas gagal. Tidak ada perubahan yang diterapkan.', 'Penyusunan semua kelas mengalami gangguan');
        }
    }

    public function fillEmpty(RppPlanner $planner): void
    {
        $this->runGenerate($planner, 'Minggu efektif yang kosong telah diisi sejauh alokasi sumber tersedia.');
    }

    public function rebalance(RppPlanner $planner): void
    {
        $this->runGenerate($planner, 'Beban otomatis diratakan kembali tanpa mengubah materi terkunci.');
    }

    public function restartFromSyllabus(RppPlanner $planner): void
    {
        $this->runGenerate($planner, 'Bagian otomatis diulang dari urutan silabus; koreksi terkunci tetap aman.');
    }

    public function validatePlan(RppPlanner $planner): void
    {
        $valid = $planner->validate($this->plan);
        $this->plan->refresh();
        $this->log('rpp.validation_attempted', ['plan_id' => $this->plan->id, 'valid' => $valid]);
        if ($valid) {
            $this->notifySuccess('RPP dinyatakan tervalidasi.', 'Validasi RPP berhasil');
        } else {
            $this->notifyWarning(
                'Validasi ditahan karena masih ada materi yang belum dijadwalkan.',
                'RPP belum lengkap',
                [],
                ['Buka kartu Belum dijadwalkan, lengkapi materi yang tersisa, lalu validasi kembali.'],
            );
        }
    }

    public function toggleLock(int $placementId, RppBulkActionService $bulk): void
    {
        $this->runBulk(function () use ($placementId, $bulk) {
            $item = RppWeekItem::query()->where('rpp_plan_id', $this->plan->id)->findOrFail($placementId);
            $action = $item->is_locked ? 'unlock' : 'lock';
            $bulk->updatePlacements($this->plan, [$item->id], $action, null, 'Aksi satuan dari planner', Auth::id());
            $this->afterBulk($item->is_locked ? 'Kunci materi dilepas.' : 'Materi dikunci.');
        });
    }

    public function movePlacement(int $placementId, int $weekId, RppBulkActionService $bulk): void
    {
        $this->runBulk(function () use ($placementId, $weekId, $bulk) {
            $bulk->updatePlacements($this->plan, [$placementId], 'move', $weekId, 'Aksi satuan dari planner', Auth::id());
            $this->afterBulk('Materi dipindahkan dan otomatis dikunci.');
        });
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

    public function scheduleAutomatically(int $syllabusItemId, RppPlanner $planner): void
    {
        $this->runBulk(function () use ($syllabusItemId, $planner) {
            $placement = $planner->scheduleOne($this->plan, $syllabusItemId, Auth::id());
            $this->afterScheduling("Materi dijadwalkan otomatis ke Minggu {$placement->week->week_number}. Materi lain tidak dipindahkan.");
        });
    }

    public function openManualScheduling(int $syllabusItemId): void
    {
        $this->errorMessage = '';
        $item = $this->unplannedQuery()->find($syllabusItemId);
        if (! $item || $item->needs_allocation || blank($item->allocation_text) || (int) $item->recommended_sessions < 1) {
            $this->notifyWarning(
                'Materi belum siap dijadwalkan. Lengkapi alokasi dan jumlah pertemuan minimal 1.',
                'Materi belum siap',
                [],
                ['Buka Edit Silabus, isi alokasi dan jumlah pertemuan, kemudian kembali ke Planner.'],
            );

            return;
        }

        $this->manualSyllabusId = $item->id;
        $this->manualWeekId = null;
        $this->manualReason = '';
    }

    public function closeManualScheduling(): void
    {
        $this->resetManualScheduling();
    }

    public function scheduleManual(int $syllabusItemId, RppBulkActionService $bulk): void
    {
        $this->runBulk(function () use ($syllabusItemId, $bulk) {
            if ((int) $this->manualSyllabusId !== $syllabusItemId) {
                throw ValidationException::withMessages(['material' => 'Form penjadwalan tidak lagi sesuai dengan materi. Buka kembali pilihan minggu.']);
            }
            $week = app(AcademicCalendarService::class)->weeksForPlan($this->plan, true)->firstWhere('id', (int) $this->manualWeekId);
            $bulk->scheduleUnplanned(
                $this->plan,
                [$syllabusItemId],
                $this->manualWeekId,
                $this->manualReason,
                Auth::id(),
                'rpp.item_scheduled_manual'
            );
            $weekLabel = $week ? "Minggu {$week->week_number}" : 'minggu pilihan';
            $this->afterScheduling("Materi dijadwalkan manual ke {$weekLabel} dan dikunci.");
        });
    }

    public function selectAllPlacements(): void
    {
        $this->selectedPlacements = $this->plan->items()->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function selectVisibleSyllabus(array $ids): void
    {
        $allowed = $this->unplannedQuery()
            ->where('needs_allocation', false)
            ->whereNotNull('allocation_text')
            ->where('allocation_text', '<>', '')
            ->where('recommended_sessions', '>=', 1)
            ->whereIn('id', $ids)
            ->pluck('id');
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
        $this->resetManualScheduling();
        $this->resetPage('detailPage');
    }

    public function updatedDetail(): void
    {
        $this->selectedSyllabus = [];
        $this->resetManualScheduling();
        $this->resetPage('detailPage');
    }

    public function render(AcademicCalendarService $calendar)
    {
        $this->plan->load(['academicYear', 'items.syllabusItem']);
        $weeks = $calendar->weeksForPlan($this->plan);
        $itemsByWeek = $this->plan->items->sortBy(['strand', 'position'])->groupBy('calendar_week_id');
        $unplanned = $this->unplannedQuery()->count();
        $needsAllocation = $this->level->syllabusItems()->where('is_duplicate', false)->where('is_source_artifact', false)->whereIn('semester_scope', [(string) $this->plan->semester, 'both'])->where('needs_allocation', true)->count();
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
            ->where('is_source_artifact', false)
            ->whereIn('semester_scope', [(string) $this->plan->semester, 'both'])
            ->whereDoesntHave('placements', fn ($query) => $query->where('rpp_plan_id', $this->plan->id));
    }

    private function detailItems(): ?LengthAwarePaginator
    {
        return match ($this->detail) {
            'unplanned' => $this->unplannedQuery()->orderBy('sort_order')->paginate(25, ['*'], 'detailPage'),
            'allocation' => $this->level->syllabusItems()->where('is_duplicate', false)->where('is_source_artifact', false)->whereIn('semester_scope', [(string) $this->plan->semester, 'both'])->where('needs_allocation', true)->orderBy('sort_order')->paginate(25, ['*'], 'detailPage'),
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
            $this->notifyValidationException(
                $exception,
                'Tindakan Planner belum dapat dijalankan',
                ['Periksa pilihan materi, alasan tindakan, dan minggu efektif tujuan.'],
                null,
                'Tindakan tidak valid.',
            );
        } catch (Throwable $exception) {
            $this->notifyTechnicalFailure($exception, 'Tindakan gagal. Tidak ada perubahan yang diterapkan.', 'Planner mengalami gangguan');
        }
    }

    private function afterBulk(string $notice): void
    {
        $this->plan->refresh();
        $this->bulkReason = '';
        $this->bulkWeekId = null;
        $this->notifySuccess($notice, 'Planner diperbarui');
    }

    private function afterScheduling(string $notice): void
    {
        $this->afterBulk($notice);
        $this->selectedSyllabus = [];
        $this->resetManualScheduling();
        $this->resetPage('detailPage');
    }

    private function resetManualScheduling(): void
    {
        $this->manualSyllabusId = null;
        $this->manualWeekId = null;
        $this->manualReason = '';
    }

    private function runGenerate(RppPlanner $planner, string $successMessage): void
    {
        $this->errorMessage = '';
        try {
            $this->plan = $planner->generate($this->plan);
            $this->log('rpp.generated', ['plan_id' => $this->plan->id, 'level' => $this->level->code, 'semester' => $this->semester]);
            $this->notifySuccess($successMessage, 'Penyusunan RPP selesai');
        } catch (ValidationException $exception) {
            $this->notifyValidationException(
                $exception,
                'Penyusunan RPP ditahan',
                ['Periksa minggu efektif, alokasi, pemetaan kolom, dan target progres.'],
                null,
                'Penyusunan otomatis gagal.',
            );
        } catch (Throwable $exception) {
            $this->notifyTechnicalFailure($exception, 'Penyusunan RPP gagal. Tidak ada perubahan yang diterapkan.', 'Penyusunan RPP mengalami gangguan');
        }
    }
}
