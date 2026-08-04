<?php

namespace App\Livewire;

use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\Level;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppMatrixColumn;
use App\Models\RppMatrixMapping;
use App\Models\RppMonthFocus;
use App\Models\RppPlan;
use App\Models\RppProgressTarget;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use App\Services\AcademicCalendarService;
use App\Services\CurriculumRevisionService;
use App\Services\RppAnnualGgbService;
use App\Services\RppCompletionService;
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
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

#[Layout('layouts.app')]
#[Title('Preview dan Ekspor RPP')]
class ExportPreview extends Component
{
    use WithPagination;

    #[Url(as: 'level')]
    public ?int $levelId = null;

    #[Url]
    public int $semester = 1;

    #[Url]
    public string $detail = '';

    #[Url(as: 'ggb_status')]
    public string $ggbStatus = 'all';

    #[Url(as: 'ggb_search')]
    public string $ggbSearch = '';

    public array $selectedGgb = [];

    public ?int $ggbSemester = null;

    public ?int $ggbColumnId = null;

    public string $ggbReason = '';

    public bool $ggbConfirmSuggestedMappings = false;

    public string $semesterOneStart = '';

    public string $semesterOneEnd = '';

    public string $semesterTwoStart = '';

    public string $semesterTwoEnd = '';

    public ?int $calendarEventId = null;

    public string $calendarType = 'holiday';

    public string $calendarTitle = '';

    public string $calendarDetails = '';

    public string $calendarStartsOn = '';

    public string $calendarEndsOn = '';

    public bool $calendarAllLevels = true;

    public array $calendarLevelIds = [];

