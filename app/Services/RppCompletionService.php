<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\RppProgressTarget;

class RppCompletionService
{
    public function __construct(
        private readonly AcademicCalendarService $calendar,
        private readonly RppMaterialCatalogService $catalog,
        private readonly RppProgressService $progress,
    ) {}

    public function report(AcademicYear $year, Level $level): array
    {
        $plans = RppPlan::query()
            ->where('academic_year_id', $year->id)
            ->where('level_id', $level->id)
            ->whereIn('semester', [1, 2])
            ->get()
            ->keyBy('semester');

        $calendarStep = $this->calendarStep($year, $plans);
        $catalogQuery = $level->materialCatalogItems()->where('source_kind', 'ggb');
        $coveragePlan = $plans->first();
        $ggbCounts = $coveragePlan ? $this->catalog->ggbStatusCounts($coveragePlan) : [
            'all' => (clone $catalogQuery)->count(),
            'used' => 0,
            'missing' => (clone $catalogQuery)->count(),
            'ready' => 0,
            'semester' => (clone $catalogQuery)->where(fn ($query) => $query->whereNotIn('semester_scope', ['1', '2'])->orWhere('semester_confirmed', false))->count(),
            'mapping' => (clone $catalogQuery)->needsRppColumnConfirmation()->count(),
            'conflict' => 0,
        ];
        $ggbTotal = $ggbCounts['all'];
        $needsSemester = $ggbCounts['semester'];
        $needsMapping = $ggbCounts['mapping'];
        $confirmationComplete = $ggbTotal > 0 && $needsSemester === 0 && $needsMapping === 0;
        $confirmationBlockers = [];
        if ($needsSemester > 0) {
            $confirmationBlockers[] = "{$needsSemester} materi GGB belum dikonfirmasi semesternya.";
        }
        if ($needsMapping > 0) {
            $confirmationBlockers[] = "{$needsMapping} materi GGB belum dikonfirmasi kolom RPP-nya.";
        }

        $coverage = $coveragePlan
            ? $this->catalog->coverage($coveragePlan)
            : ['total' => $ggbTotal, 'used' => 0, 'missing' => $ggbTotal, 'percent' => 0.0];
        $annualValidation = $year->annualValidations()->where('level_id', $level->id)->first();
        $annualComplete = (float) $coverage['percent'] >= 100 && $annualValidation?->status === 'validated';
        $annualBlockers = [];
        if ((int) $coverage['missing'] > 0) {
            $annualBlockers[] = "{$coverage['missing']} materi GGB belum masuk RPP Semester 1 atau 2.";
        } elseif ($annualValidation?->status !== 'validated') {
            $annualBlockers[] = 'Cakupan GGB sudah 100%, tetapi belum divalidasi tahunan.';
        }

        $steps = [
            $calendarStep,
            [
                'key' => 'ggb_confirmation',
                'label' => 'Konfirmasi semester dan kolom GGB',
                'complete' => $confirmationComplete,
                'current' => $ggbTotal - max($needsSemester, $needsMapping),
                'target' => $ggbTotal,
                'summary' => $confirmationComplete
                    ? "Seluruh {$ggbTotal} materi GGB sudah mempunyai semester dan kolom RPP."
                    : implode(' ', $confirmationBlockers),
                'blockers' => $confirmationBlockers,
                'action' => 'ggb',
                'diagnostics' => [
                    'needs_semester' => $needsSemester,
                    'needs_mapping' => $needsMapping,
                ],
            ],
            [
                'key' => 'annual_ggb',
                'label' => 'Lengkapi dan validasi GGB tahunan',
                'complete' => $annualComplete,
                'current' => (int) $coverage['used'],
                'target' => (int) $coverage['total'],
                'summary' => $annualComplete
                    ? "Cakupan GGB {$coverage['used']}/{$coverage['total']} sudah tervalidasi tahunan."
                    : implode(' ', $annualBlockers),
                'blockers' => $annualBlockers,
                'action' => 'ggb',
                'diagnostics' => [
                    'ggb_missing' => (int) $coverage['missing'],
                    'ggb_ready' => (int) $ggbCounts['ready'],
                    'annual_validation_pending' => (int) $coverage['missing'] === 0 && $annualValidation?->status !== 'validated',
                ],
            ],
            $this->semesterStep($plans->get(1), 1),
            $this->semesterStep($plans->get(2), 2),
        ];
        $completed = collect($steps)->where('complete', true)->count();

        return [
            'complete' => $completed === count($steps),
            'percent' => (int) round(($completed / count($steps)) * 100),
            'completed_steps' => $completed,
            'total_steps' => count($steps),
            'steps' => $steps,
            'ggb' => [
                'total' => $ggbTotal,
                'needs_semester' => $needsSemester,
                'needs_mapping' => $needsMapping,
                'coverage' => $coverage,
                'status_counts' => $ggbCounts,
            ],
        ];
    }

