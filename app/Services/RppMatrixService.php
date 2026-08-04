<?php

namespace App\Services;

use App\Models\RppMonthFocus;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use Illuminate\Support\Collection;

class RppMatrixService
{
    public function __construct(private readonly AcademicCalendarService $calendar) {}

    public function ensureMonthFocuses(RppPlan $plan): void
    {
        $plan->loadMissing(['academicYear.weeks', 'items.matrixColumn']);
        $weeks = $this->calendar->weeksForPlan($plan);
        foreach ($weeks->groupBy(fn ($week) => $week->starts_on->format('Y-m')) as $key => $monthWeeks) {
            $suggestion = $plan->items
                ->whereIn('calendar_week_id', $monthWeeks->pluck('id'))
                ->filter(fn (RppWeekItem $item) => str_contains(mb_strtolower((string) $item->matrixColumn?->aspect_label), 'akhlaq'))
                ->sortBy('position')
                ->pluck('content')
                ->filter()
                ->first();
            $focus = RppMonthFocus::query()->firstOrNew(['rpp_plan_id' => $plan->id, 'month_key' => $key]);
            if (! $focus->exists || ($focus->source === 'suggested' && ! $focus->is_locked)) {
                $focus->forceFill([
                    'month_label' => $monthWeeks->first()->month_label,
                    'focus_text' => $this->shortFocus($suggestion),
                    'source' => 'suggested',
                ])->save();
            }
        }
    }

    public function columns(RppPlan $plan): Collection
    {
        return $plan->level->matrixColumns()->where('is_active', true)->withCount('mappings')->orderBy('sort_order')->orderBy('id')->get();
    }

    public function headerGroups(Collection $columns, string $field): Collection
    {
        $groups = collect();
        foreach ($columns as $column) {
            $label = trim((string) $column->{$field});
            $groupKey = $field === 'subaspect_label' ? $column->aspect_label.'|'.$label : $label;
            $last = $groups->last();
            if ($last && $last['key'] === $groupKey) {
                $last['span']++;
                $groups->put($groups->keys()->last(), $last);
            } else {
                $groups->push(['key' => $groupKey, 'label' => $label ?: '—', 'span' => 1]);
            }
        }

        return $groups;
    }

    public function itemsByCell(RppPlan $plan): Collection
    {
        return $plan->items
            ->sortBy(fn ($item) => sprintf('%06d-%06d', $item->position, $item->id))
            ->groupBy(fn ($item) => $item->calendar_week_id.':'.($item->rpp_matrix_column_id ?: 0));
    }

    public function monthRows(Collection $weeks, Collection $focuses): array
    {
        $result = [];
        foreach ($weeks->groupBy(fn ($week) => $week->starts_on->format('Y-m')) as $key => $monthWeeks) {
            $first = $monthWeeks->sortBy('week_number')->first();
            $result[$first->id] = [
                'rowspan' => $monthWeeks->count(),
                'label' => $first->month_label,
                'focus' => $focuses->firstWhere('month_key', $key),
            ];
        }

        return $result;
    }

    public function trimesterNumber(int $semester, int $index): int
    {
        return (($semester - 1) * 2) + $index + 1;
    }

    public function sourceNote(RppWeekItem $item): string
    {
        $item->loadMissing([
            'materials.ggbItem.document',
            'materials.ggbItem.syllabusItems.document',
            'materials.syllabusItem.document',
            'syllabusItem.document',
            'syllabusItem.ggbItems.document',
        ]);
        $syllabus = $item->syllabusItem;
        $codes = $item->materials->sortBy('sort_order')->pluck('display_code')->implode(', ');
        $lines = [$codes !== '' ? 'Kode materi: '.$codes : 'Kode materi belum tersedia'];
        if ($syllabus) {
            $lines[] = 'Silabus: '.$syllabus->stable_code.' — '.$syllabus->document->title.' hlm. '.$syllabus->source_page;
        }
        $ggbItems = $item->materials->pluck('ggbItem')->filter()
            ->merge($syllabus?->ggbItems ?? collect())->unique('id')->take(8);
        foreach ($ggbItems as $ggb) {
            $lines[] = 'GGB: '.$ggb->stable_code.' — '.$ggb->title.' (hlm. '.$ggb->source_page.')';
        }
        $status = match ($item->source) {
            'manual' => 'Manual terkunci',
            'reinforcement_auto' => 'Penguatan otomatis',
            'activity_auto' => 'Rotasi kegiatan',
            'ggb_auto' => 'Materi GGB otomatis',
            default => 'Materi baru otomatis',
        };
        $lines[] = 'Status: '.$status
            .($item->progress_kind === 'penguatan' ? ' · Penguatan' : '');

        return implode("\n", $lines);
    }

    private function shortFocus(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > 90 ? mb_substr($value, 0, 87).'…' : $value;
    }
}
