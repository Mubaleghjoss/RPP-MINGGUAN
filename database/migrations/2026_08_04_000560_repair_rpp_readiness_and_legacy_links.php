<?php

use App\Models\AcademicYear;
use App\Models\Level;
use App\Services\RppReadinessRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rpp_week_item_materials') || ! Schema::hasColumn('ggb_items', 'rpp_role')) {
            return;
        }

        $repair = app(RppReadinessRepairService::class);
        AcademicYear::query()->whereHas('plans')->each(function (AcademicYear $year) use ($repair) {
            Level::query()->whereHas('plans', fn ($query) => $query->where('academic_year_id', $year->id))
                ->each(function (Level $level) use ($repair, $year) {
                    $preview = $repair->preview($year, $level);
                    if ($preview['legacy_links'] > 0 || $preview['matrix_gaps'] > 0) {
                        $repair->repair($year, $level);
                    }
                });
        });
    }

    public function down(): void
    {
        // Perbaikan relasi dan pengisian matriks tidak dibatalkan agar materi
        // manual serta riwayat revisi tidak hilang pada rollback skema.
    }
};
