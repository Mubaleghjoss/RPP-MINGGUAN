<?php

namespace App\Services;

use App\Models\CalendarWeek;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RppBulkActionService
{
    public function __construct(
        private readonly RppPlanner $planner,
        private readonly RppMatrixPresetService $presets,
        private readonly RppMaterialCatalogService $catalog,
        private readonly AcademicCalendarService $calendar,
    ) {}

    public function updatePlacements(RppPlan $plan, array $placementIds, string $action, ?int $weekId, string $reason, ?int $userId): int
    {
        $ids = $this->validatedIds($placementIds, $reason);
        Validator::make(['action' => $action], ['action' => ['required', 'in:move,lock,unlock']])->validate();

        return DB::transaction(function () use ($plan, $ids, $action, $weekId, $reason, $userId) {
            $lockedPlan = RppPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->presets->syncLevel($lockedPlan->level);
            $week = $action === 'move' ? $this->effectiveWeek($lockedPlan, $weekId) : null;
            $items = RppWeekItem::query()
                ->where('rpp_plan_id', $lockedPlan->id)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($ids)) {
                throw ValidationException::withMessages(['selection' => 'Pilihan tidak valid atau berasal dari RPP lain. Muat ulang halaman.']);
            }

            foreach ($items as $item) {
                $changes = match ($action) {
                    'move' => ['calendar_week_id' => $week->id, 'source' => 'manual', 'is_locked' => true],
                    'lock' => ['source' => 'manual', 'is_locked' => true],
                    'unlock' => ['source' => 'manual', 'is_locked' => false],
                };
                $item->forceFill($changes + [
                    'lock_version' => (int) $item->lock_version + 1,
                    'last_edited_by' => $userId,
                ])->save();
            }

            $this->finish($lockedPlan, $userId, 'rpp.bulk_'.$action, [
                'reason' => trim($reason),
                'placement_ids' => $ids,
                'calendar_week_id' => $week?->id,
                'count' => count($ids),
            ]);

            return count($ids);
        });
    }

    public function scheduleUnplanned(RppPlan $plan, array $syllabusIds, ?int $weekId, string $reason, ?int $userId, string $activityAction = 'rpp.bulk_scheduled'): int
    {
        $ids = $this->validatedIds($syllabusIds, $reason);

        return DB::transaction(function () use ($plan, $ids, $weekId, $reason, $userId, $activityAction) {
            $lockedPlan = RppPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->presets->syncLevel($lockedPlan->level);
            $week = $this->effectiveWeek($lockedPlan, $weekId);
            $items = SyllabusItem::query()
                ->where('level_id', $lockedPlan->level_id)
                ->whereIn('id', $ids)
                ->where('is_duplicate', false)
                ->where('needs_allocation', false)
                ->whereIn('semester_scope', [(string) $lockedPlan->semester, 'both'])
                ->whereDoesntHave('placements', fn ($query) => $query->where('rpp_plan_id', $lockedPlan->id))
                ->lockForUpdate()
                ->with('matrixMapping.column')
                ->get();

            if ($items->count() !== count($ids)) {
                throw ValidationException::withMessages(['selection' => 'Sebagian materi duplikat, perlu alokasi, sudah dijadwalkan, atau bukan milik jenjang ini. Tidak ada perubahan diterapkan.']);
            }
            if ($items->contains(fn (SyllabusItem $item) => blank($item->allocation_text) || (int) $item->recommended_sessions < 1)) {
                throw ValidationException::withMessages(['selection' => 'Sebagian materi belum memiliki alokasi atau jumlah pertemuan minimal 1. Tidak ada perubahan diterapkan.']);
            }

            $position = (int) RppWeekItem::query()
                ->where('rpp_plan_id', $lockedPlan->id)
                ->where('calendar_week_id', $week->id)
                ->max('position');

            foreach ($items->sortBy('sort_order') as $item) {
                $column = $item->matrixMapping?->column;
                if (! $column || ! $column->is_active) {
                    throw ValidationException::withMessages(['selection' => "Materi {$item->stable_code} belum dipetakan ke kolom matriks aktif."]);
                }
                $placement = RppWeekItem::query()->create([
                    'rpp_plan_id' => $lockedPlan->id,
                    'calendar_week_id' => $week->id,
                    'syllabus_item_id' => $item->id,
                    'source_fingerprint' => 'syllabus:'.$item->id,
                    'occurrence_no' => 1,
                    'rpp_matrix_column_id' => $column->id,
                    'strand' => $column->label,
                    'content' => $item->title,
                    'source' => 'manual',
                    'is_locked' => true,
                    'position' => ++$position,
                    'lock_version' => 1,
                    'last_edited_by' => $userId,
                ]);
                $this->catalog->attachPlacement($placement);
            }

            $this->finish($lockedPlan, $userId, $activityAction, [
                'reason' => trim($reason),
                'syllabus_item_ids' => $ids,
                'calendar_week_id' => $week->id,
                'count' => count($ids),
            ]);

            return count($ids);
        });
    }

    private function validatedIds(array $ids, string $reason): array
    {
        Validator::make(['ids' => $ids, 'reason' => $reason], [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'ids.min' => 'Pilih sedikitnya satu materi.',
            'reason.min' => 'Alasan tindakan minimal 5 karakter.',
        ])->validate();

        return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function effectiveWeek(RppPlan $plan, ?int $weekId): CalendarWeek
    {
        if (! $weekId) {
            throw ValidationException::withMessages(['week' => 'Pilih minggu efektif tujuan.']);
        }

        $week = CalendarWeek::query()
            ->where('academic_year_id', $plan->academic_year_id)
            ->where('semester', $plan->semester)
            ->lockForUpdate()
            ->find($weekId);

        if (! $week || ! $this->calendar->isEffective($plan, $week)) {
            throw ValidationException::withMessages(['week' => 'Minggu tujuan tidak efektif atau bukan bagian dari tahun ajaran ini.']);
        }

        return $week;
    }

    private function finish(RppPlan $plan, ?int $userId, string $action, array $details): void
    {
        $this->planner->refreshCoverage($plan);
        $plan->update(['status' => 'draft', 'validated_at' => null]);
        DB::table('activity_logs')->insert([
            'user_id' => $userId,
            'action' => $action,
            'details' => json_encode($details + ['plan_id' => $plan->id, 'level_id' => $plan->level_id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
