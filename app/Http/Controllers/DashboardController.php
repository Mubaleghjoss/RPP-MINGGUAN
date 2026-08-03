<?php

namespace App\Http\Controllers;

use App\Models\AuditFinding;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\SourceDocument;
use App\Models\SyllabusItem;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $levels = Level::query()
            ->withCount(['ggbItems', 'syllabusItems'])
            ->with(['plans' => fn ($query) => $query->whereHas('academicYear', fn ($year) => $year->where('is_active', true))->orderBy('semester')])
            ->orderBy('sort_order')
            ->get();

        $linkCounts = DB::table('ggb_syllabus_links')->whereNull('deleted_at')->selectRaw('status, count(*) total')->groupBy('status')->pluck('total', 'status');
        $validated = RppPlan::query()->where('status', 'validated')->count();

        return view('dashboard', [
            'levels' => $levels,
            'summary' => [
                'levels' => $levels->count(),
                'documents' => SourceDocument::query()->count(),
                'syllabus' => SyllabusItem::query()->count(),
                'open_findings' => AuditFinding::query()->where('status', 'open')->count(),
                'validated' => $validated,
                'coverage' => round((float) RppPlan::query()->avg('coverage_percent'), 1),
            ],
            'linkCounts' => $linkCounts,
        ]);
    }
}
