<?php

namespace App\Services;

use App\Models\AcademicSemester;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CalendarWeek;
use App\Models\RevisionBatch;
use App\Models\RevisionItem;
use App\Models\RppAnnualValidation;
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

    public function __construct(private readonly RppMaterialCatalogService $catalog) {}

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
            return [
                'weeks' => collect(), 'plans' => collect(), 'item_count' => 0,
                'locked_count' => 0, 'combined_groups' => 0, 'shortages' => collect(),
            ];
        }

        $levelIds = ! empty($data['applies_to_all'])
            ? $year->plans()->distinct()->pluck('level_id')
            : collect($data['level_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
        $weeks = $year->weeks()->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('starts_on', '>=', $startsOn->subDays(6))->orderBy('week_number')->get();
        $plans = $year->plans()->whereIn('level_id', $levelIds)->with(['items.week'])->get();
        $summary = collect();
        $shortages = collect();
        $combinedGroups = 0;

        foreach ($plans as $plan) {
            $planWeeks = $weeks->where('semester', (int) $plan->semester);
            if ($planWeeks->isEmpty()) {
                continue;
            }
            $existingBlocked = $this->blockedWeekIds($plan, $eventId);
            $newlyBlocked = $planWeeks->pluck('id')->diff($existingBlocked)->values();
            $items = $plan->items->whereIn('calendar_week_id', $newlyBlocked);
            $available = $this->availableWeeksAfter($plan, $planWeeks->pluck('id'), $eventId);
            if ($available->isEmpty()) {
                $shortages->push(['plan_id' => $plan->id, 'level' => $plan->level?->name, 'semester' => $plan->semester]);
            }
            $planCombined = $this->combinedGroupCount($plan, $available);
            $combinedGroups += $planCombined;
            $summary->push([
                'plan_id' => $plan->id,
                'level_id' => $plan->level_id,
                'semester' => $plan->semester,
                'week_ids' => $newlyBlocked->all(),
                'item_count' => $items->count(),
                'locked_count' => $items->where('is_locked', true)->count(),
                'effective_week_count' => $available->count(),
                'combined_groups' => $planCombined,
            ]);
        }

        return [
            'weeks' => $weeks,
            'plans' => $summary,
            'item_count' => $summary->sum('item_count'),
            'locked_count' => $summary->sum('locked_count'),
            'combined_groups' => $combinedGroups,
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
        $levelIds = $preview['plans']->pluck('level_id')->unique()->values();
        $annualStates = $this->annualValidationStates($year, $levelIds);

        return DB::transaction(function () use ($year, $data, $userId, $eventId, $preview, $levelIds, $annualStates) {
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
                app(RppMatrixFillService::class)->fill($plan, $userId);
                $plan->update(['status' => 'draft', 'validated_at' => null]);
            }
            $batch->update(['item_count' => $batch->items()->count()]);
            $this->preserveAnnualValidations($year, $levelIds, $annualStates);
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
            $year = AcademicYear::query()->findOrFail($yearId);
            $annualStates = $this->annualValidationStates($year, $levelIds);
            $snapshot = $event->only(['type', 'title', 'details', 'starts_on', 'ends_on', 'applies_to_all']);
            $event->delete();
            RppPlan::query()->where('academic_year_id', $yearId)->whereIn('level_id', $levelIds)->get()
                ->each(function (RppPlan $plan) use ($userId) {
                    app(RppMatrixFillService::class)->fill($plan, $userId);
                    $plan->update(['status' => 'draft', 'validated_at' => null]);
                });
            $this->preserveAnnualValidations($year, $levelIds, $annualStates);
            $this->log($userId, 'calendar.range_deleted', $snapshot + ['event_id' => $event->id]);
        });
    }

    public function previewSemesterRanges(AcademicYear $year, array $ranges, ?int $levelId = null): array
    {
        try {
            $dates = $this->validatedSemesterDates($ranges);
        } catch (ValidationException) {
            return ['valid' => false, 'semesters' => [], 'items' => 0, 'locked' => 0, 'combined_groups' => 0];
        }

        $plans = $year->plans()->when($levelId, fn ($query) => $query->where('level_id', $levelId))
            ->with(['items.week'])->get();
        $semesters = [];
        $items = 0;
        $locked = 0;
        $combined = 0;
        foreach ([1, 2] as $semester) {
            $oldWeeks = $year->weeks()->where('semester', $semester)->orderBy('week_number')->get();
            $newCount = $dates[$semester]->count();
            $removedIds = $oldWeeks->slice($newCount)->pluck('id');
            $semesterPlans = $plans->where('semester', $semester);
            $affected = $semesterPlans->flatMap(fn (RppPlan $plan) => $plan->items->whereIn('calendar_week_id', $removedIds));
            $combinedSemester = $semesterPlans->sum(function (RppPlan $plan) use ($newCount) {
                $targetWeeks = collect(range(1, max(1, $newCount)));

                return $this->combinedGroupCount($plan, $targetWeeks);
            });
            $items += $affected->count();
            $locked += $affected->where('is_locked', true)->count();
            $combined += $combinedSemester;
            $semesters[$semester] = [
                'old_weeks' => $oldWeeks->count(),
                'new_weeks' => $newCount,
                'items_affected' => $affected->count(),
                'locked_affected' => $affected->where('is_locked', true)->count(),
                'combined_groups' => $combinedSemester,
            ];
        }

        return [
            'valid' => true,
            'semesters' => $semesters,
            'items' => $items,
            'locked' => $locked,
            'combined_groups' => $combined,
        ];
    }

    public function saveSemesterRanges(AcademicYear $year, array $ranges, ?int $userId): array
    {
        $dates = $this->validatedSemesterDates($ranges);
        $levelIds = $year->plans()->distinct()->pluck('level_id');
        $annualStates = $this->annualValidationStates($year, $levelIds);

        return DB::transaction(function () use ($year, $ranges, $dates, $userId, $levelIds, $annualStates) {
            $batch = RevisionBatch::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'action' => 'edit',
                'reason' => 'Perbarui rentang Semester 1 dan Semester 2.',
            ]);
            $temporaryBase = 10000;
            $year->weeks()->update(['week_number' => DB::raw('week_number + '.$temporaryBase)]);
            $keptIds = collect();
            $removedIds = collect();
            $temporaryNumber = (int) $year->weeks()->max('week_number') + 1;

            foreach ([1, 2] as $semester) {
                $existing = $year->weeks()->where('semester', $semester)->orderBy('week_number')->get();
                foreach ($dates[$semester] as $index => $date) {
                    $week = $existing->get($index) ?: new CalendarWeek([
                        'academic_year_id' => $year->id,
                        'week_number' => $temporaryNumber++,
                        'semester' => $semester,
                    ]);
                    $week->forceFill([
                        'starts_on' => $date->toDateString(),
                        'month_label' => $date->locale('id')->translatedFormat('F'),
                        'type' => 'effective',
                        'label' => null,
                        'is_effective' => true,
                    ])->save();
                    $keptIds->push($week->id);
                }
                $tail = $existing->slice($dates[$semester]->count());
                if ($tail->isNotEmpty()) {
                    CalendarWeek::query()->whereIn('id', $tail->pluck('id'))->update(['is_effective' => false]);
                    $removedIds = $removedIds->merge($tail->pluck('id'));
                }

                $period = AcademicSemester::query()->firstOrNew([
                    'academic_year_id' => $year->id,
                    'semester' => $semester,
                ]);
                $before = $period->exists ? $period->only(['starts_on', 'ends_on']) : [];
                $beforeVersion = (int) $period->lock_version;
                $period->forceFill([
                    'starts_on' => $ranges["semester_{$semester}_start"],
                    'ends_on' => $ranges["semester_{$semester}_end"],
                    'last_edited_by' => $userId,
                    'lock_version' => $beforeVersion + 1,
                ])->save();
                RevisionItem::query()->create([
                    'revision_batch_id' => $batch->id,
                    'revisable_type' => 'academic_semester',
                    'revisable_id' => $period->id,
                    'before_values' => $before,
                    'after_values' => $period->only(['starts_on', 'ends_on']),
                    'before_lock_version' => $beforeVersion,
                    'after_lock_version' => $beforeVersion + 1,
                ]);
            }

            $moved = 0;
            $combined = 0;
            $plans = $year->plans()->with(['items.week'])->lockForUpdate()->get();
            foreach ($plans as $plan) {
                $available = $this->weeksForPlan($plan, true)->whereIn('id', $keptIds)->values();
                if ($available->isEmpty()) {
                    throw ValidationException::withMessages([
                        'semester' => "Semester {$plan->semester} tidak mempunyai minggu efektif setelah perubahan rentang.",
                    ]);
                }
                $combined += $this->combinedGroupCount($plan, $available);
                $moved += $this->reflowPlan($plan, $available, $batch, $userId);
                app(RppMatrixFillService::class)->fill($plan, $userId);
                $plan->update(['status' => 'draft', 'validated_at' => null]);
            }

            if ($removedIds->isNotEmpty()) {
                CalendarWeek::query()->whereIn('id', $removedIds)->delete();
            }
            $year->weeks()->orderBy('semester')->orderBy('starts_on')->orderBy('id')->get()
                ->values()->each(fn (CalendarWeek $week, int $index) => $week->update(['week_number' => $index + 1]));
            $year->update(['starts_on' => $ranges['semester_1_start'], 'ends_on' => $ranges['semester_2_end']]);
            $batch->update(['item_count' => $batch->items()->count()]);
            $this->preserveAnnualValidations($year, $levelIds, $annualStates);
            $result = ['moved_items' => $moved, 'combined_groups' => $combined, 'batch_uuid' => $batch->uuid];
            $this->log($userId, 'calendar.semester_ranges_saved', $ranges + $result);

            return $result;
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

    private function combinedGroupCount(RppPlan $plan, Collection $available): int
    {
        $capacity = $available->count();
        if ($capacity === 0) {
            return $plan->items()->exists() ? 1 : 0;
        }

        return $plan->items()->get()->groupBy(fn ($item) => $item->rpp_matrix_column_id ?: 0)
            ->sum(fn (Collection $items) => max(0, $items->pluck('calendar_week_id')->unique()->count() - $capacity));
    }

    private function reflowPlan(RppPlan $plan, Collection $available, RevisionBatch $batch, ?int $userId): int
    {
        if ($available->isEmpty()) {
            throw ValidationException::withMessages(['calendar' => 'Semester tidak mempunyai minggu efektif untuk menampung materi.']);
        }

        $available = $available->sortBy('week_number')->values();
        $availableByNumber = $available->keyBy('week_number');
        $plan->loadMissing(['items.week', 'items.matrixColumn']);
        $targets = collect();

        foreach ($plan->items->groupBy(fn ($item) => $item->rpp_matrix_column_id ?: 0) as $items) {
            $groups = $items->groupBy('calendar_week_id')->sortBy(fn ($group) => $group->first()->week?->week_number ?? 9999);
            $cursorIndex = -1;
            foreach ($groups as $group) {
                $current = (int) ($group->first()->week?->week_number ?? 0);
                $exact = $availableByNumber->get($current);
                $targetIndex = $exact ? $available->search(fn (CalendarWeek $week) => $week->is($exact)) : false;
                if ($targetIndex === false || $targetIndex <= $cursorIndex) {
                    $targetIndex = $available->search(fn (CalendarWeek $week, int $index) => $index > $cursorIndex && $week->week_number >= $current);
                }
                if ($targetIndex === false) {
                    $targetIndex = $available->count() - 1;
                }
                $target = $available->get($targetIndex);
                if (! $target) {
                    throw ValidationException::withMessages(['calendar' => 'Minggu efektif tidak cukup untuk memindahkan seluruh materi secara berurutan.']);
                }
                $cursorIndex = max($cursorIndex, $targetIndex);
                foreach ($group as $item) {
                    $targets->put($item->id, $target);
                }
            }
        }

        $rows = $plan->items->map(function (RppWeekItem $item) use ($targets) {
            $target = $targets->get($item->id) ?: $item->week;

            return [
                'item' => $item,
                'target' => $target,
                'source_fingerprint' => $item->source_fingerprint,
                'source_week_number' => (int) ($item->week?->week_number ?? 0),
            ];
        });
        $occurrences = [];
        foreach ($rows->groupBy(fn (array $row) => $row['target']->id.'|'.$row['item']->source_fingerprint) as $identityRows) {
            foreach ($identityRows->sortBy(fn (array $row) => sprintf(
                '%08d:%08d:%08d',
                $row['source_week_number'],
                $row['item']->position,
                $row['item']->id,
            ))->values() as $index => $row) {
                $occurrences[$row['item']->id] = $index + 1;
            }
        }
        $positions = [];
        foreach ($rows->groupBy(fn (array $row) => $row['target']->id) as $weekRows) {
            foreach ($weekRows->sortBy(fn (array $row) => sprintf(
                '%08d:%08d:%08d:%08d',
                $row['item']->matrixColumn?->sort_order ?? 999999,
                $row['source_week_number'],
                $row['item']->position,
                $row['item']->id,
            ))->values() as $index => $row) {
                $positions[$row['item']->id] = $index + 1;
            }
        }
        $changes = $rows->filter(fn (array $row) => (int) $row['item']->calendar_week_id !== (int) $row['target']->id
            || (int) $row['item']->occurrence_no !== (int) $occurrences[$row['item']->id]
            || (int) $row['item']->position !== (int) $positions[$row['item']->id]);

        foreach ($changes as $row) {
            $item = $row['item'];
            $item->forceFill(['source_fingerprint' => 'tmp-calendar:'.$item->id.':'.Str::uuid()])->saveQuietly();
        }
        foreach ($changes->sortByDesc('source_week_number') as $row) {
            $item = $row['item'];
            $target = $row['target'];
            $beforeVersion = (int) $item->lock_version;
            $before = [
                'calendar_week_id' => $item->calendar_week_id,
                'occurrence_no' => $item->occurrence_no,
                'position' => $item->position,
            ];
            $after = [
                'calendar_week_id' => $target->id,
                'occurrence_no' => $occurrences[$item->id],
                'position' => $positions[$item->id],
            ];
            $item->forceFill($after + [
                'source_fingerprint' => $row['source_fingerprint'],
                'lock_version' => $beforeVersion + 1,
                'last_edited_by' => $userId,
            ])->save();
            RevisionItem::query()->create([
                'revision_batch_id' => $batch->id, 'revisable_type' => 'rpp', 'revisable_id' => $item->id,
                'before_values' => $before, 'after_values' => $after,
                'before_lock_version' => $beforeVersion, 'after_lock_version' => $beforeVersion + 1,
            ]);
        }

        return $changes->count();
    }

    /**
     * @return array<int, Collection<int, CarbonImmutable>>
     */
    private function validatedSemesterDates(array $ranges): array
    {
        $validated = Validator::make($ranges, [
            'semester_1_start' => ['required', 'date'],
            'semester_1_end' => ['required', 'date', 'after_or_equal:semester_1_start'],
            'semester_2_start' => ['required', 'date', 'after:semester_1_end'],
            'semester_2_end' => ['required', 'date', 'after_or_equal:semester_2_start'],
        ], [
            'semester_1_start.required' => 'Tanggal mulai Semester 1 wajib diisi.',
            'semester_1_end.required' => 'Tanggal akhir Semester 1 wajib diisi.',
            'semester_1_end.after_or_equal' => 'Tanggal akhir Semester 1 tidak boleh mendahului tanggal mulai.',
            'semester_2_start.required' => 'Tanggal mulai Semester 2 wajib diisi.',
            'semester_2_start.after' => 'Semester 2 harus dimulai setelah Semester 1 berakhir agar rentang tidak bertumpang tindih.',
            'semester_2_end.required' => 'Tanggal akhir Semester 2 wajib diisi.',
            'semester_2_end.after_or_equal' => 'Tanggal akhir Semester 2 tidak boleh mendahului tanggal mulai.',
        ])->validate();

        return collect([1, 2])->mapWithKeys(function (int $semester) use ($validated) {
            $start = CarbonImmutable::parse($validated["semester_{$semester}_start"])->startOfDay();
            $end = CarbonImmutable::parse($validated["semester_{$semester}_end"])->startOfDay();
            $dates = collect();
            for ($date = $start; $date->lte($end); $date = $date->addWeek()) {
                $dates->push($date);
            }
            if ($dates->isEmpty()) {
                throw ValidationException::withMessages([
                    "semester_{$semester}_start" => "Semester {$semester} harus mempunyai sedikitnya satu minggu.",
                ]);
            }

            return [$semester => $dates];
        })->all();
    }

    private function annualValidationStates(AcademicYear $year, Collection $levelIds): Collection
    {
        if ($levelIds->isEmpty()) {
            return collect();
        }

        return RppAnnualValidation::query()
            ->where('academic_year_id', $year->id)
            ->whereIn('level_id', $levelIds)
            ->get()
            ->keyBy('level_id');
    }

    private function preserveAnnualValidations(AcademicYear $year, Collection $levelIds, Collection $states): void
    {
        foreach ($levelIds->unique() as $levelId) {
            $plan = RppPlan::query()
                ->where('academic_year_id', $year->id)
                ->where('level_id', $levelId)
                ->first();
            if (! $plan) {
                continue;
            }

            $coverage = $this->catalog->coverage($plan);
            $previous = $states->get($levelId);
            $keepValidated = $previous?->status === 'validated' && (float) $coverage['percent'] >= 100;
            RppAnnualValidation::query()->updateOrCreate(
                ['academic_year_id' => $year->id, 'level_id' => $levelId],
                [
                    'status' => $keepValidated ? 'validated' : 'draft',
                    'coverage_percent' => $coverage['percent'],
                    'validated_at' => $keepValidated ? $previous->validated_at : null,
                    'validated_by' => $keepValidated ? $previous->validated_by : null,
                ],
            );
        }
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
