<?php

namespace App\Console\Commands;

use App\Models\Level;
use App\Services\GgbOutlineService;
use App\Services\RppMaterialCatalogService;
use App\Services\RppMatrixFillService;
use App\Services\RppPlanner;
use Illuminate\Console\Command;

class NormalizeRppMaterials extends Command
{
    protected $signature = 'rpp:normalize-materials {--level= : Kode jenjang, misalnya PAUD} {--generate : Susun ulang plan setelah normalisasi}';

    protected $description = 'Normalisasi peran GGB, sinkronkan Bank Kegiatan, dan opsional susun ulang matriks';

    public function handle(
        GgbOutlineService $outline,
        RppMaterialCatalogService $catalog,
        RppPlanner $planner,
        RppMatrixFillService $matrixFill,
    ): int {
        $levels = Level::query()
            ->when($this->option('level'), fn ($query, $code) => $query->where('code', $code))
            ->orderBy('sort_order')->get();
        if ($levels->isEmpty()) {
            $this->error('Jenjang tidak ditemukan.');

            return self::FAILURE;
        }

        $rows = [];
        $gapDetails = [];
        foreach ($levels as $level) {
            $counts = $outline->classifyLevel($level);
            $catalog->syncLevel($level);
            $plans = $level->plans()->with(['level.syllabusItems', 'academicYear.weeks', 'items', 'progressTargets'])
                ->orderBy('semester')->get();
            if ($this->option('generate')) {
                $plans->each(fn ($plan) => $planner->generate($plan));
                $plans->each(fn ($plan) => $matrixFill->fill($plan->fresh()));
            }
            $matrix = $plans->map(function ($plan) use ($matrixFill) {
                $stats = $matrixFill->stats($plan->fresh());

                return 'S'.$plan->semester.': '.$stats['filled'].'/'.$stats['total'];
            })->implode(', ');
            foreach ($plans as $plan) {
                $stats = $matrixFill->stats($plan->fresh());
                foreach ($stats['gaps']->groupBy('column')->map->count() as $column => $count) {
                    $gapDetails[] = "{$level->code} S{$plan->semester} · {$column}: {$count} sel - ".$stats['gaps']->firstWhere('column', $column)['reason'];
                }
            }
            $activityCount = $level->materialCatalogItems()->where('source_kind', 'activity')->where('is_active', true)->count();
            $rows[] = [$level->code, $counts['material'], $counts['heading'], $counts['artifact'], $activityCount, $matrix, $this->option('generate') ? 'Ya' : 'Tidak'];
        }

        $this->table(['Jenjang', 'Materi', 'Subjudul', 'Artefak', 'Bank kegiatan', 'Kelengkapan matriks', 'Disusun ulang'], $rows);
        foreach ($gapDetails as $detail) {
            $this->warn($detail);
        }

        return self::SUCCESS;
    }
}
