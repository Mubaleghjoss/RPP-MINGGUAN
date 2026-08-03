<?php

namespace App\Services;

use App\Models\RppPlan;
use App\Models\RppWeekItem;
use Illuminate\Support\Facades\DB;

class RppPlanner
{
    public function generate(RppPlan $plan): RppPlan
    {
        return DB::transaction(function () use ($plan) {
            // Selalu muat ulang agar perubahan manual/kunci dari request lain
            // menjadi sumber kebenaran saat penyusunan ulang.
            $plan->load(['level.syllabusItems', 'academicYear.weeks', 'items']);
            $weeks = $plan->academicYear->weeks->where('is_effective', true)->sortBy('week_number')->values();
            if ($weeks->isEmpty()) {
                return $plan;
            }

            $lockedSyllabusIds = $plan->items->where('is_locked', true)->pluck('syllabus_item_id')->all();
            $plan->items()->where('source', 'auto')->where('is_locked', false)->delete();

            $groups = $plan->level->syllabusItems
                ->whereNotIn('id', $lockedSyllabusIds)
                ->where('is_duplicate', false)
                ->where('needs_allocation', false)
                ->sortBy('sort_order')
                ->groupBy(fn ($item) => trim($item->category) ?: 'Materi');

            foreach ($groups as $strand => $items) {
                $items = $items->values();
                $count = max(1, $items->count());
                foreach ($items as $index => $item) {
                    $weekIndex = min($weeks->count() - 1, (int) floor(($index * $weeks->count()) / $count));
                    RppWeekItem::updateOrCreate(
                        [
                            'rpp_plan_id' => $plan->id,
                            'calendar_week_id' => $weeks[$weekIndex]->id,
                            'syllabus_item_id' => $item->id,
                        ],
                        [
                            'strand' => $strand,
                            'content' => $item->title,
                            'source' => 'auto',
                            'is_locked' => false,
                            'position' => 1,
                        ]
                    );
                }
            }

            $total = $plan->level->syllabusItems()->where('is_duplicate', false)->count();
            $planned = $plan->items()->distinct('syllabus_item_id')->count('syllabus_item_id');
            $coverage = $total > 0 ? round(($planned / $total) * 100, 2) : 0;
            $plan->update(['coverage_percent' => $coverage, 'status' => 'draft', 'validated_at' => null]);

            return $plan->fresh(['items', 'level', 'academicYear.weeks']);
        });
    }

    public function generateAll(): void
    {
        RppPlan::query()->with(['level.syllabusItems', 'academicYear.weeks', 'items'])->each(fn (RppPlan $plan) => $this->generate($plan));
    }

    public function validate(RppPlan $plan): bool
    {
        $this->refreshCoverage($plan);
        if ((float) $plan->coverage_percent < 100) {
            return false;
        }
        $plan->update(['status' => 'validated', 'validated_at' => now()]);
        return true;
    }

    public function refreshCoverage(RppPlan $plan): void
    {
        $total = $plan->level->syllabusItems()->where('is_duplicate', false)->count();
        $planned = $plan->items()->distinct('syllabus_item_id')->count('syllabus_item_id');
        $plan->update(['coverage_percent' => $total ? round(($planned / $total) * 100, 2) : 0]);
        $plan->refresh();
    }
}
