<?php

namespace App\Services;

use App\Models\AcademicSemester;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CalendarWeek;
use App\Models\RevisionBatch;
use App\Models\RevisionItem;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcademicCalendarService
{
    public const TYPES = ['holiday', 'religious_holiday', 'evaluation', 'exam'];

    public function semester(AcademicYear $year, int $semester): AcademicSemester
    {
        $record = $year->semesters()->where('semester', $semester)->first();
        if ($record) {
            return $record;
        }

        $weeks = $year->weeks()->where('semester', $semester)->orderBy('week_number')->get();
        abort_if($weeks->isEmpty(), 404, 'Rentang semester belum tersedia.');

        return $year->semesters()->create([
            'semester' => $semester,
            'starts_on' => $weeks->first()->starts_on,
            'ends_on' => $weeks->last()->starts_on->copy()->addDays(6),
        ]);
    }

    public function weeksForPlan(RppPlan $plan, bool $effectiveOnly = false): Collection
    {
        $weeks = CalendarWeek::query()
            ->where('academic_year_id', $plan->academic_year_id)
            ->where('semester', $plan->semester)
            ->orderBy('week_number')
            ->get();
        $events = $this->eventsForLevel($plan->academic_year_id, $plan->level_id);

        $weeks->each(function (CalendarWeek $week) use ($events) {
            $weekEnd = $week->starts_on->copy()->addDays(6);
            $matches = $events->filter(fn (CalendarEvent $event) => $event->starts_on->lte($weekEnd) && $event->ends_on->gte($week->starts_on))->values();
            $effective = $week->is_effective && $matches->isEmpty();
            $week->setAttribute('resolved_is_effective', $effective);
            $week->setAttribute('resolved_events', $matches);
            $week->setAttribute('resolved_label', $matches->isNotEmpty()
                ? $matches->map(fn ($event) => $this->eventLabel($event))->implode("\n")
                : ($week->label ?: $this->weekTypeLabel($week->type)));
        });

        return $effectiveOnly ? $weeks->where('resolved_is_effective', true)->values() : $weeks;
    }

    public function isEffective(RppPlan $plan, CalendarWeek|int $week): bool
    {
        $weekId = $week instanceof CalendarWeek ? $week->id : $week;

        return $this->weeksForPlan($plan)->contains(fn (CalendarWeek $candidate) => (int) $candidate->id === (int) $weekId && $candidate->resolved_is_effective);
    }

    public function eventsForLevel(int $academicYearId, int $levelId, ?int $excludeId = null): Collection
    {
        return CalendarEvent::query()
            ->where('academic_year_id', $academicYearId)
            ->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId))
            ->where(fn ($query) => $query->where('applies_to_all', true)
                ->orWhereHas('levels', fn ($level) => $level->whereKey($levelId)))
            ->with('levels:id,name,code')
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();
    }

    public function previewEvent(AcademicYear $year, array $data, ?int $eventId = null): array
    {
        $startsOn = filled($data['starts_on'] ?? null) ? CarbonImmutable::parse($data['starts_on']) : null;
        $endsOn = filled($data['ends_on'] ?? null) ? CarbonImmutable::parse($data['ends_on']) : null;
        if (! $startsOn || ! $endsOn || $endsOn->lt($startsOn)) {
            return ['weeks' => collect(), 'plans' => collect(), 'item_count' => 0, 'locked_count' => 0, 'shortages' => collect()];
        }

        $levelIds = ! empty($data['applies_to_all'])
            ? $year->plans()->distinct()->pluck('level_id')
            : collect($data['level_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
        $weeks = $year->weeks()->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('starts_on', '>=', $startsOn->subDays(6))->orderBy('week_number')->get();
        $plans = $year->plans()->whereIn('level_id', $levelIds)->with(['items.week'])->get();
        $summary = collect();
        $shortages = collect();

        foreach ($plans as $plan) {
            $planWeeks = $weeks->where('semester', (int) $plan->semester);
            if ($planWeeks->isEmpty()) {
                continue;
            }
            $existingBlocked = $this->blockedWeekIds($plan, $eventId);
            $newlyBlocked = $planWeeks->pluck('id')->diff($existingBlocked)->values();
            $items = $plan->items->whereIn('calendar_week_id', $newlyBlocked);
            $available = $this->availableWeeksAfter($plan, $planWeeks->pluck('id'), $eventId);
            if (! $this->canReflow($plan, $available)) {
                $shortages->push(['plan_id' => $plan->id, 'level' => $plan->level?->name, 'semester' => $plan->semester]);
            }
            $summary->push([
                'plan_id' => $plan->id,
                'level_id' => $plan->level_id,
                'semester' => $plan->semester,
                'week_ids' => $newlyBlocked->all(),
                'item_count' => $items->count(),
                'locked_count' => $items->where('is_locked', true)->count(),
            ]);
        }

        return [
            'weeks' => $weeks,
            'plans' => $summary,
            'item_count' => $summary->sum('item_count'),
            'locked_count' => $summary->sum('locked_count'),
            'shortages' => $shortages,
        ];
    }

    public function saveEvent(AcademicYear $year, array $data, ?int $userId, ?int $eventId = null): CalendarEvent
    {
        Validator::make($data, [
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'details' => ['nullable', 'string', 'max:3000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'applies_to_all' => ['required', 'boolean'],
            'level_ids' => ['array'],
            'level_ids.*' => ['integer', 'exists:levels,id'],
            'confirm_impact' => ['nullable', 'boolean'],
        ])->after(function ($validator) use ($data) {
            if (empty($data['applies_to_all']) && empty($data['level_ids'])) {
                $validator->errors()->add('level_ids', 'Pilih sedikitnya satu jenjang atau gunakan Semua Jenjang.');
            }
        })->validate();

        $preview = $this->previewEvent($year, $data, $eventId);
        if ($preview['shortages']->isNotEmpty()) {
            throw ValidationException::withMessages(['calendar' => 'Minggu efektif tidak cukup setelah rentang ini. Perpanjang atau sesuaikan tanggal semester terlebih dahulu.']);
        }
        if ($preview['item_count'] > 0 && empty($data['confirm_impact'])) {
            throw ValidationException::withMessages(['confirm_impact' => 'Konfirmasikan pergeseran materi sebelum menyimpan rentang.']);
        }

        return DB::transaction(function () use ($year, $data, $userId, $eventId, $preview) {
            $event = $eventId
                ? CalendarEvent::query()->where('academic_year_id', $year->id)->lockForUpdate()->findOrFail($eventId)
                : new CalendarEvent(['academic_year_id' => $year->id]);
            $before = $event->exists ? $event->only(['type', 'title', 'details', 'starts_on', 'ends_on', 'applies_to_all']) : [];
            $event->forceFill([
                'type' => $data['type'],
                'title' => trim($data['title']),
                'details' => trim((string) ($data['details'] ?? '')) ?: null,
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'applies_to_all' => (bool) $data['applies_to_all'],
                'lock_version' => (int) $event->lock_version + 1,
                'last_edited_by' => $userId,
            ])->save();
            $event->levels()->sync($event->applies_to_all ? [] : collect($data['level_ids'])->map(fn ($id) => (int) $id)->unique()->all());

            $batch = RevisionBatch::query()->create([
                'uuid' => (string) Str::uuid(), 'user_id' => $userId, 'action' => $eventId ? 'edit' : 'create',
                'reason' => ($eventId ? 'Perbarui' : 'Tambah').' rentang kalender: '.$event->title,
            ]);
            RevisionItem::query()->create([
                'revision_batch_id' => $batch->id, 'revisable_type' => 'calendar_event', 'revisable_id' => $event->id,
                'before_values' => $before, 'after_values' => $event->only(['type', 'title', 'details', 'starts_on', 'ends_on', 'applies_to_all']),
                'before_lock_version' => max(0, $event->lock_version - 1), 'after_lock_version' => $event->lock_version,
            ]);
            $moved = 0;
            foreach ($preview['plans'] as $impact) {
                if ($impact['week_ids'] === []) {
                    continue;
                }
                $plan = RppPlan::query()->with(['items.week'])->lockForUpdate()->findOrFail($impact['plan_id']);
                $available = $this->weeksForPlan($plan, true);
                $moved += $this->reflowPlan($plan, $available, $batch, $userId);
                $plan->update(['status' => 'draft', 'validated_at' => null]);
            }
            $batch->update(['item_count' => $batch->items()->count()]);
            DB::table('rpp_annual_validations')->where('academic_year_id', $year->id)
                ->whereIn('level_id', $preview['plans']->pluck('level_id')->unique())->update(['status' => 'draft', 'validated_at' => null, 'validated_by' => null]);
            $this->log($userId, 'calendar.range_saved', ['event_id' => $event->id, 'moved_items' => $moved]);

            return $event->fresh('levels');
        });
    }

    public function deleteEvent(CalendarEvent $event, ?int $userId): void
    {
        DB::transaction(function () use ($event, $userId) {
            $yearId = $event->academic_year_id;
            $levelIds = $event->applies_to_all
                ? RppPlan::query()->where('academic_year_id', $yearId)->distinct()->pluck('level_id')
                : $event->levels()->pluck('levels.id');
            $snapshot = $event->only(['type', 'title', 'details', 'starts_on', 'ends_on', 'applies_to_all']);
            $event->delete();
            RppPlan::query()->where('academic_year_id', $yearId)->whereIn('level_id', $levelIds)
                ->update(['status' => 'draft', 'validated_at' => null]);
            DB::table('rpp_annual_validations')->where('academic_year_id', $yearId)->whereIn('level_id', $levelIds)
                ->update(['status' => 'draft', 'validated_at' => null, 'validated_by' => null]);
            $this->log($userId, 'calendar.range_deleted', $snapshot + ['event_id' => $event->id]);
        });
    }

    public function saveSemesterRanges(AcademicYear $year, array $ranges, ?int $userId): void
    {
        Validator::make($ranges, [
            'semester_1_start' => ['required', 'date'], 'semester_1_end' => ['required', 'date', 'after_or_equal:semester_1_start'],
            'semester_2_start' => ['required', 'date', 'after:semester_1_end'], 'semester_2_end' => ['required', 'date', 'after_or_equal:semester_2_start'],
        ])->validate();

        DB::transaction(function () use ($year, $ranges, $userId) {
            $dates = collect();
            foreach ([1, 2] as $semester) {
                $start = CarbonImmutable::parse($ranges["semester_{$semester}_start"]);
                $end = CarbonImmutable::parse($ranges["semester_{$semester}_end"]);
                for ($date = $start; $date->lte($end); $date = $date->addWeek()) {
                    $dates->push(['semester' => $semester, 'date' => $date]);
                }
            }
            $existingCount = $year->weeks()->count();
            if ($existingCount > $dates->count()) {
                $extraIds = $year->weeks()->orderBy('week_number')->skip($dates->count())->pluck('id');
                if (RppWeekItem::query()->whereIn('calendar_week_id', $extraIds)->exists()) {
                    throw ValidationException::withMessages(['semester' => 'Rentang baru menghapus minggu yang masih memiliki materi. Susun ulang atau pindahkan materi tersebut dahulu.']);
                }
                CalendarWeek::query()->whereIn('id', $extraIds)->delete();
            }
            foreach ($dates->values() as $index => $row) {
                $week = CalendarWeek::query()->firstOrNew(['academic_year_id' => $year->id, 'week_number' => $index + 1]);
                $week->forceFill([
                    'semester' => $row['semester'], 'starts_on' => $row['date']->toDateString(),
                    'month_label' => $row['date']->locale('id')->translatedFormat('F'),
                    'type' => 'effective', 'label' => null, 'is_effective' => true,
                ])->save();
            }
            foreach ([1, 2] as $semester) {
                $period = AcademicSemester::query()->firstOrNew(['academic_year_id' => $year->id, 'semester' => $semester]);
                $period->forceFill([
                    'starts_on' => $ranges["semester_{$semester}_start"],
                    'ends_on' => $ranges["semester_{$semester}_end"],
                    'last_edited_by' => $userId,
                    'lock_version' => (int) $period->lock_version + 1,
                ])->save();
            }
            $year->update(['starts_on' => $ranges['semester_1_start'], 'ends_on' => $ranges['semester_2_end']]);
            $year->plans()->update(['status' => 'draft', 'validated_at' => null]);
            $year->annualValidations()->update(['status' => 'draft', 'validated_at' => null, 'validated_by' => null]);
            $this->log($userId, 'calendar.semester_ranges_saved', $ranges);
        });
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            'religious_holiday' => 'Hari Raya', 'evaluation' => 'Evaluasi', 'exam' => 'Ujian', default => 'Libur',
        };
    }

    private function eventLabel(CalendarEvent $event): string
    {
        $range = $event->starts_on->format('d/m/Y').'–'.$event->ends_on->format('d/m/Y');

        return $this->typeLabel($event->type).': '.$event->title.' ('.$range.')'.($event->details ? "\n".$event->details : '');
    }

    private function weekTypeLabel(string $type): string
    {
        return $type === 'effective' ? 'Minggu Efektif' : $this->typeLabel($type);
    }

    private function blockedWeekIds(RppPlan $plan, ?int $excludeId = null): Collection
    {
        $events = $this->eventsForLevel($plan->academic_year_id, $plan->level_id, $excludeId);

        return CalendarWeek::query()->where('academic_year_id', $plan->academic_year_id)->where('semester', $plan->semester)->get()
            ->filter(function ($week) use ($events) {
                $end = $week->starts_on->copy()->addDays(6);

                return $events->contains(fn ($event) => $event->starts_on->lte($end) && $event->ends_on->gte($week->starts_on));
            })->pluck('id');
    }

    private function availableWeeksAfter(RppPlan $plan, Collection $additionalBlockedIds, ?int $excludeId): Collection
    {
        $blocked = $this->blockedWeekIds($plan, $excludeId)->merge($additionalBlockedIds)->unique();

        return CalendarWeek::query()->where('academic_year_id', $plan->academic_year_id)->where('semester', $plan->semester)
            ->where('is_effective', true)->whereNotIn('id', $blocked)->orderBy('week_number')->get();
    }

    private function canReflow(RppPlan $plan, Collection $available): bool
    {
        $weekNumbers = $available->pluck('week_number', 'id');
        foreach ($plan->items->groupBy(fn ($item) => $item->rpp_matrix_column_id ?: 0) as $items) {
            $groups = $items->groupBy('calendar_week_id')->sortBy(fn ($group) => $group->first()->week?->week_number ?? 9999);
            $cursor = -1;
            foreach ($groups as $group) {
                $current = (int) ($group->first()->week?->week_number ?? 0);
                $next = $weekNumbers->filter(fn ($number) => $number >= $current && $number > $cursor)->first();
                if ($next === null) {
                    return false;
                }
                $cursor = (int) $next;
            }
        }

        return true;
    }

    private function reflowPlan(RppPlan $plan, Collection $available, RevisionBatch $batch, ?int $userId): int
    {
        $availableByNumber = $available->keyBy('week_number');
        $moved = 0;
        $plan->loadMissing('items.week');
        foreach ($plan->items->groupBy(fn ($item) => $item->rpp_matrix_column_id ?: 0) as $items) {
            $groups = $items->groupBy('calendar_week_id')->sortBy(fn ($group) => $group->first()->week?->week_number ?? 9999);
            $cursor = -1;
            foreach ($groups as $group) {
                $current = (int) ($group->first()->week?->week_number ?? 0);
                $target = $availableByNumber->first(fn ($week, $number) => (int) $number >= $current && (int) $number > $cursor);
                if (! $target) {
                    throw ValidationException::withMessages(['calendar' => 'Minggu efektif tidak cukup untuk memindahkan seluruh materi secara berurutan.']);
                }
                $cursor = $target->week_number;
                foreach ($group as $item) {
                    if ((int) $item->calendar_week_id === (int) $target->id) {
                        continue;
                    }
                    $beforeVersion = (int) $item->lock_version;
                    $before = ['calendar_week_id' => $item->calendar_week_id];
                    $item->forceFill(['calendar_week_id' => $target->id, 'lock_version' => $beforeVersion + 1, 'last_edited_by' => $userId])->save();
                    RevisionItem::query()->create([
                        'revision_batch_id' => $batch->id, 'revisable_type' => 'rpp', 'revisable_id' => $item->id,
                        'before_values' => $before, 'after_values' => ['calendar_week_id' => $target->id],
                        'before_lock_version' => $beforeVersion, 'after_lock_version' => $beforeVersion + 1,
                    ]);
                    $moved++;
                }
            }
        }

        return $moved;
    }

    private function log(?int $userId, string $action, array $details): void
    {
        DB::table('activity_logs')->insert([
            'user_id' => $userId, 'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
