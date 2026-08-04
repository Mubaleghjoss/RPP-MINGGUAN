<?php

namespace App\Livewire;

use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppMatrixColumn;
use App\Models\RppMatrixMapping;
use App\Models\RppMonthFocus;
use App\Models\RppPlan;
use App\Models\RppProgressTarget;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use App\Services\CurriculumRevisionService;
use App\Services\RppMaterialCatalogService;
use App\Services\RppMaterialPlacementService;
use App\Services\RppMatrixPresetService;
use App\Services\RppMatrixService;
use App\Services\RppPlanner;
use App\Services\RppProgressService;
use App\Services\RppSchedulePatternService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use Throwable;

#[Layout('layouts.app')]
#[Title('Preview dan Ekspor RPP')]
class ExportPreview extends Component
{
    #[Url(as: 'level')]
    public ?int $levelId = null;

    #[Url]
    public int $semester = 1;

    public string $notice = '';

    public string $errorMessage = '';

    public ?int $targetSyllabusId = null;

    public string $targetUnit = 'halaman';

    public ?int $targetStart = null;

    public ?int $targetEnd = null;

    public string $targetStrategy = 'even';

    public int $targetVersion = 0;

    public string $targetReason = '';

    public ?int $pickerWeekId = null;

    public ?int $pickerColumnId = null;

    public string $pickerSearch = '';

    public string $pickerStatus = 'all';

    public array $pickerSelected = [];

    public string $pickerReason = '';

    public function mount(): void
    {
        abort_unless(in_array($this->semester, [1, 2], true), 404);
        $this->levelId ??= Level::query()->orderBy('sort_order')->value('id');
        $this->assertLevel();
        $this->notice = (string) session('notice', '');
    }

    public function updatedLevelId(): void
    {
        $this->assertLevel();
        $this->resetTargetForm();
        $this->resetMessages();
        $this->closeMaterialPicker();
    }

    public function selectSemester(int $semester): void
    {
        abort_unless(in_array($semester, [1, 2], true), 422);
        $this->semester = $semester;
        $this->resetTargetForm();
        $this->resetMessages();
        $this->closeMaterialPicker();
    }

    public function openMaterialPicker(int $weekId, int $columnId): void
    {
        $plan = $this->plan();
        abort_unless($plan->academicYear->weeks()->whereKey($weekId)->where('semester', $plan->semester)->where('is_effective', true)->exists(), 422);
        abort_unless(RppMatrixColumn::query()->whereKey($columnId)->where('level_id', $plan->level_id)->where('is_active', true)->exists(), 422);
        $this->pickerWeekId = $weekId;
        $this->pickerColumnId = $columnId;
        $this->pickerSearch = '';
        $this->pickerStatus = 'all';
        $this->pickerSelected = [];
        $this->pickerReason = '';
        $this->resetMessages();
    }

    public function closeMaterialPicker(): void
    {
        $this->reset(['pickerWeekId', 'pickerColumnId', 'pickerSearch', 'pickerSelected', 'pickerReason']);
        $this->pickerStatus = 'all';
    }

    public function addSelectedMaterials(RppMaterialPlacementService $placements): void
    {
        $this->resetMessages();
        try {
            $count = $placements->addToCell(
                $this->plan(),
                (int) $this->pickerWeekId,
                (int) $this->pickerColumnId,
                $this->pickerSelected,
                $this->pickerReason,
                Auth::id(),
            );
            $this->notice = "{$count} materi ditambahkan dan dikunci. Materi yang pernah digunakan ditandai sebagai penguatan.";
            $this->closeMaterialPicker();
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first() ?? 'Materi tidak dapat ditambahkan.';
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'Penambahan materi gagal. Tidak ada perubahan yang diterapkan.';
        }
    }

    public function updatedTargetSyllabusId(): void
    {
        $this->hydrateTargetForm($this->targetSyllabusId);
    }

    public function editTarget(?int $syllabusId): void
    {
        $this->targetSyllabusId = $syllabusId;
        $this->hydrateTargetForm($syllabusId);
    }

