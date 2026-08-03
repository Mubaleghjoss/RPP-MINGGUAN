<?php

namespace App\Http\Controllers;

use App\Models\Level;
use App\Models\RppPlan;
use App\Services\CurriculumWorkbookExporter;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function index(): View
    {
        return view('exports.index', [
            'levelCount' => Level::query()->count(),
            'validatedCount' => RppPlan::query()->where('status', 'validated')->count(),
            'averageCoverage' => round((float) RppPlan::query()->avg('coverage_percent'), 1),
        ]);
    }

    public function workbook(CurriculumWorkbookExporter $exporter): BinaryFileResponse
    {
        $path = $exporter->export();
        return response()->download($path, 'RPP_26_27_TangKot_Terverifikasi.xlsx');
    }
}
