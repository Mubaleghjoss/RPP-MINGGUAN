<?php

namespace App\Services;

use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RppPlanner
{
    public function __construct(
        private readonly RppProgressService $progress,
        private readonly RppMatrixPresetService $presets,
        private readonly RppSchedulePatternService $patterns,
        private readonly RppMatrixService $matrix,
        private readonly RppMaterialCatalogService $catalog,
        private readonly AcademicCalendarService $calendar,
        private readonly RppAnnualGgbService $annualGgb,
        private readonly RppMatrixFillService $matrixFill,
    ) {}

    public function scheduleOne(RppPlan $plan, int $syllabusItemId, ?int $userId): RppWeekItem
    {
        return DB::transaction(function () use ($plan, $syllabusItemId, $userId) {
            $lockedPlan = RppPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $weeks = $this->calendar->weeksForPlan($lockedPlan, true);

            if ($weeks->isEmpty()) {
                throw ValidationException::withMessages(['week' => 'Tidak ada minggu efektif yang tersedia pada tahun ajaran ini.']);
            }

            $item = SyllabusItem::query()->lockForUpdate()->find($syllabusItemId);
            if (! $item || (int) $item->level_id !== (int) $lockedPlan->level_id) {
                throw ValidationException::withMessages(['material' => 'Materi bukan milik jenjang RPP ini.']);
            }
            if ($item->is_duplicate) {
                throw ValidationException::withMessages(['material' => 'Materi duplikat tidak dapat dijadwalkan.']);
            }
            if ($item->is_source_artifact) {
                throw ValidationException::withMessages(['material' => 'Baris ini adalah artefak header dokumen sumber dan tidak dapat dijadwalkan.']);
            }
            if ($item->needs_allocation || blank($item->allocation_text) || (int) $item->recommended_sessions < 1) {
                throw ValidationException::withMessages(['material' => 'Lengkapi alokasi dan jumlah pertemuan minimal 1 sebelum menjadwalkan materi.']);
            }
            if (! in_array($item->semester_scope, [(string) $lockedPlan->semester, 'both'], true)) {
                throw ValidationException::withMessages(['material' => "Materi bukan bagian dari Semester {$lockedPlan->semester}."]);
            }
            if ($lockedPlan->progressTargets()->where('syllabus_item_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['material' => 'Materi ini menggunakan target progres. Gunakan Susun Otomatis agar rentangnya tetap berurutan.']);
            }
            if (RppWeekItem::query()->where('rpp_plan_id', $lockedPlan->id)->where('syllabus_item_id', $item->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['material' => 'Materi ini sudah dijadwalkan. Muat ulang halaman untuk melihat jadwal terbaru.']);
            }

            $this->presets->syncLevel($lockedPlan->level);
            $item->load('matrixMapping.column');
            $column = $item->matrixMapping?->column;
            if (! $column || ! $column->is_active) {
                throw ValidationException::withMessages(['material' => 'Materi belum dipetakan ke kolom matriks yang aktif.']);
            }

            $strand = $column->label;
            $siblings = SyllabusItem::query()
                ->where('level_id', $lockedPlan->level_id)
                ->where('is_duplicate', false)
                ->where('is_source_artifact', false)
                ->where('needs_allocation', false)
                ->whereIn('semester_scope', [(string) $lockedPlan->semester, 'both'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (SyllabusItem $candidate) => (trim((string) $candidate->category) ?: 'Materi') === $strand
                    && filled($candidate->allocation_text)
                    && (int) $candidate->recommended_sessions >= 1
                )
                ->values();
            $index = $siblings->search(fn (SyllabusItem $candidate) => $candidate->is($item));

            if ($index === false) {
                throw ValidationException::withMessages(['material' => 'Materi tidak lagi memenuhi syarat penjadwalan. Muat ulang halaman.']);
            }

            $weekIndex = min($weeks->count() - 1, (int) floor(($index * $weeks->count()) / max(1, $siblings->count())));
            $week = $weeks[$weekIndex];
            $position = (int) RppWeekItem::query()
                ->where('rpp_plan_id', $lockedPlan->id)
                ->where('calendar_week_id', $week->id)
                ->max('position');

            $placement = RppWeekItem::query()->create([
                'rpp_plan_id' => $lockedPlan->id,
                'calendar_week_id' => $week->id,
                'syllabus_item_id' => $item->id,
                'source_fingerprint' => 'syllabus:'.$item->id,
                'occurrence_no' => 1,
                'rpp_matrix_column_id' => $column->id,
                'strand' => $strand,
                'content' => $item->title,
                'source' => 'auto',
                'is_locked' => false,
                'position' => $position + 1,
                'lock_version' => 0,
                'last_edited_by' => $userId,
            ]);
            $this->catalog->attachPlacement($placement);

            $this->refreshCoverage($lockedPlan);
            $lockedPlan->update(['status' => 'draft', 'validated_at' => null]);
            DB::table('activity_logs')->insert([
                'user_id' => $userId,
                'action' => 'rpp.item_scheduled_auto',
                'details' => json_encode([
                    'plan_id' => $lockedPlan->id,
                    'level_id' => $lockedPlan->level_id,
                    'syllabus_item_id' => $item->id,
                    'calendar_week_id' => $week->id,
                    'algorithm' => 'category_distribution',
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $placement->fresh('week');
        });
    }

    public function generate(RppPlan $plan, bool $syncCatalog = true): RppPlan
    {
        return DB::transaction(function () use ($plan, $syncCatalog) {
            // Selalu muat ulang agar perubahan manual/kunci dari request lain
            // menjadi sumber kebenaran saat penyusunan ulang.
            if ($syncCatalog) {
                $this->catalog->syncLevel($plan->level);
            }
            $plan->load(['level.syllabusItems.matrixMapping.column', 'level.syllabusItems.ggbItems', 'academicYear.weeks', 'items.week', 'progressTargets.syllabusItem']);
            $this->progress->ensureDefaults($plan);
            $plan->load('progressTargets.syllabusItem');
            $weeks = $this->calendar->weeksForPlan($plan, true);
            if ($weeks->isEmpty()) {
                if ($plan->progressTargets->isNotEmpty()) {
                    throw ValidationException::withMessages(['progress' => "Semester {$plan->semester} tidak memiliki minggu efektif untuk target progres."]);
                }

                return $plan;
            }

            $plan->items()->where('source', 'auto')->where('is_locked', false)->delete();

            foreach ($plan->progressTargets as $target) {
                $this->progress->generateTarget($plan, $target, $weeks);
            }

            $progressSyllabusIds = $plan->progressTargets->pluck('syllabus_item_id')->all();

            $groups = $plan->level->syllabusItems
                ->whereNotIn('id', $progressSyllabusIds)
                ->where('is_duplicate', false)
                ->where('is_source_artifact', false)
                ->where('needs_allocation', false)
                ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
                ->filter(fn (SyllabusItem $item) => filled($item->allocation_text) && (int) $item->recommended_sessions >= 1 && $item->matrixMapping?->column?->is_active)
                ->sortBy('sort_order')
                ->groupBy(fn ($item) => $item->matrixMapping->rpp_matrix_column_id);

            $allSemesterWeeks = $this->calendar->weeksForPlan($plan);
            foreach ($groups as $columnId => $columnItems) {
                $column = $columnItems->first()->matrixMapping->column;
                foreach ($columnItems->groupBy(fn ($item) => $item->schedule_pattern ?: $this->patterns->detect($item->allocation_text)) as $pattern => $items) {
                    $slots = $this->patterns->slots($pattern, $allSemesterWeeks);
                    if ($slots->isEmpty()) {
                        continue;
                    }
                    $this->distribute($plan, $column, $items->values(), $slots);
                }
            }

            $this->annualGgb->rebuildForPlan($plan);
            $this->matrixFill->fill($plan);

            $this->refreshCoverage($plan);
            $plan->update(['status' => 'draft', 'validated_at' => null]);
            $plan->unsetRelation('items');
            $this->matrix->ensureMonthFocuses($plan->fresh(['items.matrixColumn', 'academicYear.weeks']));

            return $plan->fresh(['items', 'level', 'academicYear.weeks']);
        });
    }

    private function distribute(RppPlan $plan, $column, $items, $slots): void
    {
        $assignments = collect();
        $itemCount = $items->count();
        $slotCount = $slots->count();
        if ($itemCount <= $slotCount) {
            foreach ($slots as $index => $week) {
                $item = $items[min($itemCount - 1, (int) floor(($index * $itemCount) / $slotCount))];
                $assignments->push(compact('week', 'item'));
            }
        } else {
            foreach ($items as $index => $item) {
                $week = $slots[min($slotCount - 1, (int) floor(($index * $slotCount) / $itemCount))];
                $assignments->push(compact('week', 'item'));
            }
        }

        foreach ($assignments->groupBy(fn ($assignment) => $assignment['item']->id) as $syllabusId => $itemAssignments) {
            $item = $itemAssignments->first()['item'];
            $locked = $plan->items->where('syllabus_item_id', $syllabusId)->where('is_locked', true)->values();
            $remaining = $itemAssignments->values();
            foreach ($locked as $anchor) {
                $exact = $remaining->search(fn ($assignment) => (int) $assignment['week']->id === (int) $anchor->calendar_week_id);
                if ($exact !== false) {
                    $remaining->forget($exact);
                } elseif ($remaining->isNotEmpty()) {
                    $closest = $remaining->sortBy(fn ($assignment) => abs($assignment['week']->week_number - $anchor->week->week_number))->keys()->first();
                    $remaining->forget($closest);
                }
            }
            $remaining = $remaining->values();
            foreach ($remaining as $occurrence => $assignment) {
                $existingLocked = RppWeekItem::query()
                    ->where('rpp_plan_id', $plan->id)->where('calendar_week_id', $assignment['week']->id)
                    ->where('syllabus_item_id', $item->id)->where('is_locked', true)->exists();
                if ($existingLocked) {
                    continue;
                }
                $placement = RppWeekItem::query()->updateOrCreate(
                    [
                        'rpp_plan_id' => $plan->id,
                        'calendar_week_id' => $assignment['week']->id,
                        'syllabus_item_id' => $item->id,
                        'source_fingerprint' => 'syllabus:'.$item->id,
                        'occurrence_no' => 1,
                    ],
                    [
                        'rpp_matrix_column_id' => $column->id,
                        'strand' => $column->label,
                        'content' => $this->occurrenceContent($item, $occurrence, $remaining->count()),
                        'source' => 'auto', 'is_locked' => false, 'position' => $occurrence + 1,
                    ]
                );
                $this->catalog->attachPlacement($placement);
            }
        }
    }

    private function occurrenceContent(SyllabusItem $item, int $index, int $total): string
    {
        $segments = collect(preg_split('/\s*(?:;|\r?\n|,\s+)\s*/u', trim($item->title)))
            ->map(fn ($part) => trim($part, " \t\n\r\0\x0B.•"))->filter(fn ($part) => mb_strlen($part) >= 3)->values();
        if ($segments->count() <= 1) {
            return $item->title;
        }
        if ($segments->count() <= $total) {
            return $segments[min($segments->count() - 1, (int) floor(($index * $segments->count()) / max(1, $total)))];
        }
        $start = (int) floor(($index * $segments->count()) / max(1, $total));
        $end = max($start + 1, (int) floor((($index + 1) * $segments->count()) / max(1, $total)));

        return $segments->slice($start, $end - $start)->implode('; ');
    }

    public function generateAll(): void
    {
        $this->catalog->syncAll();
        $plans = RppPlan::query()->with(['level.syllabusItems', 'academicYear.weeks', 'items', 'progressTargets'])->get();
        $plans->each(fn (RppPlan $plan) => $this->generate($plan, false));
        // Pass kedua memungkinkan semester yang tidak mempunyai sumber sendiri
        // memakai materi semester pasangannya sebagai penguatan, tanpa mengubah
        // cakupan Silabus semester.
        $plans->each(fn (RppPlan $plan) => $this->matrixFill->fill($plan->fresh()));
    }

    public function validate(RppPlan $plan): bool
    {
        $this->refreshCoverage($plan);
        if ((float) $plan->coverage_percent < 100) {
            return false;
        }
        $plan->load('progressTargets');
        if ($plan->progressTargets->contains(fn ($target) => ! $this->progress->isComplete($target))) {
            return false;
        }
        if ($this->matrixFill->stats($plan)['missing'] > 0) {
            return false;
        }
        if ($plan->items()->whereHas('materials', fn ($query) => $query->where('is_schedulable', false))->exists()) {
            return false;
        }
        $plan->update(['status' => 'validated', 'validated_at' => now()]);

        return true;
    }

    public function refreshCoverage(RppPlan $plan): void
    {
        $total = $plan->level->syllabusItems()
            ->where('is_duplicate', false)
            ->where('is_source_artifact', false)
            ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
            ->count();
        $planned = $plan->items()->whereHas('syllabusItem', fn ($query) => $query
            ->where('is_duplicate', false)
            ->where('is_source_artifact', false)
            ->whereIn('semester_scope', [(string) $plan->semester, 'both']))
            ->distinct('syllabus_item_id')->count('syllabus_item_id');
        $plan->update(['coverage_percent' => $total ? round(($planned / $total) * 100, 2) : 0]);
        $plan->refresh();
    }
}