    private function hydrateTargetForm(?int $syllabusId, bool $resetMessages = true): void
    {
        $target = $syllabusId
            ? $this->plan()->progressTargets()->where('syllabus_item_id', $syllabusId)->first()
            : null;
        $this->targetUnit = $target?->unit_label ?? 'halaman';
        $this->targetStart = $target?->range_start;
        $this->targetEnd = $target?->range_end;
        $this->targetStrategy = $target?->strategy ?? 'even';
        $this->targetVersion = (int) ($target?->lock_version ?? 0);
        $this->targetReason = '';
        if ($resetMessages) {
            $this->resetMessages();
        }
    }

    public function saveTarget(CurriculumRevisionService $revisions): void
    {
        $this->resetMessages();
        try {
            $plan = $this->plan();
            $item = SyllabusItem::query()
                ->where('level_id', $this->levelId)
                ->whereIn('semester_scope', [(string) $this->semester, 'both'])
                ->where('is_duplicate', false)
                ->findOrFail($this->targetSyllabusId);
            $batch = $revisions->saveProgressTarget($plan, $item, [
                'unit_label' => $this->targetUnit,
                'range_start' => $this->targetStart,
                'range_end' => $this->targetEnd,
                'strategy' => $this->targetStrategy,
            ], $this->targetVersion, $this->targetReason, Auth::user());
            $this->notice = "Target disimpan dalam revisi {$batch->uuid}. Klik Susun Otomatis untuk membagi rentang ke minggu efektif.";
            $this->hydrateTargetForm($item->id, false);
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first() ?? 'Target tidak valid.';
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'Target gagal disimpan. Tidak ada perubahan yang diterapkan.';
        }
    }

    public function deleteTarget(int $targetId, CurriculumRevisionService $revisions): void
    {
        $this->resetMessages();
        try {
            $target = $this->plan()->progressTargets()->findOrFail($targetId);
            $batch = $revisions->deleteProgressTarget($target, $this->targetReason, Auth::user());
            $this->notice = "Target dinonaktifkan dalam revisi {$batch->uuid}.";
            $this->resetTargetForm();
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first() ?? 'Target tidak dapat dihapus.';
        }
    }

    public function savePatches(array $patches, string $reason, CurriculumRevisionService $revisions): array
    {
        $this->resetMessages();
        try {
            $plan = $this->plan();
            foreach ($patches as $patch) {
                $id = (int) ($patch['id'] ?? 0);
                $valid = match ($patch['domain'] ?? null) {
                    'rpp' => RppWeekItem::query()->where('rpp_plan_id', $plan->id)->whereKey($id)->exists(),
                    'month_focus' => RppMonthFocus::query()->where('rpp_plan_id', $plan->id)->whereKey($id)->exists(),
                    'matrix_column' => RppMatrixColumn::query()->where('level_id', $plan->level_id)->whereKey($id)->exists(),
                    'matrix_mapping' => RppMatrixMapping::query()->whereKey($id)->whereHas('syllabusItem', fn ($query) => $query->where('level_id', $plan->level_id))->exists(),
                    'material_catalog' => RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)->whereKey($id)->exists(),
                    default => false,
                };
                abort_unless($valid, 422);
            }
            $batch = $revisions->applyBatch($patches, $reason, Auth::user());
            app(RppPlanner::class)->refreshCoverage($plan);
            $this->notice = "{$batch->item_count} baris disimpan dalam revisi {$batch->uuid}.";
            $this->dispatch('grid-saved');

            return ['ok' => true, 'batch' => $batch->uuid];
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first() ?? 'Data tidak valid.';
        } catch (RuntimeException $exception) {
            $this->errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'Perubahan gagal disimpan. Tidak ada data yang diterapkan.';
        }

