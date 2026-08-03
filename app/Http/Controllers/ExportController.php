<?php

namespace App\Http\Controllers;

use App\Models\Level;
use App\Services\CurriculumWorkbookExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function workbook(Request $request, CurriculumWorkbookExporter $exporter): BinaryFileResponse|RedirectResponse
    {
        $levelId = $request->integer('level');
        $semester = $request->integer('semester');
        if (! $levelId || ! in_array($semester, [1, 2], true)) {
            return redirect()->route('exports.index')->with('notice', 'Pilih jenjang dan semester sebelum membuat workbook.');
        }

        $level = Level::query()->findOrFail($levelId);
        $path = $exporter->exportLevelSemester($level, $semester);
        $year = str_replace('/', '-', $exporter->activeYearLabel());
        $code = str_replace([' ', '/'], '_', $level->code);

        return response()->download($path, "RPP_{$year}_{$code}_Semester_{$semester}.xlsx")->deleteFileAfterSend();
    }
}
