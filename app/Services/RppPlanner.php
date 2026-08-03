<?php

namespace App\Services;

use App\Models\CalendarWeek;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RppPlanner
{
    public function __construct(private readonly RppProgressService $progress) {}

    public function scheduleOne(RppPlan $plan, int $syllabusItemId, ?int $userId): RppWeekItem
    {
        return DB::transaction(function () use ($plan, $syllabusItemId, $userId) {
            $lockedPlan = RppPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $weeks = CalendarWeek::query()
                ->where('academic_year_id', $lockedPlan->academic_year_id)
                ->where('semester', $lockedPlan->semester)
                ->where('is_effective', true)
                ->orderBy('week_number')
                ->lockForUpdate()
                ->get();

            if ($weeks->isEmpty()) {
                throw ValidationException::withMessages(['week' => 'Tidak ada minggu efektif yang tersedia pada tahun ajaran ini.']);
            }

            $item = SyllabusItem::query()->lockForUpdate()->find($syllabusItemId);
            if (! $item || (int) $item->level_id !== (int) $lockedPlan->level_id) {
                throw ValidationException::withMessages(['material' => 'Materi bukan milik jenjang RPP ini.']);
            }
            if ($item->is_duplicate) {
                throw ValidationException::withMessages(['material' => 'Materi duplikat tidak dapat dijadwalkan.']);
            }
            if ($item->needs_allocation || blank($item->allocation_text) || (int) $item->recommended_sessions < 1) {
                throw ValidationException::withMessages(['material' => 'Lengkapi alokasi dan jumlah pertemuan minimal 1 sebelum menjadwalkan materi.']);
            }
            if (! in_array($item->semester_scope, [(string) $lockedPlan->semester, 'both'], true)) {
                throw ValidationException::withMessages(['material' => "Materi bukan bagian dari Semester {$lockedPlan->semester}."]);
            }
            if ($lockedPlan->progressTargets()->where('syllabus_item_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['material' => 'Materi ini menggunakan target progres. Gunakan Susun Otomatis agar rentangnya tetap berurutan.']);
            }
            if (RppWeekItem::query()->where('rpp_plan_id', $lockedPlan->id)->where('syllabus_item_id', $item->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['material' => 'Materi ini sudah dijadwalkan. Muat ulang halaman untuk melihat jadwal terbaru.']);
            }

            $strand = trim((string) $item->category) ?: 'Materi';
            $siblings = SyllabusItem::query()
                ->where('level_id', $lockedPlan->level_id)
                ->where('is_duplicate', false)
                ->where('needs_allocation', false)
                ->whereIn('semester_scope', [(string) $lockedPlan->semester, 'both'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (SyllabusItem $candidate) => (trim((string) $candidate->category) ?: 'Materi') === $strand
                    && filled($candidate->allocation_text)
                    && (int) $candidate->recommended_sessions >= 1
                )
                ->values();
            $index = $siblings->search(fn (SyllabusItem $candidate) => $candidate->is($item));

            if ($index === false) {
                throw ValidationException::withMessages(['material' => 'Materi tidak lagi memenuhi syarat penjadwalan. Muat ulang halaman.']);
            }

            $weekIndex = min($weeks->count() - 1, (int) floor(($index * $weeks->count()) / max(1, $siblings->count())));
            $week = $weeks[$weekIndex];
            $position = (int) RppWeekItem::query()
                ->where('rpp_plan_id', $lockedPlan->id)
                ->where('calendar_week_id', $week->id)
                ->max('position');

            $placement = RppWeekItem::query()->create([
                'rpp_plan_id' => $lockedPlan->id,
                'calendar_week_id' => $week->id,
                'syllabus_item_id' => $item->id,
                'strand' => $strand,
                'content' => $item->title,
                'source' => 'auto',
                'is_locked' => false,
                'position' => $position + 1,
                'lock_version' => 0,
                'last_edited_by' => $userId,
            ]);

            $this->refreshCoverage($lockedPlan);
            $lockedPlan->update(['status' => 'draft', 'validated_at' => null]);
            DB::table('activity_logs')->insert([
                'user_id' => $userId,
                'action' => 'rpp.item_scheduled_auto',
                'details' => json_encode([
                    'plan_id' => $lockedPlan->id,
                    'level_id' => $lockedPlan->level_id,
                    'syllabus_item_id' => $item->id,
                    'calendar_week_id' => $week->id,
                    'algorithm' => 'category_distribution',
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $placement->fresh('week');
        });
    }

    public function generate(RppPlan $plan): RppPlan
    {
        return DB::transaction(function () use ($plan) {
            // Selalu muat ulang agar perubahan manual/kunci dari request lain
            // menjadi sumber kebenaran saat penyusunan ulang.
            $plan->load(['level.syllabusItems', 'academicYear.weeks', 'items', 'progressTargets.syllabusItem']);
            $this->progress->ensureDefaults($plan);
            $plan->load('progressTargets.syllabusItem');
            $weeks = $plan->academicYear->weeks
                ->where('semester', (int) $plan->semester)
                ->where('is_effective', true)
                ->sortBy('week_number')
                ->values();
            if ($weeks->isEmpty()) {
                if ($plan->progressTargets->isNotEmpty()) {
                    throw ValidationException::withMessages(['progress' => "Semester {$plan->semester} tidak memiliki minggu efektif untuk target progres."]);
                }

                return $plan;
            }

            $lockedSyllabusIds = $plan->items->where('is_locked', true)->pluck('syllabus_item_id')->all();
            $plan->items()->where('source', 'auto')->where('is_locked', false)->delete();

            foreach ($plan->progressTargets as $target) {
                $this->progress->generateTarget($plan, $target, $weeks);
            }

            $progressSyllabusIds = $plan->progressTargets->pluck('syllabus_item_id')->all();

            $groups = $plan->level->syllabusItems
                ->whereNotIn('id', $lockedSyllabusIds)
                ->whereNotIn('id', $progressSyllabusIds)
                ->where('is_duplicate', false)
                ->where('needs_allocation', false)
                ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
                ->filter(fn (SyllabusItem $item) => filled($item->allocation_text) && (int) $item->recommended_sessions >= 1)
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

            $this->refreshCoverage($plan);
            $plan->update(['status' => 'draft', 'validated_at' => null]);

            return $plan->fresh(['items', 'level', 'academicYear.weeks']);
        });
    }

    public function generateAll(): void
    {
        RppPlan::query()->with(['level.syllabusItems', 'academicYear.weeks', 'items', 'progressTargets'])->each(fn (RppPlan $plan) => $this->generate($plan));
    }

    public function validate(RppPlan $plan): bool
    {
        $this->refreshCoverage($plan);
        if ((float) $plan->coverage_percent < 100) {
            return false;
        }
        $plan->load('progressTargets');
        if ($plan->progressTargets->contains(fn ($target) => ! $this->progress->isComplete($target))) {
            return false;
        }
        $plan->update(['status' => 'validated', 'validated_at' => now()]);

        return true;
    }

    public function refreshCoverage(RppPlan $plan): void
    {
        $total = $plan->level->syllabusItems()
            ->where('is_duplicate', false)
            ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
            ->count();
        $planned = $plan->items()->distinct('syllabus_item_id')->count('syllabus_item_id');
        $plan->update(['coverage_percent' => $total ? round(($planned / $total) * 100, 2) : 0]);
        $plan->refresh();
    }
}
