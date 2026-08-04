<?php

namespace App\Services;

use App\Models\RppMaterialCatalogItem;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RppMatrixFillService
{
    public function __construct(
        private readonly AcademicCalendarService $calendar,
    ) {}

    /**
     * Melengkapi setiap perpotongan minggu efektif dan kolom aktif.
     * Materi manual/terkunci tidak pernah diubah. Pengisi otomatis lama dibuat
     * ulang agar perubahan kalender dan Bank Kegiatan tetap idempoten.
     */
    public function fill(RppPlan $plan, ?int $userId = null): array
    {
        return DB::transaction(function () use ($plan, $userId) {
            $weeks = $this->calendar->weeksForPlan($plan, true)->values();
            $columns = $plan->level->matrixColumns()->where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')->get();

            $plan->items()->whereIn('source', ['reinforcement_auto', 'activity_auto'])
                ->where('is_locked', false)->delete();

            if ($weeks->isEmpty() || $columns->isEmpty()) {
                return $this->stats($plan);
            }

            $plan->load(['items.materials', 'items.week']);
            $weekIds = $weeks->pluck('id');
            $existing = $plan->items->whereIn('calendar_week_id', $weekIds);
            $positions = $existing->groupBy('calendar_week_id')
                ->map(fn (Collection $items) => (int) $items->max('position'))->all();
            $rows = [];
            $materialIdsByKey = [];
            $now = now();

            foreach ($columns as $column) {
                $columnItems = $existing->where('rpp_matrix_column_id', $column->id)
                    ->sortBy(fn (RppWeekItem $item) => sprintf('%08d:%08d:%08d', $item->week?->week_number ?? 0, $item->position, $item->id))
                    ->values();
                $activities = RppMaterialCatalogItem::query()
                    ->where('level_id', $plan->level_id)
                    ->where('rpp_matrix_column_id', $column->id)
                    ->where('source_kind', 'activity')
                    ->where('is_schedulable', true)
                    ->where('is_active', true)
                    ->where('rotation_enabled', true)
                    ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
                    ->orderBy('sort_order')->orderBy('id')->get();

                $seedItems = $columnItems->filter(fn (RppWeekItem $item) => ! in_array($item->source, ['reinforcement_auto', 'activity_auto'], true));
                if ($seedItems->isEmpty()) {
                    $seedItems = RppWeekItem::query()
                        ->where('rpp_matrix_column_id', $column->id)
                        ->whereNotIn('source', ['reinforcement_auto', 'activity_auto'])
                        ->whereHas('plan', fn ($query) => $query
                            ->where('academic_year_id', $plan->academic_year_id)
                            ->where('level_id', $plan->level_id)
                            ->where('id', '!=', $plan->id))
                        ->with(['materials', 'week'])
                        ->orderBy('calendar_week_id')->orderBy('position')->orderBy('id')->get();
                }

                foreach ($weeks as $weekIndex => $week) {
                    if ($columnItems->contains(fn (RppWeekItem $item) => (int) $item->calendar_week_id === (int) $week->id)) {
                        continue;
                    }

                    if ($activities->isNotEmpty()) {
                        $activity = $activities[$weekIndex % $activities->count()];
                        $fingerprint = 'activity:'.$activity->id;
                        $this->queuePlacement(
                            $rows, $materialIdsByKey, $positions, $plan, $week->id, $column->id,
                            $column->label, $activity->title, 'activity_auto', $fingerprint,
                            'materi_baru', $userId, [$activity->id], null, true, $now
                        );

                        continue;
                    }

                    if ($seedItems->isNotEmpty()) {
                        $seed = $seedItems[$weekIndex % $seedItems->count()];
                        $fingerprint = 'reinforcement:'.$column->id.':'.$seed->source_fingerprint;
                        $this->queuePlacement(
                            $rows, $materialIdsByKey, $positions, $plan, $week->id, $column->id,
                            $column->label, $seed->content, 'reinforcement_auto', $fingerprint,
                            'penguatan', $userId, $seed->materials->pluck('id')->all(), $seed,
                            (int) $seed->rpp_plan_id === (int) $plan->id, $now
                        );
                    }
                }
            }

            collect($rows)->chunk(500)->each(fn (Collection $chunk) => DB::table('rpp_week_items')->insert($chunk->all()));
            if ($materialIdsByKey !== []) {
                $generated = RppWeekItem::query()->where('rpp_plan_id', $plan->id)
                    ->whereIn('source', ['reinforcement_auto', 'activity_auto'])
                    ->get(['id', 'calendar_week_id', 'rpp_matrix_column_id', 'source_fingerprint']);
                $pivotRows = $generated->flatMap(function (RppWeekItem $item) use ($materialIdsByKey, $now) {
                    $key = $this->placementKey($item->calendar_week_id, $item->rpp_matrix_column_id, $item->source_fingerprint);

                    return collect($materialIdsByKey[$key] ?? [])->map(fn (int $materialId) => [
                        'rpp_week_item_id' => $item->id,
                        'rpp_material_catalog_item_id' => $materialId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
                $pivotRows->chunk(1000)->each(fn (Collection $chunk) => DB::table('rpp_week_item_materials')->insertOrIgnore($chunk->all()));
            }

            $plan->update(['status' => 'draft', 'validated_at' => null]);

            return $this->stats($plan);
        });
    }

    public function stats(RppPlan $plan): array
    {
        $weeks = $this->calendar->weeksForPlan($plan, true)->values();
        $columns = $plan->level->matrixColumns()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        $occupied = $plan->items()
            ->whereIn('calendar_week_id', $weeks->pluck('id'))
            ->whereIn('rpp_matrix_column_id', $columns->pluck('id'))
            ->select(['calendar_week_id', 'rpp_matrix_column_id'])
            ->distinct()->get()
            ->mapWithKeys(fn ($item) => [$item->calendar_week_id.':'.$item->rpp_matrix_column_id => true]);
        $gaps = collect();
        foreach ($weeks as $week) {
            foreach ($columns as $column) {
                if (! $occupied->has($week->id.':'.$column->id)) {
                    $gaps->push([
                        'week_id' => $week->id,
                        'week_number' => $week->week_number,
                        'starts_on' => $week->starts_on,
                        'column_id' => $column->id,
                        'column' => $column->label,
                        'reason' => $this->gapReason($plan, $column->id),
                    ]);
                }
            }
        }
        $total = $weeks->count() * $columns->count();
        $filled = $total - $gaps->count();

        return [
            'total' => $total,
            'filled' => $filled,
            'missing' => $gaps->count(),
            'percent' => $total > 0 ? round(($filled / $total) * 100, 1) : 0,
            'gaps' => $gaps,
        ];
    }

    private function queuePlacement(
        array &$rows,
        array &$materialIdsByKey,
        array &$positions,
        RppPlan $plan,
        int $weekId,
        int $columnId,
        string $strand,
        string $content,
        string $source,
        string $fingerprint,
        string $progressKind,
        ?int $userId,
        array $materialIds,
        ?RppWeekItem $seed = null,
        bool $keepSyllabus = true,
        mixed $now = null,
    ): void {
        $positions[$weekId] = ($positions[$weekId] ?? 0) + 1;
        $rows[] = [
            'rpp_plan_id' => $plan->id,
            'calendar_week_id' => $weekId,
            // Penguatan lintas semester tetap membawa relasi katalog sumber,
            // tetapi tidak dihitung sebagai cakupan Silabus semester aktif.
            'syllabus_item_id' => $keepSyllabus ? $seed?->syllabus_item_id : null,
            'source_fingerprint' => $fingerprint,
            'occurrence_no' => 1,
            'rpp_matrix_column_id' => $columnId,
            'strand' => $strand,
            'content' => $content,
            'source' => $source,
            'is_locked' => false,
            'position' => $positions[$weekId],
            'progress_start' => $seed?->progress_start,
            'progress_end' => $seed?->progress_end,
            'progress_kind' => $progressKind,
            'lock_version' => 0,
            'last_edited_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($materialIds !== []) {
            $materialIdsByKey[$this->placementKey($weekId, $columnId, $fingerprint)] = array_values(array_unique($materialIds));
        }
    }

    private function placementKey(int $weekId, int $columnId, string $fingerprint): string
    {
        return $weekId.'|'.$columnId.'|'.$fingerprint;
    }

    private function gapReason(RppPlan $plan, int $columnId): string
    {
        $hasActivity = RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)
            ->where('rpp_matrix_column_id', $columnId)->where('source_kind', 'activity')
            ->where('is_schedulable', true)->where('is_active', true)
            ->whereIn('semester_scope', [(string) $plan->semester, 'both'])->exists();
        $hasSource = $plan->items()->where('rpp_matrix_column_id', $columnId)
            ->whereNotIn('source', ['reinforcement_auto', 'activity_auto'])->exists();

        return $hasActivity || $hasSource
            ? 'Jalankan Susun Otomatis untuk membuat penguatan atau rotasi kegiatan.'
            : 'Kolom belum mempunyai materi maupun Bank Kegiatan. Perlu Isian Admin.';
    }
}
