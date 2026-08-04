<?php

namespace App\Services;

use App\Models\RppPlan;
use App\Models\RppProgressTarget;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RppProgressService
{
    public function __construct(private readonly RppMaterialCatalogService $catalog) {}

    public function ensureDefaults(RppPlan $plan): void
    {
        $items = $plan->level->syllabusItems()
            ->where('is_duplicate', false)
            ->where('is_source_artifact', false)
            ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
            ->where(fn ($query) => $query->where('category', 'like', '%Tilawati%')->orWhere('title', 'like', 'Tilawati%'))
            ->get();

        foreach ($items as $item) {
            $range = $this->defaultRange($plan, $item);
            if (! $range) {
                continue;
            }
            RppProgressTarget::query()->firstOrCreate(
                ['rpp_plan_id' => $plan->id, 'syllabus_item_id' => $item->id],
                [
                    'unit_label' => 'halaman',
                    'range_start' => $range[0],
                    'range_end' => $range[1],
                    'strategy' => 'even',
                    'source' => 'auto',
                ]
            );
        }
    }

    public function generateTarget(RppPlan $plan, RppProgressTarget $target, Collection $weeks): void
    {
        if ($weeks->isEmpty()) {
            throw ValidationException::withMessages(['progress' => 'Target progres tidak dapat disusun karena semester ini tidak memiliki minggu efektif.']);
        }

        $target->loadMissing('syllabusItem');
        $target->syllabusItem->loadMissing('matrixMapping.column');
        $column = $target->syllabusItem->matrixMapping?->column;
        if (! $column || ! $column->is_active) {
            throw ValidationException::withMessages(['progress' => "Target {$target->syllabusItem->stable_code} belum dipetakan ke kolom matriks aktif."]);
        }
        if (! in_array($target->syllabusItem->semester_scope, [(string) $plan->semester, 'both'], true)) {
            throw ValidationException::withMessages(['progress' => "Target {$target->syllabusItem->stable_code} bukan bagian dari Semester {$plan->semester}. Ubah semester efektif atau nonaktifkan target terlebih dahulu."]);
        }
        $anchors = RppWeekItem::query()
            ->with('week')
            ->where('rpp_plan_id', $plan->id)
            ->where('syllabus_item_id', $target->syllabus_item_id)
            ->where('is_locked', true)
            ->orderBy('calendar_week_id')
            ->get()
            ->sortBy(fn (RppWeekItem $item) => $item->week->week_number)
            ->values();

        $this->validateAnchors($plan, $target, $anchors, $weeks);
        $weekIds = $weeks->pluck('id')->all();
        $anchorByWeek = $anchors->keyBy('calendar_week_id');
        $cursor = (int) $target->range_start;
        $pendingWeeks = collect();

        foreach ($weeks as $week) {
            $anchor = $anchorByWeek->get($week->id);
            if (! $anchor) {
                $pendingWeeks->push($week);

                continue;
            }

            $this->generateSegment($plan, $target, $column, $pendingWeeks, $cursor, (int) $anchor->progress_start - 1);
            $pendingWeeks = collect();
            $cursor = (int) $anchor->progress_end + 1;
        }

        $this->generateSegment($plan, $target, $column, $pendingWeeks, $cursor, (int) $target->range_end);

        RppWeekItem::query()
            ->where('rpp_plan_id', $plan->id)
            ->where('syllabus_item_id', $target->syllabus_item_id)
            ->whereNotIn('calendar_week_id', $weekIds)
            ->where('source', 'auto')
            ->where('is_locked', false)
            ->delete();
    }

    public function isComplete(RppProgressTarget $target): bool
    {
        $covered = [];
        $target->placements()
            ->where('progress_kind', 'materi_baru')
            ->get(['progress_start', 'progress_end'])
            ->each(function (RppWeekItem $item) use (&$covered) {
                for ($value = (int) $item->progress_start; $value <= (int) $item->progress_end; $value++) {
                    $covered[$value] = true;
                }
            });

        for ($value = (int) $target->range_start; $value <= (int) $target->range_end; $value++) {
            if (! isset($covered[$value])) {
                return false;
            }
        }

        return true;
    }

    public function progressSummary(RppProgressTarget $target): array
    {
        $covered = [];
        $target->placements()
            ->where('progress_kind', 'materi_baru')
            ->get(['progress_start', 'progress_end'])
            ->each(function (RppWeekItem $item) use (&$covered) {
                for ($value = (int) $item->progress_start; $value <= (int) $item->progress_end; $value++) {
                    $covered[$value] = true;
                }
            });
        $total = (int) $target->range_end - (int) $target->range_start + 1;
        $achieved = collect(array_keys($covered))->filter(fn ($value) => $value >= $target->range_start && $value <= $target->range_end)->count();

        return [
            'total' => $total,
            'achieved' => $achieved,
            'remaining' => max(0, $total - $achieved),
            'percent' => $total > 0 ? round(($achieved / $total) * 100, 1) : 0,
            'complete' => $achieved >= $total,
        ];
    }

    private function defaultRange(RppPlan $plan, SyllabusItem $item): ?array
    {
        if ($plan->level->code === 'PAUD' && str_contains(mb_strtolower($item->title), 'tilawati')) {
            return (int) $plan->semester === 1 ? [1, 22] : [23, 44];
        }
        if (preg_match('/Tilawati\s+([1-6])\s*\(44 halaman\)/i', $item->title, $match)) {
            $volume = (int) $match[1];

            return ($volume % 2 === 1 ? 1 : 2) === (int) $plan->semester ? [1, 44] : null;
        }

        return null;
    }

    private function validateAnchors(RppPlan $plan, RppProgressTarget $target, Collection $anchors, Collection $weeks): void
    {
        $effectiveIds = $weeks->pluck('id');
        $previousEnd = (int) $target->range_start - 1;
        foreach ($anchors as $anchor) {
            if (! $effectiveIds->contains($anchor->calendar_week_id) || (int) $anchor->week->semester !== (int) $plan->semester) {
                throw ValidationException::withMessages(['progress' => "Jangkar manual pada M{$anchor->week->week_number} berada di luar minggu efektif Semester {$plan->semester}."]);
            }
            if ($anchor->progress_start === null || $anchor->progress_end === null) {
                throw ValidationException::withMessages(['progress' => "Jangkar manual pada M{$anchor->week->week_number} belum memiliki rentang progres."]);
            }
            if ((int) $anchor->progress_start < (int) $target->range_start || (int) $anchor->progress_end > (int) $target->range_end || (int) $anchor->progress_start > (int) $anchor->progress_end) {
                throw ValidationException::withMessages(['progress' => "Rentang manual M{$anchor->week->week_number} berada di luar target {$target->range_start}–{$target->range_end}."]);
            }
            if ((int) $anchor->progress_start <= $previousEnd) {
                throw ValidationException::withMessages(['progress' => "Rentang manual M{$anchor->week->week_number} tumpang tindih atau tidak berurutan."]);
            }
            $previousEnd = (int) $anchor->progress_end;
        }
    }

    private function generateSegment(RppPlan $plan, RppProgressTarget $target, $column, Collection $weeks, int $start, int $end): void
    {
        $unitCount = max(0, $end - $start + 1);
        if ($unitCount > 0 && $weeks->isEmpty()) {
            throw ValidationException::withMessages(['progress' => "Target {$start}–{$end} tidak memiliki minggu kosong di antara jangkar manual."]);
        }
        if ($weeks->isEmpty()) {
            return;
        }

        $previousEnd = $start - 1;
        foreach ($weeks->values() as $index => $week) {
            if ($unitCount > 0) {
                $cumulative = (int) ceil((($index + 1) * $unitCount) / $weeks->count());
                $newEnd = $start + $cumulative - 1;
            } else {
                $newEnd = $previousEnd;
            }

            if ($unitCount > 0 && $newEnd > $previousEnd) {
                $newStart = $previousEnd + 1;
                $kind = 'materi_baru';
                $content = $this->content($target, $newStart, $newEnd, false);
                $previousEnd = $newEnd;
            } else {
                $review = max((int) $target->range_start, min((int) $target->range_end, $previousEnd));
                $newStart = $review;
                $newEnd = $review;
                $kind = 'penguatan';
                $content = $this->content($target, $review, $review, true);
            }

            $placement = RppWeekItem::query()->updateOrCreate(
                [
                    'rpp_plan_id' => $plan->id,
                    'calendar_week_id' => $week->id,
                    'syllabus_item_id' => $target->syllabus_item_id,
                    'source_fingerprint' => 'syllabus:'.$target->syllabus_item_id,
                    'occurrence_no' => 1,
                ],
                [
                    'rpp_progress_target_id' => $target->id,
                    'rpp_matrix_column_id' => $column->id,
                    'strand' => $column->label,
                    'content' => $content,
                    'progress_start' => $newStart,
                    'progress_end' => $newEnd,
                    'progress_kind' => $kind,
                    'source' => 'auto',
                    'is_locked' => false,
                    'position' => 1,
                ]
            );
            $this->catalog->attachPlacement($placement);
        }
    }

    private function content(RppProgressTarget $target, int $start, int $end, bool $review): string
    {
        $label = $start === $end ? (string) $start : "{$start}–{$end}";

        return $target->syllabusItem->title.' — '.($review ? 'Penguatan ' : ucfirst($target->unit_label).' ').($review ? $target->unit_label.' ' : '').$label;
    }
}
