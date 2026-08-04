<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RevisionBatch;
use App\Models\RevisionItem;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RppReadinessRepairService
{
    public function __construct(
        private readonly RppMaterialCatalogService $catalog,
        private readonly RppMatrixFillService $matrixFill,
        private readonly RppPlanner $planner,
        private readonly RppAnnualGgbService $annualGgb,
    ) {}

    public function preview(AcademicYear $year, Level $level): array
    {
        $plans = $this->plans($year, $level);
        $integrity = $plans->mapWithKeys(fn (RppPlan $plan) => [$plan->id => $this->integrityForPlan($plan)]);
        $matrix = $plans->mapWithKeys(fn (RppPlan $plan) => [$plan->id => $this->matrixFill->stats($plan)]);

        return [
            'legacy_links' => $integrity->sum('legacy_links'),
            'legacy_placements' => $integrity->sum('legacy_placements'),
            'invalid_placements' => $integrity->sum('invalid_placements'),
            'matrix_gaps' => $matrix->sum('missing'),
            'admin_gaps' => $matrix->sum(fn (array $stats) => $stats['gaps']
                ->filter(fn (array $gap) => str_contains($gap['reason'], 'Perlu Isian Admin'))->count()),
            'semesters' => $plans->mapWithKeys(fn (RppPlan $plan) => [
                $plan->semester => [
                    'legacy_links' => $integrity->get($plan->id)['legacy_links'] ?? 0,
                    'legacy_placements' => $integrity->get($plan->id)['legacy_placements'] ?? 0,
                    'invalid_placements' => $integrity->get($plan->id)['invalid_placements'] ?? 0,
                    'matrix_gaps' => $matrix->get($plan->id)['missing'] ?? 0,
                ],
            ])->all(),
        ];
    }

    public function integrityForPlan(RppPlan $plan): array
    {
        $placements = $this->placementsWithLegacyLinks($plan, false);

        return [
            'plan_id' => $plan->id,
            'legacy_links' => $placements->sum(fn (RppWeekItem $item) => $item->materials->where('is_schedulable', false)->count()),
            'legacy_placements' => $placements->count(),
            'invalid_placements' => $placements->filter(fn (RppWeekItem $item) => $this->isTrulyInvalid($item))->count(),
        ];
    }

    public function repair(AcademicYear $year, Level $level, ?int $userId = null): array
    {
        return DB::transaction(function () use ($year, $level, $userId) {
            $plans = $this->plans($year, $level);
            $before = $this->preview($year, $level);
            $batch = null;
            $removedLinks = 0;
            $removedPlacements = 0;
            $linkedActivities = 0;

            foreach ($plans as $plan) {
                foreach ($this->placementsWithLegacyLinks($plan, true) as $placement) {
                    $beforeIds = $placement->materials->pluck('id')->sort()->values();
                    if ($this->isTrulyInvalid($placement)) {
                        $batch ??= $this->newBatch($userId);
                        $this->recordRevision($batch, $placement, $beforeIds, collect(), true);
                        $removedLinks += $beforeIds->count();
                        $removedPlacements++;
                        $placement->delete();

                        continue;
                    }

                    $invalidIds = $placement->materials->where('is_schedulable', false)->pluck('id');
                    $placement->materials()->detach($invalidIds);
                    $removedLinks += $invalidIds->count();

                    $activityIds = $this->matchingActivityIds($placement, $level);
                    if ($activityIds->isNotEmpty()) {
                        $placement->materials()->syncWithoutDetaching($activityIds->all());
                        $linkedActivities += $activityIds->count();
                    } elseif ($placement->syllabus_item_id) {
                        $this->catalog->attachPlacement($placement);
                    }

                    $afterIds = $placement->materials()->pluck('rpp_material_catalog_items.id')->sort()->values();
                    if ($beforeIds->all() !== $afterIds->all()) {
                        $batch ??= $this->newBatch($userId);
                        $this->recordRevision($batch, $placement, $beforeIds, $afterIds, false);
                        $placement->forceFill([
                            'lock_version' => (int) $placement->lock_version + 1,
                            'last_edited_by' => $userId,
                        ])->save();
                    }
                }
            }

            if ($batch) {
                $batch->update(['item_count' => $batch->items()->count()]);
            }

            $filled = 0;
            foreach ($plans as $plan) {
                $beforeMissing = $this->matrixFill->stats($plan)['missing'];
                $afterStats = $this->matrixFill->fill($plan, $userId);
                $filled += max(0, $beforeMissing - $afterStats['missing']);
            }

            $ggbRestored = 0;
            $coveragePlan = $plans->first();
            $missingBeforeRestore = $coveragePlan ? (int) $this->catalog->coverage($coveragePlan)['missing'] : 0;
            if ($coveragePlan && $missingBeforeRestore > 0) {
                $ggbRestored = $this->annualGgb->restoreMissing($year, $level, $userId);
            }

            foreach ($plans as $plan) {
                $this->planner->refreshCoverage($plan);
                $plan->update(['status' => 'draft', 'validated_at' => null]);
            }

            $after = $this->preview($year, $level);
            DB::table('activity_logs')->insert([
                'user_id' => $userId,
                'action' => 'rpp.readiness_repaired',
                'details' => json_encode([
                    'academic_year_id' => $year->id,
                    'level_id' => $level->id,
                    'legacy_links_removed' => $removedLinks,
                    'placements_removed' => $removedPlacements,
                    'activities_linked' => $linkedActivities,
                    'matrix_gaps_filled' => $filled,
                    'ggb_placements_restored' => $ggbRestored,
                    'remaining' => $after,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'legacy_links_removed' => $removedLinks,
                'placements_removed' => $removedPlacements,
                'activities_linked' => $linkedActivities,
                'matrix_gaps_filled' => $filled,
                'ggb_placements_restored' => $ggbRestored,
                'before' => $before,
                'after' => $after,
                'batch_uuid' => $batch?->uuid,
            ];
        });
    }

    private function plans(AcademicYear $year, Level $level): Collection
    {
        return RppPlan::query()
            ->where('academic_year_id', $year->id)
            ->where('level_id', $level->id)
            ->whereIn('semester', [1, 2])
            ->orderBy('semester')
            ->get();
    }

    private function placementsWithLegacyLinks(RppPlan $plan, bool $lock): Collection
    {
        $query = $plan->items()
            ->whereHas('materials', fn ($query) => $query->where('is_schedulable', false))
            ->with(['materials', 'matrixColumn']);

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    private function isTrulyInvalid(RppWeekItem $placement): bool
    {
        return $placement->syllabus_item_id === null
            && in_array($placement->source, ['ggb_auto'], true)
            && $placement->materials->where('is_schedulable', true)->isEmpty();
    }

    private function matchingActivityIds(RppWeekItem $placement, Level $level): Collection
    {
        if (! $placement->rpp_matrix_column_id) {
            return collect();
        }

        $content = Str::of($placement->content)->lower()->ascii()->squish()->toString();

        return RppMaterialCatalogItem::query()
            ->where('level_id', $level->id)
            ->where('rpp_matrix_column_id', $placement->rpp_matrix_column_id)
            ->where('source_kind', 'activity')
            ->where('is_schedulable', true)
            ->where('is_active', true)
            ->get()
            ->filter(fn (RppMaterialCatalogItem $activity) => str_contains(
                $content,
                Str::of($activity->title)->lower()->ascii()->squish()->toString(),
            ))
            ->pluck('id')
            ->values();
    }

    private function newBatch(?int $userId): RevisionBatch
    {
        return RevisionBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $userId,
            'action' => 'normalize',
            'reason' => 'Perbaikan kesiapan RPP: bersihkan relasi Subjudul/Artefak lama.',
        ]);
    }

    private function recordRevision(
        RevisionBatch $batch,
        RppWeekItem $placement,
        Collection $beforeIds,
        Collection $afterIds,
        bool $removed,
    ): void {
        RevisionItem::query()->create([
            'revision_batch_id' => $batch->id,
            'revisable_type' => 'rpp',
            'revisable_id' => $placement->id,
            'before_values' => ['material_catalog_ids' => $beforeIds->all()],
            'after_values' => $removed
                ? ['removed_by_readiness_repair' => true]
                : ['material_catalog_ids' => $afterIds->all()],
            'before_lock_version' => (int) $placement->lock_version,
            'after_lock_version' => (int) $placement->lock_version + 1,
        ]);
    }
}
