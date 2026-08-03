<?php

namespace App\Livewire;

use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RppMatrixColumn;
use App\Models\RppMatrixMapping;
use App\Models\RppMonthFocus;
use App\Models\RppPlan;
use App\Models\RppProgressTarget;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use App\Services\CurriculumRevisionService;
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
    }

    public function selectSemester(int $semester): void
    {
        abort_unless(in_array($semester, [1, 2], true), 422);
        $this->semester = $semester;
        $this->resetTargetForm();
        $this->resetMessages();
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
    ) {
        $presets->syncLevel($this->level());
        $plan = $this->plan()->load([
            'level',
            'academicYear.weeks' => fn ($query) => $query->where('semester', $this->semester)->orderBy('week_number'),
            'items.week',
            'items.matrixColumn',
            'items.syllabusItem.document',
            'items.syllabusItem.ggbItems.document',
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
        $patternIssues = $layoutMappings
            ->whereIn('semester_scope', [(string) $this->semester, 'both'])
            ->filter(fn ($item) => in_array($item->schedule_pattern, ['unknown', 'tentative'], true) && ! $plan->items->contains('syllabus_item_id', $item->id));

        return view('livewire.export-preview', [
            'levels' => Level::query()->orderBy('sort_order')->get(),
            'plan' => $plan,
            'weeks' => $weeks,
            'columns' => $columns,
            'aspectGroups' => $matrix->headerGroups($columns, 'aspect_label'),
            'subaspectGroups' => $matrix->headerGroups($columns, 'subaspect_label'),
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
            'conflictCount' => $unmappedCount + $patternIssues->count(),
            'unmappedCount' => $unmappedCount,
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