    public bool $calendarConfirmImpact = false;

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
        if (! in_array($this->detail, ['', 'ggb', 'calendar'], true)) {
            $this->detail = '';
        }
        $this->hydrateSemesterRanges();
    }

    public function updatedLevelId(): void
    {
        $this->assertLevel();
        $this->resetTargetForm();
        $this->resetMessages();
        $this->closeMaterialPicker();
        $this->selectedGgb = [];
        $this->ggbConfirmSuggestedMappings = false;
        $this->resetPage('ggbPage');
    }

    public function selectSemester(int $semester): void
    {
        abort_unless(in_array($semester, [1, 2], true), 422);
        $this->semester = $semester;
        $this->resetTargetForm();
        $this->resetMessages();
        $this->closeMaterialPicker();
    }

    public function showDetail(string $detail): void
    {
        abort_unless(in_array($detail, ['', 'ggb', 'calendar'], true), 422);
        $this->detail = $detail;
        $this->selectedGgb = [];
        $this->resetMessages();
        $this->resetPage('ggbPage');
    }

    public function updatedGgbSearch(): void
    {
        $this->resetPage('ggbPage');
    }

    public function updatedGgbStatus(): void
    {
        $this->selectedGgb = [];
        $this->resetPage('ggbPage');
    }

    public function selectVisibleGgb(array $ids): void
    {
        $allowed = RppMaterialCatalogItem::query()->where('level_id', $this->levelId)->where('source_kind', 'ggb')
            ->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id);
        $this->selectedGgb = collect($this->selectedGgb)->merge($allowed)->unique()->values()->all();
    }

    public function clearGgbSelection(): void
    {
        $this->selectedGgb = [];
    }

    public function confirmGgb(RppAnnualGgbService $service): void
    {
        $this->resetMessages();
        try {
            $count = $service->confirm($this->level(), $this->selectedGgb, $this->ggbSemester, $this->ggbColumnId, $this->ggbReason, Auth::id());
            $this->notice = "{$count} materi GGB dikonfirmasi. Materi siap dapat dimasukkan melalui Lengkapi GGB 1 Tahun.";
            $this->selectedGgb = [];
            $this->ggbSemester = null;
            $this->ggbColumnId = null;
            $this->ggbReason = '';
        } catch (ValidationException $exception) {
            $this->applyValidationException($exception, [
                'ids' => 'selectedGgb', 'selection' => 'selectedGgb', 'reason' => 'ggbReason',
                'semester' => 'ggbSemester', 'columnId' => 'ggbColumnId', 'column' => 'ggbColumnId',
            ], 'Konfirmasi GGB gagal.');
        }
    }

    public function balancePaudGgb(RppAnnualGgbService $service): void
    {
        $this->resetMessages();
        abort_unless($this->level()->code === 'PAUD', 422);
        try {
            $result = $service->confirmGeneralBalanced(
                $this->level(),
                $this->ggbReason,
                $this->ggbConfirmSuggestedMappings,
                Auth::id(),
            );
            $this->notice = $result['changed'] > 0
                ? "{$result['changed']} materi dikonfirmasi: Semester 1 {$result['semester_1']} dan Semester 2 {$result['semester_2']}. Lanjutkan dengan Lengkapi GGB 1 Tahun."
                : 'Pembagian semester sudah lengkap dan tidak memerlukan perubahan.';
            $this->ggbReason = '';
            $this->ggbConfirmSuggestedMappings = false;
        } catch (ValidationException $exception) {
            $this->applyValidationException($exception, [
                'reason' => 'ggbReason', 'confirm_mapping' => 'ggbConfirmSuggestedMappings', 'mapping' => 'ggbColumnId',
            ], 'Pembagian GGB PAUD gagal.');
        }
    }

    public function completeAnnualGgb(RppAnnualGgbService $service): void
    {
        $this->resetMessages();
        try {
            $result = $service->enableReadyAndSchedule($this->year(), $this->level(), $this->ggbReason, Auth::id());
            $this->notice = "{$result['scheduled']} materi GGB ditambahkan. Cakupan tahunan sekarang {$result['coverage']['percent']}%.";
            $this->ggbReason = '';
            $this->selectedGgb = [];
        } catch (ValidationException $exception) {
            $this->applyValidationException($exception, ['reason' => 'ggbReason'], 'Bulk GGB gagal.');
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'Bulk GGB gagal. Tidak ada perubahan yang diterapkan.';
        }
    }

    public function validateAnnualGgb(RppAnnualGgbService $service): void
    {
        $this->resetMessages();
        $valid = $service->validateAnnual($this->year(), $this->level(), Auth::id());
        if ($valid) {
            $this->notice = 'Cakupan GGB satu tahun tervalidasi 100%.';
        } else {
            $coverage = app(RppMaterialCatalogService::class)->coverage($this->plan());
            $this->errorMessage = "Validasi GGB ditahan: {$coverage['missing']} dari {$coverage['total']} materi belum masuk RPP. Jalankan Lengkapi GGB 1 Tahun terlebih dahulu.";
        }
    }

    public function saveSemesterRanges(AcademicCalendarService $calendar): void
    {
        $this->resetMessages();
        try {
            $calendar->saveSemesterRanges($this->year(), [
                'semester_1_start' => $this->semesterOneStart, 'semester_1_end' => $this->semesterOneEnd,
                'semester_2_start' => $this->semesterTwoStart, 'semester_2_end' => $this->semesterTwoEnd,
            ], Auth::id());
            $this->notice = 'Rentang Semester 1 dan 2 diperbarui. Jumlah minggu mengikuti tanggal Admin.';
            $this->hydrateSemesterRanges();
        } catch (ValidationException $exception) {
            $this->applyValidationException($exception, [
                'semester_1_start' => 'semesterOneStart', 'semester_1_end' => 'semesterOneEnd',
                'semester_2_start' => 'semesterTwoStart', 'semester_2_end' => 'semesterTwoEnd',
                'semester' => 'semesterOneStart',
            ], 'Rentang semester tidak dapat disimpan.');
        }
    }

    public function editCalendarEvent(int $eventId): void
    {
        $event = CalendarEvent::query()->where('academic_year_id', $this->year()->id)->findOrFail($eventId);
        $this->calendarEventId = $event->id;
        $this->calendarType = $event->type;
        $this->calendarTitle = $event->title;
        $this->calendarDetails = (string) $event->details;
        $this->calendarStartsOn = $event->starts_on->toDateString();
        $this->calendarEndsOn = $event->ends_on->toDateString();
        $this->calendarAllLevels = $event->applies_to_all;
        $this->calendarLevelIds = $event->levels()->pluck('levels.id')->map(fn ($id) => (string) $id)->all();
        $this->calendarConfirmImpact = false;
    }

    public function saveCalendarEvent(AcademicCalendarService $calendar): void
    {
        $this->resetMessages();
        try {
            $calendar->saveEvent($this->year(), $this->calendarPayload(), Auth::id(), $this->calendarEventId);
            $this->notice = 'Rentang kalender disimpan dan RPP terdampak digeser ke minggu efektif berikutnya.';
            $this->resetCalendarForm();
        } catch (ValidationException $exception) {
            $this->applyValidationException($exception, [
                'type' => 'calendarType', 'title' => 'calendarTitle', 'details' => 'calendarDetails',
                'starts_on' => 'calendarStartsOn', 'ends_on' => 'calendarEndsOn',
                'level_ids' => 'calendarLevelIds', 'confirm_impact' => 'calendarConfirmImpact', 'calendar' => 'calendarStartsOn',
            ], 'Rentang kalender tidak dapat disimpan.');
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'Rentang kalender gagal disimpan. Tidak ada perubahan yang diterapkan.';
        }
    }

    public function deleteCalendarEvent(int $eventId, AcademicCalendarService $calendar): void
    {
        $event = CalendarEvent::query()->where('academic_year_id', $this->year()->id)->findOrFail($eventId);
        $calendar->deleteEvent($event, Auth::id());
        $this->notice = 'Rentang dihapus. Minggu dibuka kembali; materi tidak ditarik mundur otomatis.';
        $this->resetCalendarForm();
    }

    public function resetCalendarForm(): void
    {
        $this->reset(['calendarEventId', 'calendarTitle', 'calendarDetails', 'calendarStartsOn', 'calendarEndsOn', 'calendarLevelIds', 'calendarConfirmImpact']);
        $this->calendarType = 'holiday';
        $this->calendarAllLevels = true;
    }

    public function openMaterialPicker(int $weekId, int $columnId): void
    {
        $plan = $this->plan();
        $week = $plan->academicYear->weeks()->whereKey($weekId)->where('semester', $plan->semester)->first();
        abort_unless($week && app(AcademicCalendarService::class)->isEffective($plan, $week), 422);
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
        if ($valid) {
            $this->notice = "RPP Semester {$this->semester} tervalidasi.";
        } else {
            $report = app(RppCompletionService::class)->report($this->year(), $this->level());
            $step = collect($report['steps'])->firstWhere('key', "semester_{$this->semester}");
            $this->errorMessage = 'Validasi Semester '.$this->semester.' ditahan. '.($step['summary'] ?? 'Cakupan atau target progres belum lengkap.');
        }
    }

    public function validatePaudSemester(int $semester, RppPlanner $planner): void
    {
        abort_unless($this->level()->code === 'PAUD' && in_array($semester, [1, 2], true), 422);
        $this->resetMessages();
        $plan = RppPlan::query()
            ->where('academic_year_id', $this->year()->id)
            ->where('level_id', $this->levelId)
            ->where('semester', $semester)
            ->firstOrFail();
        $valid = $planner->validate($plan);
        if ($valid) {
            $this->notice = "RPP PAUD Semester {$semester} tervalidasi.";

            return;
        }

        $report = app(RppCompletionService::class)->report($this->year(), $this->level());
        $step = collect($report['steps'])->firstWhere('key', "semester_{$semester}");
        $this->errorMessage = 'Validasi Semester '.$semester.' ditahan. '.($step['summary'] ?? 'Cakupan atau target progres belum lengkap.');
    }

    public function render(
        RppProgressService $progress,
        RppMatrixPresetService $presets,
        RppMatrixService $matrix,
        RppSchedulePatternService $patterns,
        RppMaterialCatalogService $catalog,
        AcademicCalendarService $calendar,
        RppAnnualGgbService $annualGgb,
        RppCompletionService $completion,
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
        $weeks = $calendar->weeksForPlan($plan);
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
        $annualValidation = $plan->academicYear->annualValidations()->where('level_id', $plan->level_id)->first();
        $completionReport = $plan->level->code === 'PAUD' ? $completion->report($plan->academicYear, $plan->level) : null;
        $balancedPreview = $plan->level->code === 'PAUD' ? $annualGgb->balancedPreview($plan->level) : null;
        $ggbItems = null;
        if ($this->detail === 'ggb') {
            $usedScope = fn ($query) => $query->where('academic_year_id', $plan->academic_year_id)->where('level_id', $plan->level_id);
            $ggbQuery = RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)->where('source_kind', 'ggb')
                ->with(['ggbItem.document', 'matrixColumn', 'placements.plan', 'placements.week']);
            if (filled($this->ggbSearch)) {
                $needle = '%'.trim($this->ggbSearch).'%';
                $ggbQuery->where(fn ($query) => $query->where('display_code', 'like', $needle)->orWhere('title', 'like', $needle)
                    ->orWhereHas('ggbItem', fn ($ggb) => $ggb->where('stable_code', 'like', $needle)));
            }
            match ($this->ggbStatus) {
                'used' => $ggbQuery->whereHas('placements.plan', $usedScope),
                'ready' => $ggbQuery->whereDoesntHave('placements.plan', $usedScope)->where('mapping_status', 'mapped')
                    ->whereNotNull('rpp_matrix_column_id')->whereIn('semester_scope', ['1', '2'])->where('semester_confirmed', true),
                'semester' => $ggbQuery->where('source_semester_scope', 'general')->where('semester_confirmed', false),
                'mapping' => $ggbQuery->where(fn ($query) => $query->whereNull('rpp_matrix_column_id')->orWhere('mapping_status', '!=', 'mapped')),
                'conflict' => $ggbQuery->where('source_semester_scope', 'general')->where('semester_confirmed', false)
                    ->where(fn ($query) => $query->whereNull('rpp_matrix_column_id')->orWhere('mapping_status', '!=', 'mapped')),
                default => null,
            };
            $ggbItems = $ggbQuery->orderBy('sort_order')->paginate(50, ['*'], 'ggbPage');
        }
        $calendarPreview = $this->detail === 'calendar'
            ? $calendar->previewEvent($plan->academicYear, $this->calendarPayload(), $this->calendarEventId)
            : null;
        $pickerMaterials = collect();
        $pickerColumn = null;
        if ($this->pickerWeekId && $this->pickerColumnId) {
            $pickerColumn = $columns->firstWhere('id', $this->pickerColumnId);
            $pickerQuery = RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)
                ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
                ->where(fn ($query) => $query->where('source_semester_scope', '!=', 'general')->orWhere('semester_confirmed', true))
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
            'trimesterChunks' => $weeks->chunk(max(1, (int) ceil($weeks->count() / 2)))->values(),
            'layoutColumns' => $layoutColumns,
            'layoutMappings' => $layoutMappings,
            'patternIssues' => $patternIssues,
            'patternLabels' => collect(RppSchedulePatternService::PATTERNS)->mapWithKeys(fn ($pattern) => [$pattern => $patterns->label($pattern)]),
            'targets' => $targets,
            'eligibleMaterials' => $eligible,
            'effectiveWeeks' => $weeks->where('resolved_is_effective', true)->values(),
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
            'ggbItems' => $ggbItems,
            'annualValidation' => $annualValidation,
            'calendarEvents' => $plan->academicYear->calendarEvents()->with('levels:id,name,code')->orderBy('starts_on')->get(),
            'calendarPreview' => $calendarPreview,
            'completionReport' => $completionReport,
            'balancedPreview' => $balancedPreview,
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

    private function year(): AcademicYear
    {
        return AcademicYear::query()->where('is_active', true)->firstOrFail();
    }

    private function hydrateSemesterRanges(): void
    {
        $calendar = app(AcademicCalendarService::class);
        $year = $this->year();
        $one = $calendar->semester($year, 1);
        $two = $calendar->semester($year, 2);
        $this->semesterOneStart = $one->starts_on->toDateString();
        $this->semesterOneEnd = $one->ends_on->toDateString();
        $this->semesterTwoStart = $two->starts_on->toDateString();
        $this->semesterTwoEnd = $two->ends_on->toDateString();
    }

    private function calendarPayload(): array
    {
        return [
            'type' => $this->calendarType, 'title' => $this->calendarTitle, 'details' => $this->calendarDetails,
            'starts_on' => $this->calendarStartsOn, 'ends_on' => $this->calendarEndsOn,
            'applies_to_all' => $this->calendarAllLevels, 'level_ids' => $this->calendarLevelIds,
            'confirm_impact' => $this->calendarConfirmImpact,
        ];
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

    private function applyValidationException(ValidationException $exception, array $fieldMap = [], string $fallback = 'Data tidak valid.'): void
    {
        $firstField = null;
        foreach ($exception->errors() as $field => $messages) {
            $componentField = $fieldMap[$field] ?? $field;
            $message = $messages[0] ?? $fallback;
            $this->addError($componentField, $message);
            $firstField ??= $componentField;
        }
        $this->errorMessage = collect($exception->errors())->flatten()->first() ?? $fallback;
        if ($firstField) {
            $this->dispatch('focus-validation-field', field: $firstField);
        }
    }
}
