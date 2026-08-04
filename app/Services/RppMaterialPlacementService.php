<?php

namespace App\Services;

use App\Models\CalendarWeek;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppMatrixColumn;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RppMaterialPlacementService
{
    public function __construct(
        private readonly RppPlanner $planner,
        private readonly RppMaterialCatalogService $catalog,
        private readonly AcademicCalendarService $calendar,
    ) {}

    public function addToCell(RppPlan $plan, int $weekId, int $columnId, array $catalogIds, string $reason, ?int $userId): int
    {
        $ids = collect($catalogIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        Validator::make(compact('ids', 'reason'), [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['reason.min' => 'Alasan penambahan minimal 5 karakter.'])->validate();

        return DB::transaction(function () use ($plan, $weekId, $columnId, $ids, $reason, $userId) {
            $lockedPlan = RppPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $week = CalendarWeek::query()->where('academic_year_id', $lockedPlan->academic_year_id)
                ->where('semester', $lockedPlan->semester)->lockForUpdate()->find($weekId);
            if (! $week || ! $this->calendar->isEffective($lockedPlan, $week)) {
                throw ValidationException::withMessages(['week' => 'Materi hanya dapat ditambahkan pada minggu efektif semester aktif.']);
            }
            $column = RppMatrixColumn::query()->where('level_id', $lockedPlan->level_id)->where('is_active', true)->find($columnId);
            if (! $column) {
                throw ValidationException::withMessages(['column' => 'Kolom materi tidak aktif atau berasal dari jenjang lain.']);
            }
            $materials = RppMaterialCatalogItem::query()->where('level_id', $lockedPlan->level_id)
                ->where('is_schedulable', true)->where('is_active', true)
                ->where('rpp_matrix_column_id', $column->id)->where('mapping_status', '!=', 'unmapped')
                ->whereIn('semester_scope', [(string) $lockedPlan->semester, 'both'])
                ->where(fn ($query) => $query->where('source_semester_scope', '!=', 'general')->orWhere('semester_confirmed', true))
                ->whereIn('id', $ids)->with(['ggbItem.syllabusItems.matrixMapping.column'])->lockForUpdate()->get();
            if ($materials->count() !== count($ids)) {
                throw ValidationException::withMessages(['material' => 'Sebagian materi tidak sesuai kolom, belum dipetakan, atau berasal dari jenjang lain.']);
            }

            $position = (int) RppWeekItem::query()->where('rpp_plan_id', $lockedPlan->id)
                ->where('calendar_week_id', $week->id)->max('position');
            foreach ($materials->sortBy('sort_order') as $material) {
                $used = $material->placements()->where('rpp_plan_id', $lockedPlan->id)->exists();
                $fingerprint = 'catalog:'.$material->id;
                $syllabusId = $material->syllabus_item_id;
                if (! $syllabusId && $material->ggbItem) {
                    $syllabusId = $material->ggbItem->syllabusItems
                        ->first(fn ($syllabus) => ! $syllabus->is_duplicate
                            && ! $syllabus->is_source_artifact
                            && in_array($syllabus->semester_scope, [(string) $lockedPlan->semester, 'both'], true)
                            && (int) $syllabus->matrixMapping?->rpp_matrix_column_id === (int) $column->id)?->id;
                }
                $occurrence = (int) RppWeekItem::query()->where('rpp_plan_id', $lockedPlan->id)
                    ->where('calendar_week_id', $week->id)->where('source_fingerprint', $fingerprint)->max('occurrence_no') + 1;
                $item = RppWeekItem::query()->create([
                    'rpp_plan_id' => $lockedPlan->id,
                    'calendar_week_id' => $week->id,
                    'syllabus_item_id' => $syllabusId,
                    'source_fingerprint' => $fingerprint,
                    'occurrence_no' => $occurrence,
                    'rpp_matrix_column_id' => $column->id,
                    'strand' => $column->label,
                    'content' => $material->title,
                    'source' => 'manual',
                    'is_locked' => true,
                    'position' => ++$position,
                    'progress_kind' => $used ? 'penguatan' : 'materi_baru',
                    'lock_version' => 1,
                    'last_edited_by' => $userId,
                ]);
                $this->catalog->attachPlacement($item, [$material->id]);
            }

            $this->planner->refreshCoverage($lockedPlan);
            $lockedPlan->update(['status' => 'draft', 'validated_at' => null]);
            DB::table('activity_logs')->insert([
                'user_id' => $userId,
                'action' => 'rpp.catalog_materials_added',
                'details' => json_encode([
                    'plan_id' => $lockedPlan->id,
                    'calendar_week_id' => $week->id,
                    'rpp_matrix_column_id' => $column->id,
                    'catalog_item_ids' => $ids,
                    'reason' => trim($reason),
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return count($ids);
        });
    }

    public function addOneOffToCell(
        RppPlan $plan,
        int $weekId,
        int $columnId,
        string $content,
        string $reason,
        ?int $userId,
    ): RppWeekItem {
        Validator::make(compact('content', 'reason'), [
            'content' => ['required', 'string', 'min:3', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'content.required' => 'Isi kegiatan manual wajib diisi.',
            'content.min' => 'Isi kegiatan manual minimal 3 karakter.',
            'reason.required' => 'Alasan tindakan wajib diisi.',
            'reason.min' => 'Alasan tindakan minimal 5 karakter.',
        ])->validate();

        return DB::transaction(function () use ($plan, $weekId, $columnId, $content, $reason, $userId) {
            $lockedPlan = RppPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $week = CalendarWeek::query()->where('academic_year_id', $lockedPlan->academic_year_id)
                ->where('semester', $lockedPlan->semester)->lockForUpdate()->find($weekId);
            if (! $week || ! $this->calendar->isEffective($lockedPlan, $week)) {
                throw ValidationException::withMessages(['week' => 'Isian manual hanya dapat ditambahkan pada minggu efektif semester aktif.']);
            }
            $column = RppMatrixColumn::query()->where('level_id', $lockedPlan->level_id)->where('is_active', true)->find($columnId);
            if (! $column) {
                throw ValidationException::withMessages(['column' => 'Kolom materi tidak aktif atau berasal dari jenjang lain.']);
            }
            $position = (int) RppWeekItem::query()->where('rpp_plan_id', $lockedPlan->id)
                ->where('calendar_week_id', $week->id)->max('position');
            $item = RppWeekItem::query()->create([
                'rpp_plan_id' => $lockedPlan->id,
                'calendar_week_id' => $week->id,
                'syllabus_item_id' => null,
                'source_fingerprint' => 'manual:'.Str::uuid(),
                'occurrence_no' => 1,
                'rpp_matrix_column_id' => $column->id,
                'strand' => $column->label,
                'content' => trim($content),
                'source' => 'manual',
                'is_locked' => true,
                'position' => $position + 1,
                'progress_kind' => 'materi_baru',
                'lock_version' => 1,
                'last_edited_by' => $userId,
            ]);
            $this->planner->refreshCoverage($lockedPlan);
            $lockedPlan->update(['status' => 'draft', 'validated_at' => null]);
            DB::table('activity_logs')->insert([
                'user_id' => $userId,
                'action' => 'rpp.one_off_activity_added',
                'details' => json_encode([
                    'plan_id' => $lockedPlan->id,
                    'calendar_week_id' => $week->id,
                    'rpp_matrix_column_id' => $column->id,
                    'content' => trim($content),
                    'reason' => trim($reason),
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $item;
        });
    }
}
