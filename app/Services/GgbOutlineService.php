<?php

namespace App\Services;

use App\Models\GgbItem;
use App\Models\Level;
use App\Models\RevisionBatch;
use App\Models\RevisionItem;
use App\Models\RppWeekItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GgbOutlineService
{
    public const ROLES = ['material', 'heading', 'artifact'];

    public function classifyLevel(Level $level, bool $preserveManual = true): array
    {
        $items = $level->ggbItems()->orderBy('sort_order')->orderBy('id')->get();
        $roles = $this->rolesFor($level, $items);
        $counts = array_fill_keys(self::ROLES, 0);

        foreach ($items as $item) {
            $role = $roles[$item->id] ?? 'material';
            if (! ($preserveManual && $item->rpp_role_source === 'manual')) {
                $changes = ['rpp_role' => $role, 'rpp_role_source' => 'auto'];
                if ($item->only(array_keys($changes)) !== $changes) {
                    $item->forceFill($changes)->saveQuietly();
                }
            }
            $counts[$item->rpp_role]++;
        }

        return $counts;
    }

    public function rolesFor(Level $level, Collection $items): array
    {
        $roles = [];
        $artifacts = $this->artifactLabels($level);

        foreach ($items as $item) {
            if (in_array($item->kind, ['aspect', 'subaspect'], true)) {
                $roles[$item->id] = 'heading';
            } elseif (in_array($this->normalize($item->title), $artifacts, true)) {
                $roles[$item->id] = 'artifact';
            } else {
                $roles[$item->id] = 'material';
            }
        }

        foreach ($items->groupBy(fn (GgbItem $item) => (int) ($item->parent_id ?: 0)) as $siblings) {
            $siblings = $siblings->values();
            foreach ($siblings as $index => $item) {
                if (($roles[$item->id] ?? null) !== 'material') {
                    continue;
                }
                if ($this->isStructuralHeading($siblings, $index, $roles)) {
                    $roles[$item->id] = 'heading';
                }
            }
        }

        return $roles;
    }

    public function removeInvalidPlacements(Level $level, ?int $userId = null): int
    {
        $items = RppWeekItem::query()
            ->whereHas('plan', fn ($query) => $query->where('level_id', $level->id))
            ->whereHas('materials', fn ($query) => $query->where('is_schedulable', false))
            ->with('materials:id,is_schedulable')
            ->get();
        if ($items->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($items, $userId) {
            $batch = RevisionBatch::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'action' => 'normalize',
                'reason' => 'Normalisasi: subjudul dan artefak GGB dikeluarkan dari RPP.',
                'item_count' => $items->count(),
            ]);
            foreach ($items as $item) {
                $beforeIds = $item->materials->pluck('id')->sort()->values();
                $invalidIds = $item->materials->where('is_schedulable', false)->pluck('id');
                $removePlacement = $item->syllabus_item_id === null
                    && $item->source === 'ggb_auto'
                    && $item->materials->where('is_schedulable', true)->isEmpty();
                $afterIds = $removePlacement ? collect() : $beforeIds->diff($invalidIds)->values();
                RevisionItem::query()->create([
                    'revision_batch_id' => $batch->id,
                    'revisable_type' => 'rpp',
                    'revisable_id' => $item->id,
                    'before_values' => $item->only([
                        'rpp_plan_id', 'calendar_week_id', 'syllabus_item_id', 'source_fingerprint',
                        'occurrence_no', 'rpp_matrix_column_id', 'strand', 'content', 'source',
                        'is_locked', 'position', 'progress_start', 'progress_end', 'progress_kind',
                    ]) + ['material_catalog_ids' => $beforeIds->all()],
                    'after_values' => $removePlacement
                        ? ['removed_by_normalization' => true]
                        : ['material_catalog_ids' => $afterIds->all()],
                    'before_lock_version' => (int) $item->lock_version,
                    'after_lock_version' => (int) $item->lock_version + 1,
                ]);
                if ($removePlacement) {
                    $item->delete();
                } else {
                    $item->materials()->detach($invalidIds);
                    $item->forceFill([
                        'lock_version' => (int) $item->lock_version + 1,
                        'last_edited_by' => $userId,
                    ])->save();
                }
            }

            return $items->count();
        });
    }

    private function isStructuralHeading(Collection $siblings, int $index, array $roles): bool
    {
        $item = $siblings[$index];
        $depth = $this->markerDepth($item->raw_text);

        if ($depth === 1) {
            for ($cursor = $index + 1; $cursor < $siblings->count(); $cursor++) {
                $next = $siblings[$cursor];
                if ($this->markerDepth($next->raw_text) === 1) {
                    break;
                }
                if (($roles[$next->id] ?? null) !== 'artifact') {
                    return true;
                }
            }

            return false;
        }

        if ($depth === 2) {
            for ($cursor = $index + 1; $cursor < $siblings->count(); $cursor++) {
                $nextDepth = $this->markerDepth($siblings[$cursor]->raw_text);
                if ($nextDepth !== null && $nextDepth <= 2) {
                    break;
                }
                if ($nextDepth === 3) {
                    return true;
                }
            }

            return false;
        }

        if ($depth === null && str_ends_with(trim((string) $item->raw_text), ':')) {
            $next = $siblings->get($index + 1);

            return $next && $this->markerDepth($next->raw_text) === 3;
        }

        return false;
    }

    private function markerDepth(?string $value): ?int
    {
        $value = trim((string) $value);

        return match (true) {
            preg_match('/^\d+\.\s+/u', $value) === 1 => 1,
            preg_match('/^[a-z]\.\s+/u', $value) === 1 => 2,
            preg_match('/^\d+\)\s+/u', $value) === 1 => 3,
            default => null,
        };
    }

    private function artifactLabels(Level $level): array
    {
        return collect([$level->code, $level->name])
            ->map(fn ($value) => $this->normalize($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalize(?string $value): string
    {
        return Str::of((string) $value)->lower()->ascii()->squish()->toString();
    }
}
