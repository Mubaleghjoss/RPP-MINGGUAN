<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('calendar_weeks', 'semester')) {
            Schema::table('calendar_weeks', function (Blueprint $table) {
                $table->unsignedTinyInteger('semester')->default(1)->after('week_number')->index();
            });
        }
        if (! Schema::hasColumn('syllabus_items', 'source_semester')) {
            Schema::table('syllabus_items', function (Blueprint $table) {
                $table->string('source_semester', 10)->default('1')->after('group_number');
                $table->string('semester_scope', 10)->default('1')->after('source_semester')->index();
            });
        }
        if (! Schema::hasColumn('rpp_plans', 'semester')) {
            Schema::table('rpp_plans', function (Blueprint $table) {
                $table->unsignedTinyInteger('semester')->default(1)->after('level_id');
            });
        }
        // MariaDB dapat memakai indeks unik lama sebagai penopang foreign key.
        // Indeks eksplisit ini membuat pelepasan indeks unik aman dan migrasi dapat dilanjutkan.
        if (! Schema::hasIndex('rpp_plans', 'rpp_plan_academic_year_lookup')) {
            Schema::table('rpp_plans', fn (Blueprint $table) => $table->index('academic_year_id', 'rpp_plan_academic_year_lookup'));
        }
        if (! Schema::hasIndex('rpp_plans', 'rpp_plan_level_lookup')) {
            Schema::table('rpp_plans', fn (Blueprint $table) => $table->index('level_id', 'rpp_plan_level_lookup'));
        }
        if (Schema::hasIndex('rpp_plans', 'rpp_plans_academic_year_id_level_id_unique')) {
            Schema::table('rpp_plans', fn (Blueprint $table) => $table->dropUnique('rpp_plans_academic_year_id_level_id_unique'));
        }
        if (! Schema::hasIndex('rpp_plans', 'rpp_plan_semester_unique')) {
            Schema::table('rpp_plans', fn (Blueprint $table) => $table->unique(['academic_year_id', 'level_id', 'semester'], 'rpp_plan_semester_unique'));
        }

        if (! Schema::hasTable('rpp_progress_targets')) {
            Schema::create('rpp_progress_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rpp_plan_id')->constrained()->cascadeOnDelete();
                $table->foreignId('syllabus_item_id')->constrained()->cascadeOnDelete();
                $table->string('unit_label', 50)->default('halaman');
                $table->unsignedInteger('range_start');
                $table->unsignedInteger('range_end');
                $table->string('strategy', 20)->default('even');
                $table->string('source', 20)->default('auto');
                $table->unsignedInteger('lock_version')->default(0);
                $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
                $table->unique(['rpp_plan_id', 'syllabus_item_id'], 'rpp_progress_target_unique');
            });
        }

        if (! Schema::hasColumn('rpp_week_items', 'rpp_progress_target_id')) {
            Schema::table('rpp_week_items', function (Blueprint $table) {
                $table->foreignId('rpp_progress_target_id')->nullable()->after('syllabus_item_id')->constrained()->nullOnDelete();
                $table->unsignedInteger('progress_start')->nullable()->after('content');
                $table->unsignedInteger('progress_end')->nullable()->after('progress_start');
                $table->string('progress_kind', 20)->nullable()->after('progress_end');
            });
        }

        DB::table('calendar_weeks')->orderBy('id')->eachById(function ($week) {
            DB::table('calendar_weeks')->where('id', $week->id)->update([
                'semester' => (int) $week->week_number <= 26 ? 1 : 2,
            ]);
        });

        $this->backfillSyllabusSemesters();
        $this->createSemesterPlans();
        $this->seedProgressTargets();
        $this->rebuildAutomaticPlacements();
    }

    private function backfillSyllabusSemesters(): void
    {
        $seen = [];
        $items = DB::table('syllabus_items')
            ->join('levels', 'levels.id', '=', 'syllabus_items.level_id')
            ->join('source_documents', 'source_documents.id', '=', 'syllabus_items.source_document_id')
            ->select('syllabus_items.*', 'levels.code as level_code', 'source_documents.page_count')
            ->orderBy('syllabus_items.level_id')
            ->orderBy('syllabus_items.sort_order')
            ->get();

        foreach ($items as $item) {
            $semester = $item->level_code === 'PAUD'
                ? 'both'
                : ((int) $item->source_page <= (int) ceil(((int) $item->page_count) / 2) ? '1' : '2');
            $key = implode('|', [
                $item->level_id,
                $semester,
                mb_strtolower(trim((string) $item->category)),
                mb_strtolower(trim((string) $item->title)),
                mb_strtolower(trim((string) ($item->allocation_text ?? ''))),
            ]);
            $duplicate = isset($seen[$key]);
            $seen[$key] = true;

            DB::table('syllabus_items')->where('id', $item->id)->update([
                'source_semester' => $semester,
                'semester_scope' => $semester,
                'is_duplicate' => $duplicate,
            ]);
        }
    }

    private function createSemesterPlans(): void
    {
        $plans = DB::table('rpp_plans')->where('semester', 1)->orderBy('id')->get();
        foreach ($plans as $plan) {
            DB::table('rpp_plans')->where('id', $plan->id)->update([
                'semester' => 1,
                'status' => 'draft',
                'validated_at' => null,
            ]);
            $semesterTwoId = DB::table('rpp_plans')
                ->where('academic_year_id', $plan->academic_year_id)
                ->where('level_id', $plan->level_id)
                ->where('semester', 2)
                ->value('id');
            if (! $semesterTwoId) {
                $semesterTwoId = DB::table('rpp_plans')->insertGetId([
                    'academic_year_id' => $plan->academic_year_id,
                    'level_id' => $plan->level_id,
                    'semester' => 2,
                    'status' => 'draft',
                    'coverage_percent' => 0,
                    'validated_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('rpp_week_items')
                ->where('rpp_plan_id', $plan->id)
                ->whereIn('calendar_week_id', DB::table('calendar_weeks')->where('semester', 2)->where('academic_year_id', $plan->academic_year_id)->select('id'))
                ->update(['rpp_plan_id' => $semesterTwoId]);
        }
    }

    private function seedProgressTargets(): void
    {
        $tilawati = DB::table('syllabus_items')
            ->join('levels', 'levels.id', '=', 'syllabus_items.level_id')
            ->where('syllabus_items.is_duplicate', false)
            ->where(function ($query) {
                $query->where('syllabus_items.title', 'like', 'Tilawati %')
                    ->orWhere('syllabus_items.stable_code', 'like', 'PAUD / TILAWATI / %');
            })
            ->select('syllabus_items.id', 'syllabus_items.title', 'levels.id as level_id', 'levels.code as level_code')
            ->get();

        foreach ($tilawati as $item) {
            $targets = [];
            if ($item->level_code === 'PAUD' && str_contains(mb_strtolower($item->title), 'tilawati')) {
                $targets = [1 => [1, 22], 2 => [23, 44]];
            } elseif (preg_match('/Tilawati\s+([1-6])\s*\(44 halaman\)/i', $item->title, $match)) {
                $volume = (int) $match[1];
                $targets[$volume % 2 === 1 ? 1 : 2] = [1, 44];
            }

            foreach ($targets as $semester => [$start, $end]) {
                $plan = DB::table('rpp_plans')->where('level_id', $item->level_id)->where('semester', $semester)->first();
                if (! $plan) {
                    continue;
                }
                DB::table('rpp_progress_targets')->updateOrInsert(
                    ['rpp_plan_id' => $plan->id, 'syllabus_item_id' => $item->id],
                    [
                        'unit_label' => 'halaman', 'range_start' => $start, 'range_end' => $end,
                        'strategy' => 'even', 'source' => 'auto', 'lock_version' => 0,
                        'created_at' => now(), 'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function rebuildAutomaticPlacements(): void
    {
        DB::table('rpp_week_items')->where('source', 'auto')->where('is_locked', false)->delete();
        $plans = DB::table('rpp_plans')->orderBy('id')->get();

        foreach ($plans as $plan) {
            $weeks = DB::table('calendar_weeks')
                ->where('academic_year_id', $plan->academic_year_id)
                ->where('semester', $plan->semester)
                ->where('is_effective', true)
                ->orderBy('week_number')
                ->get();
            if ($weeks->isEmpty()) {
                continue;
            }

            $targets = DB::table('rpp_progress_targets')->where('rpp_plan_id', $plan->id)->get();
            foreach ($targets as $target) {
                $syllabus = DB::table('syllabus_items')->find($target->syllabus_item_id);
                if ($syllabus) {
                    $this->insertProgressRows($plan, $target, $syllabus, $weeks);
                }
            }

            $targetIds = $targets->pluck('syllabus_item_id');
            $materials = DB::table('syllabus_items')
                ->where('level_id', $plan->level_id)
                ->where('is_duplicate', false)
                ->where('needs_allocation', false)
                ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
                ->when($targetIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $targetIds))
                ->whereNotNull('allocation_text')
                ->where('recommended_sessions', '>=', 1)
                ->orderBy('sort_order')
                ->get()
                ->groupBy(fn ($item) => trim((string) $item->category) ?: 'Materi');

            foreach ($materials as $strand => $items) {
                $items = $items->values();
                foreach ($items as $index => $item) {
                    if (DB::table('rpp_week_items')->where('rpp_plan_id', $plan->id)->where('syllabus_item_id', $item->id)->exists()) {
                        continue;
                    }
                    $weekIndex = min($weeks->count() - 1, (int) floor(($index * $weeks->count()) / max(1, $items->count())));
                    DB::table('rpp_week_items')->insert([
                        'rpp_plan_id' => $plan->id,
                        'calendar_week_id' => $weeks[$weekIndex]->id,
                        'syllabus_item_id' => $item->id,
                        'strand' => $strand,
                        'content' => $item->title,
                        'source' => 'auto',
                        'is_locked' => false,
                        'position' => 1,
                        'lock_version' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->refreshCoverage($plan);
        }
    }

    private function insertProgressRows(object $plan, object $target, object $syllabus, $weeks): void
    {
        $total = $target->range_end - $target->range_start + 1;
        $previousEnd = $target->range_start - 1;
        foreach ($weeks as $index => $week) {
            $cumulative = (int) ceil((($index + 1) * $total) / $weeks->count());
            $end = $target->range_start + $cumulative - 1;
            if ($end > $previousEnd) {
                $start = $previousEnd + 1;
                $kind = 'materi_baru';
                $content = $syllabus->title.' — '.ucfirst($target->unit_label).' '.($start === $end ? $start : "{$start}–{$end}");
                $previousEnd = $end;
            } else {
                $start = max($target->range_start, $previousEnd);
                $end = $start;
                $kind = 'penguatan';
                $content = $syllabus->title.' — Penguatan '.$target->unit_label.' '.$end;
            }
            DB::table('rpp_week_items')->insert([
                'rpp_plan_id' => $plan->id,
                'calendar_week_id' => $week->id,
                'syllabus_item_id' => $syllabus->id,
                'rpp_progress_target_id' => $target->id,
                'strand' => trim((string) $syllabus->category) ?: 'Materi',
                'content' => $content,
                'progress_start' => $start,
                'progress_end' => $end,
                'progress_kind' => $kind,
                'source' => 'auto',
                'is_locked' => false,
                'position' => 1,
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function refreshCoverage(object $plan): void
    {
        $total = DB::table('syllabus_items')
            ->where('level_id', $plan->level_id)
            ->where('is_duplicate', false)
            ->whereIn('semester_scope', [(string) $plan->semester, 'both'])
            ->count();
        $planned = DB::table('rpp_week_items')->where('rpp_plan_id', $plan->id)->distinct()->count('syllabus_item_id');
        DB::table('rpp_plans')->where('id', $plan->id)->update([
            'coverage_percent' => $total > 0 ? round(($planned / $total) * 100, 2) : 0,
            'status' => 'draft',
            'validated_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('rpp_week_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rpp_progress_target_id');
            $table->dropColumn(['progress_start', 'progress_end', 'progress_kind']);
        });
        Schema::dropIfExists('rpp_progress_targets');
        Schema::table('rpp_plans', function (Blueprint $table) {
            $table->dropUnique('rpp_plan_semester_unique');
            $table->dropColumn('semester');
            $table->unique(['academic_year_id', 'level_id']);
        });
        Schema::table('syllabus_items', function (Blueprint $table) {
            $table->dropColumn(['source_semester', 'semester_scope']);
        });
        Schema::table('calendar_weeks', function (Blueprint $table) {
            $table->dropColumn('semester');
        });
    }
};
