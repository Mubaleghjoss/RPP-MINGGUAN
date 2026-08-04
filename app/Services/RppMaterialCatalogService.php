<?php

namespace App\Services;

use App\Models\GgbItem;
use App\Models\Level;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RppMaterialCatalogService
{
    private array $catalogMaps = [];

    private array $usedCodes = [];

    public function __construct(private readonly RppMatrixPresetService $presets) {}

    public function syncAll(): void
    {
        Level::query()->orderBy('sort_order')->each(fn (Level $level) => $this->syncLevel($level));
    }

    public function syncLevel(Level $level): void
    {
        DB::transaction(function () use ($level) {
            $this->presets->syncLevel($level);
            $this->usedCodes[$level->id] = RppMaterialCatalogItem::query()->where('level_id', $level->id)
                ->pluck('display_code')->flip()->all();
            $columns = $level->matrixColumns()->where('is_active', true)->orderBy('sort_order')->get();
            $allGgb = $level->ggbItems()->with(['syllabusItems.matrixMapping.column'])->orderBy('sort_order')->get();
            $byId = $allGgb->keyBy('id');
            $parentIds = $allGgb->pluck('parent_id')->filter()->mapWithKeys(fn ($id) => [(int) $id => true]);
            $leaves = $allGgb->filter(fn (GgbItem $item) => ! in_array($item->kind, ['aspect', 'subaspect'], true)
                && ! $parentIds->has($item->id));
            $existingGgb = RppMaterialCatalogItem::query()->where('level_id', $level->id)->whereNotNull('ggb_item_id')->get()->keyBy('ggb_item_id');
            $newGgb = [];
            $now = now();

            foreach ($leaves as $leaf) {
                [$column, $status] = $this->columnForGgb($leaf, $columns, $byId);
                $catalog = $existingGgb->get($leaf->id);
                if (! $catalog) {
                    $newGgb[] = [
                        'level_id' => $level->id,
                        'rpp_matrix_column_id' => $column?->id,
                        'ggb_item_id' => $leaf->id,
                        'syllabus_item_id' => null,
                        'source_kind' => 'ggb',
                        'display_code' => $this->nextCode($level, $column?->label ?: $leaf->subaspect ?: 'Materi'),
                        'title' => $leaf->title,
                        'semester_scope' => $this->semesterScope($leaf->syllabusItems),
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
                    'semester_scope' => $this->semesterScope($leaf->syllabusItems),
                ];
                if (! $catalog->last_edited_by) {
                    $changes['rpp_matrix_column_id'] = $column?->id;
                    $changes['mapping_status'] = $status;
                }
                if ($catalog->only(array_keys($changes)) != $changes) {
                    $catalog->forceFill($changes)->save();
                }
            }
            collect($newGgb)->chunk(500)->each(fn ($chunk) => DB::table('rpp_material_catalog_items')->insert($chunk->all()));

            unset($this->catalogMaps[$level->id]);
            $catalogMap = $this->catalogMap($level->id);
            $ggbCatalogIds = RppMaterialCatalogItem::query()->where('level_id', $level->id)
                ->where('source_kind', 'ggb')->pluck('id')->flip()->all();
            $existingSyllabus = RppMaterialCatalogItem::query()->where('level_id', $level->id)->whereNotNull('syllabus_item_id')->get()->keyBy('syllabus_item_id');
            $newSyllabus = [];
            $supplementalSyllabusIds = [];
            $level->syllabusItems()->where('is_duplicate', false)->with('matrixMapping.column')->orderBy('sort_order')->get()
                ->each(function (SyllabusItem $syllabus) use ($level, $catalogMap, $ggbCatalogIds, $existingSyllabus, &$newSyllabus, &$supplementalSyllabusIds, $now) {
                    $hasDetailedGgb = collect($catalogMap[$syllabus->id] ?? [])->contains(fn ($id) => isset($ggbCatalogIds[$id]));
                    if ($hasDetailedGgb) {
                        return;
                    }
                    $supplementalSyllabusIds[] = $syllabus->id;
                    $column = $syllabus->matrixMapping?->column;
                    $catalog = $existingSyllabus->get($syllabus->id);
                    if (! $catalog) {
                        $newSyllabus[] = [
                            'level_id' => $level->id,
                            'rpp_matrix_column_id' => $column?->is_active ? $column->id : null,
                            'ggb_item_id' => null,
                            'syllabus_item_id' => $syllabus->id,
                            'source_kind' => 'syllabus',
                            'display_code' => $this->nextCode($level, $column?->label ?: $syllabus->category ?: 'Materi'),
                            'title' => $syllabus->title,
                            'semester_scope' => $syllabus->semester_scope,
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
                        'semester_scope' => $syllabus->semester_scope,
                        'sort_order' => 100000 + $syllabus->sort_order,
                    ];
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

            $validGgbIds = $leaves->pluck('id');
            RppMaterialCatalogItem::query()->where('level_id', $level->id)->where('source_kind', 'ggb')
                ->whereNotIn('ggb_item_id', $validGgbIds)->whereDoesntHave('placements')->delete();

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

    public function placementLabel(RppWeekItem $item): string
    {
        $item->loadMissing('materials');
        $codes = $item->materials->sortBy('sort_order')->pluck('display_code')->implode(', ');

        return $codes !== '' ? $codes.' — '.$item->content : $item->content;
    }

    public function coverage(RppPlan $plan): array
    {
        $query = RppMaterialCatalogItem::query()->where('level_id', $plan->level_id)->where('source_kind', 'ggb');
        $total = (clone $query)->count();
        $used = (clone $query)->whereHas('placements', fn ($placement) => $placement->where('rpp_plan_id', $plan->id))->count();

        return [
            'total' => $total,
            'used' => $used,
            'missing' => max(0, $total - $used),
            'percent' => $total ? round(($used / $total) * 100, 2) : 100.0,
        ];
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

        $catalog = RppMaterialCatalogItem::query()->where('level_id', $levelId)->get();
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
        SyllabusItem::query()->where('level_id', $levelId)->where('is_duplicate', false)->with('ggbItems:id,parent_id')->get()
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
        $linked = $ggb->syllabusItems->filter(fn ($item) => ! $item->is_duplicate && $item->matrixMapping?->column?->is_active)
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

    private function normalize(?string $value): string
    {
        return Str::of((string) $value)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