        return ['ok' => false, 'message' => $this->errorMessage];
    }

    public function generate(RppPlanner $planner): void
    {
        $this->resetMessages();
        try {
            $planner->generate($this->plan());
            $this->notice = "Semester {$this->semester} berhasil disusun. Jangkar manual yang dikunci tetap dipertahankan.";
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first() ?? 'Penyusunan otomatis gagal.';
        }
    }

    public function validateSemester(RppPlanner $planner): void
    {
        $this->resetMessages();
        $valid = $planner->validate($this->plan());
        $this->notice = $valid
            ? "RPP Semester {$this->semester} tervalidasi."
            : 'Validasi ditahan: cakupan atau target progres belum lengkap.';
    }

    public function render(
        RppProgressService $progress,
        RppMatrixPresetService $presets,
        RppMatrixService $matrix,
        RppSchedulePatternService $patterns,
        RppMaterialCatalogService $catalog,
    ) {
        $presets->syncLevel($this->level());
        $plan = $this->plan()->load([
            'level',
            'academicYear.weeks' => fn ($query) => $query->where('semester', $this->semester)->orderBy('week_number'),
            'items.week',
            'items.matrixColumn',
            'items.syllabusItem.document',
            'items.syllabusItem.ggbItems.document',
            'items.materials.ggbItem.document',
            'items.materials.ggbItem.syllabusItems.document',
            'items.materials.syllabusItem.document',
            'progressTargets.syllabusItem.document',
        ]);
        $matrix->ensureMonthFocuses($plan);
        $plan->load('monthFocuses');
        $weeks = $plan->academicYear->weeks;
        $monthOrdinals = [];
        foreach ($weeks as $week) {
            $key = $week->starts_on->format('Y-m');
            $monthOrdinals[$key] = ($monthOrdinals[$key] ?? 0) + 1;
            $week->setAttribute('month_ordinal', $monthOrdinals[$key]);
        }
        $columns = $matrix->columns($plan);
        $itemsByCell = $matrix->itemsByCell($plan);
        $targets = $plan->progressTargets->map(function (RppProgressTarget $target) use ($progress) {
            $target->setAttribute('summary', $progress->progressSummary($target));

            return $target;
        });
        $eligible = SyllabusItem::query()
            ->where('level_id', $this->levelId)
            ->where('is_duplicate', false)
            ->whereIn('semester_scope', [(string) $this->semester, 'both'])
            ->orderBy('sort_order')
            ->get(['id', 'stable_code', 'title', 'semester_scope']);
        $annualTargets = $plan->level->code === 'PAUD'
            ? RppProgressTarget::query()->with('placements')->whereHas('plan', fn ($query) => $query->where('academic_year_id', $plan->academic_year_id)->where('level_id', $plan->level_id))->whereHas('syllabusItem', fn ($query) => $query->where('title', 'like', '%Tilawati%'))->get()
            : collect();
        $layoutColumns = $plan->level->matrixColumns()->withCount('mappings')->orderBy('sort_order')->orderBy('id')->get();
        $layoutMappings = $plan->level->syllabusItems()->where('is_duplicate', false)->with(['matrixMapping.column'])->orderBy('sort_order')->get();
        $unmappedCount = $layoutMappings->filter(fn ($item) => ! $item->matrixMapping)->count();
        $unmappedCatalog = RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)
            ->where(fn ($query) => $query->whereNull('rpp_matrix_column_id')->orWhere('mapping_status', 'unmapped'))
            ->with(['ggbItem', 'syllabusItem'])->orderBy('sort_order')->get();
        $patternIssues = $layoutMappings
            ->whereIn('semester_scope', [(string) $this->semester, 'both'])
            ->filter(fn ($item) => in_array($item->schedule_pattern, ['unknown', 'tentative'], true) && ! $plan->items->contains('syllabus_item_id', $item->id));
        $ggbCoverage = $catalog->coverage($plan);
        $pickerMaterials = collect();
        $pickerColumn = null;
        if ($this->pickerWeekId && $this->pickerColumnId) {
            $pickerColumn = $columns->firstWhere('id', $this->pickerColumnId);
            $pickerQuery = RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)
                ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
                ->with([
                    'ggbItem.document',
                    'ggbItem.syllabusItems.document',
                    'syllabusItem.document',
                    'placements' => fn ($query) => $query->where('rpp_plan_id', $plan->id)->with('week'),
                ]);
            if ($this->pickerStatus === 'unmapped') {
                $pickerQuery->where(fn ($query) => $query->whereNull('rpp_matrix_column_id')->orWhere('mapping_status', 'unmapped'));
            } else {
                $pickerQuery->where('rpp_matrix_column_id', $this->pickerColumnId);
            }
            if (filled($this->pickerSearch)) {
                $needle = '%'.trim($this->pickerSearch).'%';
                $pickerQuery->where(fn ($query) => $query->where('display_code', 'like', $needle)->orWhere('title', 'like', $needle));
            }
            if ($this->pickerStatus === 'unused') {
                $pickerQuery->whereDoesntHave('placements', fn ($query) => $query->where('rpp_plan_id', $plan->id));
            } elseif ($this->pickerStatus === 'used') {
                $pickerQuery->whereHas('placements', fn ($query) => $query->where('rpp_plan_id', $plan->id));
            } elseif ($this->pickerStatus === 'week') {
                $pickerQuery->whereHas('placements', fn ($query) => $query->where('rpp_plan_id', $plan->id)->where('calendar_week_id', $this->pickerWeekId));
            }
            $pickerMaterials = $pickerQuery->orderBy('sort_order')->limit(150)->get();
        }

        return view('livewire.export-preview', [
            'levels' => Level::query()->orderBy('sort_order')->get(),
            'plan' => $plan,
            'weeks' => $weeks,
            'columns' => $columns,
            'headerTree' => $catalog->headerTree($columns),
            'itemsByCell' => $itemsByCell,
            'monthRows' => $matrix->monthRows($weeks, $plan->monthFocuses),
            'trimesterChunks' => $weeks->chunk(13)->values(),
            'layoutColumns' => $layoutColumns,
            'layoutMappings' => $layoutMappings,
            'patternIssues' => $patternIssues,
            'patternLabels' => collect(RppSchedulePatternService::PATTERNS)->mapWithKeys(fn ($pattern) => [$pattern => $patterns->label($pattern)]),
            'targets' => $targets,
            'eligibleMaterials' => $eligible,
            'effectiveWeeks' => $weeks->where('is_effective', true)->values(),
            'targetTotal' => $targets->sum(fn ($target) => $target->summary['total']),
            'targetAchieved' => $targets->sum(fn ($target) => $target->summary['achieved']),
            'annualTargetTotal' => $annualTargets->sum(fn ($target) => $progress->progressSummary($target)['total']),
            'annualTargetAchieved' => $annualTargets->sum(fn ($target) => $progress->progressSummary($target)['achieved']),
            'conflictCount' => $unmappedCount + $unmappedCatalog->count() + $patternIssues->count(),
            'unmappedCount' => $unmappedCount,
            'unmappedCatalog' => $unmappedCatalog,
            'ggbCoverage' => $ggbCoverage,
            'pickerMaterials' => $pickerMaterials,
            'pickerColumn' => $pickerColumn,
        ]);
    }

    private function plan(): RppPlan
    {
        $year = AcademicYear::query()->where('is_active', true)->firstOrFail();

        return RppPlan::query()->firstOrCreate(
            ['academic_year_id' => $year->id, 'level_id' => $this->levelId, 'semester' => $this->semester],
            ['status' => 'draft']
        );
    }

    private function assertLevel(): void
    {
        abort_unless($this->levelId && Level::query()->whereKey($this->levelId)->exists(), 404);
    }

    private function level(): Level
    {
        return Level::query()->findOrFail($this->levelId);
    }

    private function resetTargetForm(): void
    {
        $this->reset(['targetSyllabusId', 'targetStart', 'targetEnd', 'targetReason']);
        $this->targetUnit = 'halaman';
        $this->targetStrategy = 'even';
        $this->targetVersion = 0;
    }

    private function resetMessages(): void
    {
        $this->notice = '';
        $this->errorMessage = '';
        $this->resetErrorBag();
    }
}
