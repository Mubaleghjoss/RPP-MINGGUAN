<?php

namespace App\Livewire;

use App\Models\GgbItem;
use App\Models\GgbSyllabusLink;
use App\Models\Level;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use App\Services\AcademicCalendarService;
use App\Services\CurriculumRevisionService;
use Illuminate\Pagination\LengthAwarePaginator;
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
#[Title('Editor Kurikulum')]
class CurriculumEditor extends Component
{
    use WithPagination;

    public Level $level;

    #[Url]
    public string $tab = 'ggb';

    #[Url]
    public string $search = '';

    #[Url]
    public string $filter = '';

    #[Url]
    public int $semester = 1;

    public string $sortField = 'sort_order';

    public string $sortDirection = 'asc';

    public string $notice = '';

    public string $errorMessage = '';

    public string $newGgbCode = '';

    public string $newSyllabusCode = '';

    public string $newRelationStatus = 'perlu_verifikasi';

    public string $newRelationNotes = '';

    public string $relationReason = '';

    public function mount(Level $level): void
    {
        $this->level = $level;
        abort_unless(in_array($this->tab, ['ggb', 'syllabus', 'link', 'rpp'], true), 404);
        abort_unless(in_array($this->semester, [1, 2], true), 404);
    }

    public function setTab(string $tab): void
    {
        abort_unless(in_array($tab, ['ggb', 'syllabus', 'link', 'rpp'], true), 422);
        $this->tab = $tab;
        $this->search = '';
        $this->filter = '';
        $this->sortField = 'sort_order';
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowed = $this->sortableFields();
        abort_unless(in_array($field, $allowed, true), 422);
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function savePatches(array $patches, string $reason, CurriculumRevisionService $revisions): array
    {
        $this->resetErrorBag();
        $this->errorMessage = '';
        try {
            foreach ($patches as $patch) {
                abort_unless(($patch['domain'] ?? null) === $this->tab, 422);
                $this->assertRowBelongsToLevel($this->tab, (int) ($patch['id'] ?? 0));
            }
            $batch = $revisions->applyBatch($patches, $reason, Auth::user());
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

    public function addRelation(CurriculumRevisionService $revisions): void
    {
        $this->resetErrorBag();
        try {
            $batch = $revisions->addLink(
                $this->level, $this->newGgbCode, $this->newSyllabusCode,
                $this->newRelationStatus, $this->newRelationNotes ?: null,
                $this->relationReason, Auth::user()
            );
            $this->notice = "Relasi ditambahkan dalam revisi {$batch->uuid}.";
            $this->reset(['newGgbCode', 'newSyllabusCode', 'newRelationNotes', 'relationReason']);
            $this->newRelationStatus = 'perlu_verifikasi';
            $this->resetPage();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }
        }
    }

    public function deleteRelation(int $linkId, CurriculumRevisionService $revisions): void
    {
        $this->resetErrorBag();
        $link = GgbSyllabusLink::query()->whereHas('syllabusItem', fn ($query) => $query->where('level_id', $this->level->id))->findOrFail($linkId);
        try {
            $batch = $revisions->deleteLink($link, $this->relationReason, Auth::user());
            $this->notice = "Relasi diarsipkan dalam revisi {$batch->uuid}.";
            $this->relationReason = '';
        } catch (ValidationException $exception) {
            $this->addError('relationReason', collect($exception->errors())->flatten()->first());
        }
    }

    public function render()
    {
        $plan = $this->level->plans()
            ->whereHas('academicYear', fn ($query) => $query->where('is_active', true))
            ->where('semester', $this->semester)
            ->with('academicYear.weeks')
            ->first();

        return view('livewire.curriculum-editor', [
            'rows' => $this->rows(),
            'effectiveWeeks' => $plan ? app(AcademicCalendarService::class)->weeksForPlan($plan, true) : collect(),
        ]);
    }

    private function rows(): LengthAwarePaginator
    {
        $term = '%'.trim($this->search).'%';
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return match ($this->tab) {
            'ggb' => $this->level->ggbItems()->with('document')
                ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q->where('stable_code', 'like', $term)->orWhere('aspect', 'like', $term)->orWhere('subaspect', 'like', $term)->orWhere('title', 'like', $term)))
                ->when($this->filter !== '', fn ($query) => $query->where('kind', $this->filter))
                ->orderBy(in_array($this->sortField, $this->sortableFields(), true) ? $this->sortField : 'sort_order', $direction)->paginate(100),
            'syllabus' => $this->level->syllabusItems()->with('document')
                ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q->where('stable_code', 'like', $term)->orWhere('category', 'like', $term)->orWhere('title', 'like', $term)->orWhere('description', 'like', $term)))
                ->when($this->filter === 'allocation', fn ($query) => $query->where('needs_allocation', true)->where('is_duplicate', false))
                ->when($this->filter === 'duplicate', fn ($query) => $query->where('is_duplicate', true))
                ->when(in_array($this->filter, ['1', '2', 'both'], true), fn ($query) => $query->where('semester_scope', $this->filter))
                ->orderBy(in_array($this->sortField, $this->sortableFields(), true) ? $this->sortField : 'sort_order', $direction)->paginate(100),
            'link' => GgbSyllabusLink::query()->with(['ggbItem:id,stable_code,title', 'syllabusItem:id,level_id,stable_code,title'])
                ->whereHas('syllabusItem', fn ($query) => $query->where('level_id', $this->level->id))
                ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q->where('notes', 'like', $term)->orWhereHas('ggbItem', fn ($g) => $g->where('stable_code', 'like', $term)->orWhere('title', 'like', $term))->orWhereHas('syllabusItem', fn ($s) => $s->where('stable_code', 'like', $term)->orWhere('title', 'like', $term))))
                ->when($this->filter !== '', fn ($query) => $query->where('status', $this->filter))
                ->orderBy(in_array($this->sortField, $this->sortableFields(), true) ? $this->sortField : 'id', $direction)->paginate(100),
            'rpp' => RppWeekItem::query()->with(['week', 'syllabusItem:id,stable_code'])
                ->whereHas('plan', fn ($query) => $query->where('level_id', $this->level->id)->where('semester', $this->semester)->whereHas('academicYear', fn ($year) => $year->where('is_active', true)))
                ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q->where('strand', 'like', $term)->orWhere('content', 'like', $term)))
                ->when($this->filter === 'locked', fn ($query) => $query->where('is_locked', true))
                ->when($this->filter === 'auto', fn ($query) => $query->where('source', 'auto'))
                ->orderBy(in_array($this->sortField, $this->sortableFields(), true) ? $this->sortField : 'calendar_week_id', $direction)->paginate(100),
            default => abort(404),
        };
    }

    private function sortableFields(): array
    {
        return match ($this->tab) {
            'ggb' => ['sort_order', 'stable_code', 'aspect', 'subaspect', 'title'],
            'syllabus' => ['sort_order', 'stable_code', 'category', 'title', 'recommended_sessions', 'semester_scope'],
            'link' => ['id', 'status', 'confidence'],
            'rpp' => ['calendar_week_id', 'strand', 'position', 'source'],
            default => [],
        };
    }

    private function assertRowBelongsToLevel(string $domain, int $id): void
    {
        $valid = match ($domain) {
            'ggb' => GgbItem::query()->whereKey($id)->where('level_id', $this->level->id)->exists(),
            'syllabus' => SyllabusItem::query()->whereKey($id)->where('level_id', $this->level->id)->exists(),
            'link' => GgbSyllabusLink::query()->whereKey($id)->whereHas('syllabusItem', fn ($query) => $query->where('level_id', $this->level->id))->exists(),
            'rpp' => RppWeekItem::query()->whereKey($id)->whereHas('plan', fn ($query) => $query->where('level_id', $this->level->id)->where('semester', $this->semester))->exists(),
            default => false,
        };
        abort_unless($valid, 404);
    }
}
