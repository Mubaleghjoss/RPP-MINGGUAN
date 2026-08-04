<?php

namespace App\Services;

use App\Models\GgbItem;
use App\Models\Level;
use App\Models\RevisionBatch;
use App\Models\RevisionItem;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RppMaterialCatalogService
{
    public const GGB_STATUSES = ['all', 'used', 'missing', 'ready', 'semester', 'mapping', 'conflict'];

    private array $catalogMaps = [];

    private array $usedCodes = [];

    public function __construct(
        private readonly RppMatrixPresetService $presets,
        private readonly GgbOutlineService $outline,
    ) {}

    public function syncAll(): void
    {
        Level::query()->orderBy('sort_order')->each(fn (Level $level) => $this->syncLevel($level));
    }

    public function syncLevel(Level $level): void
    {
        DB::transaction(function () use ($level) {
            $this->outline->classifyLevel($level);
            $this->presets->syncLevel($level);
            $this->usedCodes[$level->id] = RppMaterialCatalogItem::query()->where('level_id', $level->id)
                ->pluck('display_code')->flip()->all();
            $columns = $level->matrixColumns()->where('is_active', true)->orderBy('sort_order')->get();
            $allGgb = $level->ggbItems()->with(['syllabusItems.matrixMapping.column'])->orderBy('sort_order')->get();
            $byId = $allGgb->keyBy('id');
            $leaves = $allGgb->where('rpp_role', 'material');
            $existingGgb = RppMaterialCatalogItem::query()->where('level_id', $level->id)->whereNotNull('ggb_item_id')->get()->keyBy('ggb_item_id');
            $newGgb = [];
            $now = now();

            foreach ($leaves as $leaf) {
                [$column, $status] = $this->columnForGgb($leaf, $columns, $byId);
                $scope = $this->semesterScope($leaf->syllabusItems);
                $sourceScope = in_array($scope, ['1', '2'], true) ? $scope : 'general';
                $catalog = $existingGgb->get($leaf->id);
                if (! $catalog) {
                    $newGgb[] = [
                        'level_id' => $level->id,
                        'rpp_matrix_column_id' => $column?->id,
                        'ggb_item_id' => $leaf->id,
                        'syllabus_item_id' => null,
                        'source_kind' => 'ggb',
                        'is_schedulable' => true,
                        'display_code' => $this->nextCode($level, $column?->label ?: $leaf->subaspect ?: 'Materi'),
                        'title' => $leaf->title,
                        'semester_scope' => $scope,
                        'source_semester_scope' => $sourceScope,
                        'semester_confirmed' => $sourceScope !== 'general',
                        'auto_include' => false,
                        'is_active' => true,
                        'rotation_enabled' => false,
                        'origin_key' => null,
                        'mapping_status' => $status,
                        'sort_order' => $leaf->sort_order,
                        'lock_version' => 0,
                        'last_edited_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    continue;
                }
                $changes = [
                    'title' => $leaf->title,
                    'sort_order' => $leaf->sort_order,
                    'source_semester_scope' => $sourceScope,
                    'is_schedulable' => true,
                    'is_active' => true,
                ];
                if ($sourceScope !== 'general' || ! $catalog->semester_confirmed) {
                    $changes['semester_scope'] = $scope;
                    $changes['semester_confirmed'] = $sourceScope !== 'general';
                }
                if (! $catalog->last_edited_by) {
                    $changes['rpp_matrix_column_id'] = $column?->id;
                    $changes['mapping_status'] = $status;
                }
                if ($catalog->only(array_keys($changes)) != $changes) {
                    $catalog->forceFill($changes)->save();
                }
            }
            collect($newGgb)->chunk(500)->each(fn ($chunk) => DB::table('rpp_material_catalog_items')->insert($chunk->all()));
            RppMaterialCatalogItem::query()->where('level_id', $level->id)->where('source_kind', 'ggb')
                ->whereNotIn('ggb_item_id', $leaves->pluck('id'))
                ->update(['is_schedulable' => false, 'auto_include' => false]);
            $this->outline->removeInvalidPlacements($level);

            unset($this->catalogMaps[$level->id]);
            $catalogMap = $this->catalogMap($level->id);
            $ggbCatalogIds = RppMaterialCatalogItem::query()->where('level_id', $level->id)
                ->where('source_kind', 'ggb')->pluck('id')->flip()->all();
            $existingSyllabus = RppMaterialCatalogItem::query()->where('level_id', $level->id)->whereNotNull('syllabus_item_id')->get()->keyBy('syllabus_item_id');
            $newSyllabus = [];
            $supplementalSyllabusIds = [];
            $level->syllabusItems()->where('is_duplicate', false)->where('is_source_artifact', false)->with('matrixMapping.column')->orderBy('sort_order')->get()
                ->reject(fn (SyllabusItem $syllabus) => $this->presets->isSourceArtifact($syllabus))
                ->each(function (SyllabusItem $syllabus) use ($level, $catalogMap, $ggbCatalogIds, $existingSyllabus, &$newSyllabus, &$supplementalSyllabusIds, $now) {
                    $hasDetailedGgb = collect($catalogMap[$syllabus->id] ?? [])->contains(fn ($id) => isset($ggbCatalogIds[$id]));
                    if ($hasDetailedGgb) {
                        return;
                    }
                    $supplementalSyllabusIds[] = $syllabus->id;
                    $column = $syllabus->matrixMapping?->column;
                    $catalog = $existingSyllabus->get($syllabus->id);
                    if (! $catalog) {
                        $sourceScope = in_array((string) $syllabus->source_semester, ['1', '2'], true)
                            ? (string) $syllabus->source_semester : 'general';
                        $newSyllabus[] = [
                            'level_id' => $level->id,
                            'rpp_matrix_column_id' => $column?->is_active ? $column->id : null,
                            'ggb_item_id' => null,
                            'syllabus_item_id' => $syllabus->id,
                            'source_kind' => 'syllabus',
                            'is_schedulable' => true,
                            'display_code' => $this->nextCode($level, $column?->label ?: $syllabus->category ?: 'Materi'),
                            'title' => $syllabus->title,
                            'semester_scope' => $syllabus->semester_scope,
                            'source_semester_scope' => $sourceScope,
                            'semester_confirmed' => $sourceScope !== 'general',
                            'auto_include' => false,
                            'is_active' => true,
                            'rotation_enabled' => false,
                            'origin_key' => null,
                            'mapping_status' => $column?->is_active ? 'mapped' : 'unmapped',
                            'sort_order' => 100000 + $syllabus->sort_order,
                            'lock_version' => 0,
                            'last_edited_by' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        return;
                    }
                    $changes = [
                        'title' => $syllabus->title,
                        'sort_order' => 100000 + $syllabus->sort_order,
                        'is_schedulable' => true,
                        'is_active' => true,
                    ];
                    $sourceScope = in_array((string) $syllabus->source_semester, ['1', '2'], true)
                        ? (string) $syllabus->source_semester : 'general';
                    $changes['source_semester_scope'] = $sourceScope;
                    if ($sourceScope !== 'general' || ! $catalog->semester_confirmed) {
                        $changes['semester_scope'] = $syllabus->semester_scope;
                        $changes['semester_confirmed'] = $sourceScope !== 'general';
                    }
                    if (! $catalog->last_edited_by) {
                        $changes['rpp_matrix_column_id'] = $column?->is_active ? $column->id : null;
                        $changes['mapping_status'] = $column?->is_active ? 'mapped' : 'unmapped';
                    }
                    if ($catalog->only(array_keys($changes)) != $changes) {
                        $catalog->forceFill($changes)->save();
                    }
                });
            collect($newSyllabus)->chunk(500)->each(fn ($chunk) => DB::table('rpp_material_catalog_items')->insert($chunk->all()));
            RppMaterialCatalogItem::query()->where('level_id', $level->id)->where('source_kind', 'syllabus')
                ->whereNotIn('syllabus_item_id', $supplementalSyllabusIds)->whereDoesntHave('placements')->delete();

            $this->syncActivities($level, $now);
            unset($this->catalogMaps[$level->id]);
            $this->backfillPlacements($level);
        });
    }

    public function catalogIdsForSyllabus(SyllabusItem $syllabus): Collection
    {
        $map = $this->catalogMap($syllabus->level_id);

        return collect($map[$syllabus->id] ?? []);
    }

    public function attachPlacement(RppWeekItem $item, array|Collection|null $catalogIds = null): void
    {
        if ($catalogIds === null && $item->syllabus_item_id) {
            $syllabus = $item->syllabusItem ?: SyllabusItem::query()->find($item->syllabus_item_id);
            $catalogIds = $syllabus ? $this->catalogIdsForSyllabus($syllabus) : collect();
        }
        $ids = collect($catalogIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isNotEmpty()) {
            $item->materials()->syncWithoutDetaching($ids->all());
        }
    }

    public function createActivity(
        Level $level,
        int $columnId,
        string $title,
        string $semesterScope,
        bool $rotationEnabled,
        string $reason,
        ?int $userId,
    ): RppMaterialCatalogItem {
        Validator::make(compact('columnId', 'title', 'semesterScope', 'reason'), [
            'columnId' => ['required', 'integer'],
            'title' => ['required', 'string', 'min:3', 'max:500'],
            'semesterScope' => ['required', 'in:1,2,both'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'title.required' => 'Nama kegiatan wajib diisi.',
            'title.min' => 'Nama kegiatan minimal 3 karakter.',
            'reason.required' => 'Alasan tindakan wajib diisi.',
            'reason.min' => 'Alasan tindakan minimal 5 karakter.',
        ])->validate();

        return DB::transaction(function () use ($level, $columnId, $title, $semesterScope, $rotationEnabled, $reason, $userId) {
            $column = $level->matrixColumns()->where('is_active', true)->lockForUpdate()->find($columnId);
            if (! $column) {
                throw ValidationException::withMessages([
                    'activityColumnId' => 'Kolom RPP tidak aktif atau bukan milik jenjang ini.',
                ]);
            }
            $this->usedCodes[$level->id] = RppMaterialCatalogItem::query()->where('level_id', $level->id)
                ->pluck('display_code')->flip()->all();
            $item = RppMaterialCatalogItem::query()->create([
                'level_id' => $level->id,
                'rpp_matrix_column_id' => $column->id,
                'source_kind' => 'activity',
                'is_schedulable' => true,
                'display_code' => $this->nextCode($level, $column->label),
                'title' => trim($title),
                'semester_scope' => $semesterScope,
                'source_semester_scope' => 'general',
                'semester_confirmed' => true,
                'auto_include' => false,
                'is_active' => true,
                'rotation_enabled' => $rotationEnabled,
                'origin_key' => 'activity:manual:'.Str::uuid(),
                'mapping_status' => 'mapped',
                'sort_order' => 300000 + (int) RppMaterialCatalogItem::query()->where('level_id', $level->id)->where('source_kind', 'activity')->max('sort_order') % 100000 + 1,
                'lock_version' => 1,
                'last_edited_by' => $userId,
            ]);
            $batch = RevisionBatch::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'action' => 'create',
                'reason' => trim($reason),
                'item_count' => 1,
            ]);
            RevisionItem::query()->create([
                'revision_batch_id' => $batch->id,
                'revisable_type' => 'material_catalog',
                'revisable_id' => $item->id,
                'before_values' => [],
                'after_values' => $item->only(['display_code', 'title', 'rpp_matrix_column_id', 'semester_scope', 'is_active', 'rotation_enabled']),
                'before_lock_version' => 0,
                'after_lock_version' => 1,
            ]);
            $level->plans()->update(['status' => 'draft', 'validated_at' => null]);
            DB::table('activity_logs')->insert([
                'user_id' => $userId,
                'action' => 'rpp.activity_created',
                'details' => json_encode(['level_id' => $level->id, 'catalog_item_id' => $item->id, 'reason' => trim($reason)], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $item;
        });
    }

    public function placementLabel(RppWeekItem $item): string
    {
        $item->loadMissing('materials');
        $codes = $item->materials->sortBy('sort_order')->pluck('display_code')->implode(', ');

        return $codes !== '' ? $codes.' — '.$item->content : $item->content;
    }

    public function coverage(RppPlan $plan): array
    {
        $query = $this->ggbQuery($plan);
        $counts = $this->ggbStatusCounts($plan);
        $semesterUsed = collect([1, 2])->mapWithKeys(fn ($semester) => [$semester => (clone $query)
            ->whereHas('placements.plan', fn ($annualPlan) => $annualPlan
                ->where('academic_year_id', $plan->academic_year_id)
                ->where('level_id', $plan->level_id)
                ->where('semester', $semester))->count()]);

        return [
            'total' => $counts['all'],
            'used' => $counts['used'],
            'missing' => $counts['missing'],
            'percent' => $counts['all'] ? round(($counts['used'] / $counts['all']) * 100, 2) : 100.0,
            'semester_used' => $semesterUsed->all(),
        ];
    }

    public function ggbStatusCounts(RppPlan $plan): array
    {
        $query = $this->ggbQuery($plan);

        return collect(self::GGB_STATUSES)->mapWithKeys(fn (string $status) => [
            $status => $this->applyGgbStatus(clone $query, $plan, $status)->count(),
        ])->all();
    }

    public function applyGgbStatus(Builder $query, RppPlan $plan, string $status): Builder
    {
        $annualPlanScope = fn (Builder $annualPlan) => $annualPlan
            ->where('academic_year_id', $plan->academic_year_id)
            ->where('level_id', $plan->level_id);
        $needsSemester = fn (Builder $material) => $material
            ->whereNotIn('semester_scope', ['1', '2'])
            ->orWhere('semester_confirmed', false);

        return match ($status) {
            'used' => $query->whereHas('placements.plan', $annualPlanScope),
            'missing' => $query->whereDoesntHave('placements.plan', $annualPlanScope),
            'ready' => $query->whereDoesntHave('placements.plan', $annualPlanScope)
                ->where('mapping_status', 'mapped')
                ->whereNotNull('rpp_matrix_column_id')
                ->whereIn('semester_scope', ['1', '2'])
                ->where(fn (Builder $material) => $material
                    ->where('source_semester_scope', '!=', 'general')
                    ->orWhere('semester_confirmed', true))
                ->whereHas('matrixColumn', fn (Builder $column) => $column->where('is_active', true)),
            'semester' => $query->where($needsSemester),
            'mapping' => $query->needsRppColumnConfirmation(),
            'conflict' => $query->where($needsSemester)->needsRppColumnConfirmation(),
            default => $query,
        };
    }

    private function ggbQuery(RppPlan $plan): Builder
    {
        return RppMaterialCatalogItem::query()
            ->where('level_id', $plan->level_id)
            ->where('source_kind', 'ggb')
            ->where('is_schedulable', true)
            ->where('is_active', true);
    }

    public function headerTree(Collection $columns): Collection
    {
        $tree = [];
        foreach ($columns->values() as $column) {
            $aspectLabel = trim((string) $column->aspect_label) ?: 'Materi Tambahan';
            $subaspectLabel = trim((string) $column->subaspect_label) ?: 'Tanpa Subaspek';
            $aspectIndex = count($tree) - 1;
            if ($aspectIndex < 0 || $tree[$aspectIndex]['label'] !== $aspectLabel) {
                $tree[] = ['label' => $aspectLabel, 'span' => 0, 'subaspects' => []];
                $aspectIndex++;
            }

            $subaspectIndex = count($tree[$aspectIndex]['subaspects']) - 1;
            if ($subaspectIndex < 0 || $tree[$aspectIndex]['subaspects'][$subaspectIndex]['label'] !== $subaspectLabel) {
                $tree[$aspectIndex]['subaspects'][] = ['label' => $subaspectLabel, 'span' => 0, 'columns' => collect()];
                $subaspectIndex++;
            }

            $tree[$aspectIndex]['span']++;
            $tree[$aspectIndex]['subaspects'][$subaspectIndex]['span']++;
            $tree[$aspectIndex]['subaspects'][$subaspectIndex]['columns']->push($column);
        }

        return collect($tree)->map(function (array $aspect) {
            $aspect['subaspects'] = collect($aspect['subaspects']);

            return $aspect;
        });
    }

    private function catalogMap(int $levelId): array
    {
        if (isset($this->catalogMaps[$levelId])) {
            return $this->catalogMaps[$levelId];
        }

        $catalog = RppMaterialCatalogItem::query()->where('level_id', $levelId)->where('is_schedulable', true)->get();
        $ggb = GgbItem::query()->where('level_id', $levelId)->get(['id', 'parent_id']);
        $parents = $ggb->pluck('parent_id', 'id');
        $ancestorCatalog = [];
        foreach ($catalog->whereNotNull('ggb_item_id') as $entry) {
            $cursor = $entry->ggb_item_id;
            while ($cursor) {
                $ancestorCatalog[$cursor][] = $entry->id;
                $cursor = $parents[$cursor] ?? null;
            }
        }

        $map = [];
        SyllabusItem::query()->where('level_id', $levelId)->where('is_duplicate', false)->where('is_source_artifact', false)->with('ggbItems:id,parent_id')->get()
            ->each(function (SyllabusItem $syllabus) use (&$map, $ancestorCatalog, $catalog) {
                $ids = $syllabus->ggbItems->flatMap(fn ($item) => $ancestorCatalog[$item->id] ?? [])->unique()->values();
                if ($ids->isEmpty()) {
                    $supplemental = $catalog->firstWhere('syllabus_item_id', $syllabus->id);
                    $ids = $supplemental ? collect([$supplemental->id]) : collect();
                }
                $map[$syllabus->id] = $ids->all();
            });

        return $this->catalogMaps[$levelId] = $map;
    }

    private function columnForGgb(GgbItem $ggb, Collection $columns, Collection $byId): array
    {
        $linked = $ggb->syllabusItems->filter(fn ($item) => ! $item->is_duplicate
            && ! $item->is_source_artifact
            && $item->matrixMapping?->column?->is_active)
            ->sortBy(fn ($item) => sprintf('%d-%08.4f-%010d', match ($item->pivot->status) {
                'sesuai' => 0, 'sebagian' => 1, default => 2,
            }, 1 - (float) $item->pivot->confidence, $item->sort_order));
        $linkedColumns = $linked->pluck('matrixMapping.column')->filter()->unique('id')->values();
        if ($linkedColumns->isNotEmpty()) {
            return [$linkedColumns->first(), $linkedColumns->count() > 1 ? 'needs_verification' : 'mapped'];
        }

        $lineage = collect([$ggb->aspect, $ggb->subaspect, $ggb->title]);
        $cursor = $ggb->parent_id;
        while ($cursor && $byId->has($cursor)) {
            $parent = $byId[$cursor];
            $lineage->push($parent->title);
            $cursor = $parent->parent_id;
        }
        $haystack = $this->normalize($lineage->implode(' '));
        $aspect = $this->normalize($ggb->aspect);
        $subaspect = $this->normalize($ggb->subaspect);
        $scored = $columns->map(function ($column) use ($haystack, $aspect, $subaspect) {
            $columnAspect = $this->normalize($column->aspect_label);
            $columnSubaspect = $this->normalize($column->subaspect_label);
            $score = ($aspect !== '' && str_contains($columnAspect, $aspect)) ? 80 : 0;
            $score += ($subaspect !== '' && str_contains($columnSubaspect, $subaspect)) ? 45 : 0;
            $tokens = collect(preg_split('/\s+/u', mb_strtolower($column->label)))->filter(fn ($token) => mb_strlen($token) >= 4);
            $score += $tokens->sum(fn ($token) => str_contains($haystack, $this->normalize($token)) ? 20 : 0);

            return ['column' => $column, 'score' => $score];
        })->sortByDesc('score')->values();
        $winner = $scored->first();

        return $winner && $winner['score'] >= 80 ? [$winner['column'], 'mapped'] : [null, 'unmapped'];
    }

    private function semesterScope(Collection $syllabus): string
    {
        $scopes = $syllabus->pluck('semester_scope')->filter()->unique();
        if ($scopes->contains('both') || ($scopes->contains('1') && $scopes->contains('2'))) {
            return 'both';
        }

        return (string) ($scopes->first() ?: 'both');
    }

    private function nextCode(Level $level, string $label): string
    {
        $prefix = Str::of($label)->replaceMatches('/^[IVX]+\.\s*/iu', '')->squish()->limit(24, '')->toString() ?: 'Materi';
        $this->usedCodes[$level->id] ??= RppMaterialCatalogItem::query()->where('level_id', $level->id)
            ->pluck('display_code')->flip()->all();
        $number = 1;
        while (isset($this->usedCodes[$level->id][$prefix.' '.str_pad((string) $number, 2, '0', STR_PAD_LEFT)])) {
            $number++;
        }

        $code = $prefix.' '.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
        $this->usedCodes[$level->id][$code] = true;

        return $code;
    }

    private function backfillPlacements(Level $level): void
    {
        $map = $this->catalogMap($level->id);
        $now = now();
        $rows = RppWeekItem::query()->whereNotNull('syllabus_item_id')
            ->whereHas('plan', fn ($query) => $query->where('level_id', $level->id))
            ->get(['id', 'syllabus_item_id'])
            ->flatMap(fn (RppWeekItem $item) => collect($map[$item->syllabus_item_id] ?? [])->map(fn ($catalogId) => [
                'rpp_week_item_id' => $item->id,
                'rpp_material_catalog_item_id' => $catalogId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        $rows->chunk(1000)->each(fn ($chunk) => DB::table('rpp_week_item_materials')->insertOrIgnore($chunk->all()));
    }

    private function syncActivities(Level $level, $now): void
    {
        $items = $level->syllabusItems()->where('is_duplicate', false)->where('is_source_artifact', false)
            ->where('schedule_pattern', 'tentative')
            ->with('matrixMapping.column')
            ->orderBy('sort_order')
            ->get();

        foreach ($items as $syllabus) {
            $column = $syllabus->matrixMapping?->column;
            if (! $column?->is_active) {
                continue;
            }
            $segments = collect(preg_split('/\s*,\s*/u', (string) $syllabus->title))
                ->map(fn ($value) => trim(preg_replace('/\b(?:dll|dan lain-lain)\.?\s*$/iu', '', (string) $value)))
                ->filter(fn ($value) => mb_strlen($value) >= 3)
                ->unique(fn ($value) => $this->normalize($value));
            foreach ($segments as $position => $title) {
                $origin = 'activity:auto:'.$column->stable_key.':'.sha1($this->normalize($title));
                $activity = RppMaterialCatalogItem::query()->firstOrNew([
                    'level_id' => $level->id,
                    'origin_key' => $origin,
                ]);
                if (! $activity->exists) {
                    $activity->forceFill([
                        'rpp_matrix_column_id' => $column->id,
                        'ggb_item_id' => null,
                        'syllabus_item_id' => null,
                        'source_kind' => 'activity',
                        'is_schedulable' => true,
                        'display_code' => $this->nextCode($level, $column->label),
                        'title' => $title,
                        'semester_scope' => $syllabus->semester_scope,
                        'source_semester_scope' => 'both',
                        'semester_confirmed' => true,
                        'auto_include' => false,
                        'is_active' => true,
                        'rotation_enabled' => true,
                        'mapping_status' => 'mapped',
                        'sort_order' => 200000 + $column->sort_order * 100 + $position,
                        'lock_version' => 0,
                        'last_edited_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->save();
                } elseif (! $activity->last_edited_by) {
                    $scope = $activity->semester_scope === $syllabus->semester_scope
                        ? $activity->semester_scope
                        : 'both';
                    $activity->forceFill([
                        'title' => $title,
                        'rpp_matrix_column_id' => $column->id,
                        'semester_scope' => $scope,
                        'is_schedulable' => true,
                        'is_active' => true,
                        'rotation_enabled' => true,
                        'mapping_status' => 'mapped',
                    ])->save();
                }
            }
        }
    }

    private function normalize(?string $value): string
    {
        return Str::of((string) $value)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