    private function calendarStep(AcademicYear $year, $plans): array
    {
        $periods = $year->semesters()->whereIn('semester', [1, 2])->get()->keyBy('semester');
        $one = $periods->get(1);
        $two = $periods->get(2);
        $blockers = [];

        if (! $one || ! $two) {
            $blockers[] = 'Rentang Semester 1 dan 2 belum lengkap.';
        } elseif ($one->starts_on->gt($one->ends_on) || $two->starts_on->gt($two->ends_on) || $one->ends_on->gte($two->starts_on)) {
            $blockers[] = 'Rentang semester tidak valid atau saling tumpang tindih.';
        }

        $effective = [1 => 0, 2 => 0];
        foreach ([1, 2] as $semester) {
            $plan = $plans->get($semester);
            if (! $plan) {
                $blockers[] = "RPP Semester {$semester} belum tersedia.";

                continue;
            }
            $effective[$semester] = $this->calendar->weeksForPlan($plan, true)->count();
            if ($effective[$semester] === 0) {
                $blockers[] = "Semester {$semester} tidak memiliki minggu efektif.";
            }
        }

        return [
            'key' => 'calendar',
            'label' => 'Periksa kalender akademik',
            'complete' => $blockers === [],
            'current' => collect($effective)->filter(fn ($count) => $count > 0)->count(),
            'target' => 2,
            'summary' => $blockers === []
                ? "Rentang valid: Semester 1 {$effective[1]} minggu efektif dan Semester 2 {$effective[2]} minggu efektif."
                : implode(' ', $blockers),
            'blockers' => $blockers,
            'action' => 'calendar',
            'diagnostics' => [
                'semester_1_effective_weeks' => $effective[1],
                'semester_2_effective_weeks' => $effective[2],
            ],
        ];
    }

    private function semesterStep(?RppPlan $plan, int $semester): array
    {
        if (! $plan) {
            return [
                'key' => "semester_{$semester}",
                'label' => "Validasi RPP Semester {$semester}",
                'complete' => false,
                'current' => 0,
                'target' => 100,
                'summary' => "RPP Semester {$semester} belum tersedia.",
                'blockers' => ["RPP Semester {$semester} belum tersedia."],
                'action' => "semester_{$semester}",
                'diagnostics' => [
                    'syllabus_missing' => 0,
                    'target_issue_count' => 0,
                    'validation_pending' => false,
                    'can_validate' => false,
                ],
            ];
        }

        $total = $plan->level->syllabusItems()
            ->where('is_duplicate', false)
            ->whereIn('semester_scope', [(string) $semester, 'both'])
            ->count();
        $planned = $plan->items()->whereNotNull('syllabus_item_id')->distinct('syllabus_item_id')->count('syllabus_item_id');
        $coverage = $total > 0 ? round(($planned / $total) * 100, 1) : 0.0;
        $targets = RppProgressTarget::query()->with(['syllabusItem', 'placements'])->where('rpp_plan_id', $plan->id)->get();
        $incompleteTargets = $targets->filter(fn (RppProgressTarget $target) => ! $this->progress->isComplete($target));
        $tilawati = $targets->first(fn (RppProgressTarget $target) => str_contains(mb_strtolower((string) $target->syllabusItem?->title), 'tilawati'));
        $expected = $semester === 1 ? [1, 22] : [23, 44];
        $blockers = [];
        $targetIssueCount = 0;

        if ($coverage < 100) {
            $missing = max(0, $total - $planned);
            $blockers[] = "Cakupan Silabus Semester {$semester} masih {$coverage}% ({$missing} materi belum dijadwalkan).";
        }
        if (! $tilawati) {
            $targetIssueCount++;
            $blockers[] = "Target Tilawati Semester {$semester} belum tersedia.";
        } elseif ((int) $tilawati->range_start !== $expected[0] || (int) $tilawati->range_end !== $expected[1]) {
            $targetIssueCount++;
            $blockers[] = "Target Tilawati Semester {$semester} harus halaman {$expected[0]}–{$expected[1]}.";
        } elseif (! $this->progress->isComplete($tilawati)) {
            $targetIssueCount++;
            $summary = $this->progress->progressSummary($tilawati);
            $blockers[] = "Target Tilawati Semester {$semester} masih tersisa {$summary['remaining']} halaman.";
        }
        foreach ($incompleteTargets as $target) {
            if ($target->is($tilawati)) {
                continue;
            }
            $targetIssueCount++;
            $summary = $this->progress->progressSummary($target);
            $blockers[] = "Target {$target->syllabusItem?->title} masih tersisa {$summary['remaining']} {$target->unit_label}.";
        }
        if ($plan->status !== 'validated') {
            $blockers[] = "RPP Semester {$semester} belum divalidasi.";
        }

        return [
            'key' => "semester_{$semester}",
            'label' => "Validasi RPP Semester {$semester}",
            'complete' => $blockers === [],
            'current' => $coverage,
            'target' => 100,
            'summary' => $blockers === []
                ? "Silabus 100%, Tilawati halaman {$expected[0]}–{$expected[1]} selesai, dan semester tervalidasi."
                : implode(' ', $blockers),
            'blockers' => $blockers,
            'action' => "semester_{$semester}",
            'diagnostics' => [
                'syllabus_missing' => $missing ?? 0,
                'target_issue_count' => $targetIssueCount,
                'validation_pending' => $plan->status !== 'validated',
                'can_validate' => ($missing ?? 0) === 0 && $targetIssueCount === 0,
            ],
        ];
    }
}
