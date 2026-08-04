<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RevisionBatch;
use App\Models\RevisionItem;
use App\Models\RppAnnualValidation;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RppAnnualGgbService
{
    public function __construct(
        private readonly AcademicCalendarService $calendar,
        private readonly RppMaterialCatalogService $catalog,
        private readonly RppMatrixPresetService $presets,
    ) {}

    public function confirm(Level $level, array $ids, ?int $semester, ?int $columnId, string $reason, ?int $userId): int
    {
        Validator::make(compact('ids', 'semester', 'columnId', 'reason'), [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'semester' => ['nullable', 'integer', 'in:1,2'],
            'columnId' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['reason.min' => 'Alasan konfirmasi minimal 5 karakter.'])->validate();
        if (! $semester && ! $columnId) {
            throw ValidationException::withMessages(['selection' => 'Pilih semester atau kolom RPP yang akan dikonfirmasi.']);
        }

        return DB::transaction(function () use ($level, $ids, $semester, $columnId, $reason, $userId) {
            $materials = RppMaterialCatalogItem::query()->where('level_id', $level->id)->where('source_kind', 'ggb')
                ->whereIn('id', collect($ids)->map(fn ($id) => (int) $id)->unique())->lockForUpdate()->get();
            if ($materials->count() !== collect($ids)->unique()->count()) {
                throw ValidationException::withMessages(['selection' => 'Sebagian materi bukan milik jenjang aktif.']);
            }
            $column = $columnId ? $level->matrixColumns()->where('is_active', true)->find($columnId) : null;
            if ($columnId && ! $column) {
                throw ValidationException::withMessages(['column' => 'Kolom RPP tidak aktif atau berasal dari jenjang lain.']);
            }
            $batch = RevisionBatch::query()->create([
                'uuid' => (string) Str::uuid(), 'user_id' => $userId, 'action' => 'edit', 'reason' => trim($reason),
            ]);
            foreach ($materials as $material) {
                $before = $material->only(['semester_scope', 'semester_confirmed', 'rpp_matrix_column_id', 'mapping_status']);
                $version = (int) $material->lock_version;
                if ($semester) {
                    $material->semester_scope = (string) $semester;
                    $material->semester_confirmed = true;
                }
                if ($column) {
                    $material->rpp_matrix_column_id = $column->id;
                    $material->mapping_status = 'mapped';
                }
                $material->lock_version = $version + 1;
                $material->last_edited_by = $userId;
                $material->save();
                RevisionItem::query()->create([
                    'revision_batch_id' => $batch->id, 'revisable_type' => 'material_catalog', 'revisable_id' => $material->id,
                    'before_values' => $before,
                    'after_values' => $material->only(['semester_scope', 'semester_confirmed', 'rpp_matrix_column_id', 'mapping_status']),
                    'before_lock_version' => $version, 'after_lock_version' => $version + 1,
                ]);
            }
            $batch->update(['item_count' => $materials->count()]);
            $this->invalidateAnnualValidation(AcademicYear::query()->where('is_active', true)->firstOrFail(), $level);

            return $materials->count();
        });
    }

    public function enableReadyAndSchedule(AcademicYear $year, Level $level, string $reason, ?int $userId): array
    {
        Validator::make(compact('reason'), ['reason' => ['required', 'string', 'min:5', 'max:500']], [
            'reason.min' => 'Alasan bulk minimal 5 karakter.',
        ])->validate();

        return DB::transaction(function () use ($year, $level, $reason, $userId) {
            $this->presets->syncLevel($level);
            $this->catalog->syncLevel($level);
            $ready = $level->materialCatalogItems()->where('source_kind', 'ggb')
                ->where('mapping_status', 'mapped')->whereNotNull('rpp_matrix_column_id')
                ->whereIn('semester_scope', ['1', '2'])
                ->where(fn ($query) => $query->where('source_semester_scope', '!=', 'general')->orWhere('semester_confirmed', true))
                ->whereHas('matrixColumn', fn ($column) => $column->where('is_active', true))
                ->lockForUpdate()->get();
            $ready->each->update(['auto_include' => true, 'last_edited_by' => $userId]);
            $scheduled = 0;
            foreach ([1, 2] as $semester) {
                $plan = RppPlan::query()->where('academic_year_id', $year->id)->where('level_id', $level->id)->where('semester', $semester)->firstOrFail();
                $scheduled += $this->rebuildForPlan($plan, $userId);
            }
            $coverage = $this->catalog->coverage(RppPlan::query()->where('academic_year_id', $year->id)->where('level_id', $level->id)->firstOrFail());
            $this->invalidateAnnualValidation($year, $level, $coverage['percent']);
            DB::table('activity_logs')->insert([
                'user_id' => $userId, 'action' => 'rpp.ggb_annual_bulk_scheduled',
                'details' => json_encode(['academic_year_id' => $year->id, 'level_id' => $level->id, 'ready_count' => $ready->count(),
                    'scheduled_count' => $scheduled, 'coverage' => $coverage['percent'], 'reason' => trim($reason)], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['ready' => $ready->count(), 'scheduled' => $scheduled, 'coverage' => $coverage];
        });
    }

    public function rebuildForPlan(RppPlan $plan, ?int $userId = null): int
    {
        $hasAutomaticGgb = RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)->where('source_kind', 'ggb')
            ->where('auto_include', true)->where('semester_scope', (string) $plan->semester)->exists();
        if (! $hasAutomaticGgb && ! $plan->items()->where('source', 'ggb_auto')->exists()) {
            return 0;
        }
        $weeks = $this->calendar->weeksForPlan($plan, true);
        $plan->items()->where('source', 'ggb_auto')->where('is_locked', false)->delete();
        if ($weeks->isEmpty()) {
            return 0;
        }
        $coveredIds = RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)->where('source_kind', 'ggb')
            ->whereHas('placements.plan', fn ($query) => $query->where('academic_year_id', $plan->academic_year_id)->where('level_id', $plan->level_id))
            ->pluck('id');
        $materials = RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)->where('source_kind', 'ggb')
            ->where('auto_include', true)->where('mapping_status', 'mapped')->where('semester_confirmed', true)
            ->where('semester_scope', (string) $plan->semester)->whereNotNull('rpp_matrix_column_id')
            ->whereNotIn('id', $coveredIds)->with('matrixColumn')->orderBy('sort_order')->get();
        $count = 0;
        foreach ($materials->groupBy('rpp_matrix_column_id') as $columnId => $group) {
            foreach ($group->values() as $index => $material) {
                $weekIndex = min($weeks->count() - 1, (int) floor(($index * $weeks->count()) / max(1, $group->count())));
                $week = $weeks[$weekIndex];
                $position = (int) RppWeekItem::query()->where('rpp_plan_id', $plan->id)->where('calendar_week_id', $week->id)->max('position');
                $placement = RppWeekItem::query()->create([
                    'rpp_plan_id' => $plan->id, 'calendar_week_id' => $week->id, 'syllabus_item_id' => null,
                    'source_fingerprint' => 'catalog:'.$material->id, 'occurrence_no' => 1,
                    'rpp_matrix_column_id' => $columnId, 'strand' => $material->matrixColumn?->label ?: 'Materi GGB',
                    'content' => $material->title, 'source' => 'ggb_auto', 'is_locked' => false,
                    'position' => $position + 1, 'progress_kind' => 'materi_baru', 'lock_version' => 0,
                    'last_edited_by' => $userId,
                ]);
                $this->catalog->attachPlacement($placement, [$material->id]);
                $count++;
            }
        }
        $plan->update(['status' => 'draft', 'validated_at' => null]);

        return $count;
    }

    public function validateAnnual(AcademicYear $year, Level $level, ?int $userId): bool
    {
        $plan = RppPlan::query()->where('academic_year_id', $year->id)->where('level_id', $level->id)->firstOrFail();
        $coverage = $this->catalog->coverage($plan);
        $valid = (float) $coverage['percent'] >= 100;
        RppAnnualValidation::query()->updateOrCreate(
            ['academic_year_id' => $year->id, 'level_id' => $level->id],
            ['status' => $valid ? 'validated' : 'draft', 'coverage_percent' => $coverage['percent'],
                'validated_at' => $valid ? now() : null, 'validated_by' => $valid ? $userId : null]
        );
        DB::table('activity_logs')->insert([
            'user_id' => $userId, 'action' => 'rpp.ggb_annual_validation_attempted',
            'details' => json_encode(['academic_year_id' => $year->id, 'level_id' => $level->id, 'valid' => $valid,
                'coverage' => $coverage['percent']], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $valid;
    }

    private function invalidateAnnualValidation(AcademicYear $year, Level $level, ?float $coverage = null): void
    {
        RppAnnualValidation::query()->updateOrCreate(
            ['academic_year_id' => $year->id, 'level_id' => $level->id],
            ['status' => 'draft', 'coverage_percent' => $coverage ?? 0, 'validated_at' => null, 'validated_by' => null]
        );
    }
}
