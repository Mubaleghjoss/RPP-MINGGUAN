<?php

namespace App\Http\Controllers;

use App\Models\AuditFinding;
use App\Models\Level;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __invoke(): View
    {
        $statusCounts = DB::table('ggb_syllabus_links')->whereNull('deleted_at')->selectRaw('status, count(*) total')->groupBy('status')->pluck('total', 'status');
        $links = DB::table('ggb_syllabus_links as links')
            ->join('syllabus_items as silabus', 'silabus.id', '=', 'links.syllabus_item_id')
            ->join('ggb_items as ggb', 'ggb.id', '=', 'links.ggb_item_id')
            ->join('levels', 'levels.id', '=', 'silabus.level_id')
            ->whereNull('links.deleted_at')
            ->where('links.status', '!=', 'sesuai')
            ->select(['links.id', 'links.status', 'links.confidence', 'levels.name as level_name', 'silabus.title as syllabus_title', 'silabus.source_page as syllabus_page', 'ggb.title as ggb_title', 'ggb.source_page as ggb_page'])
            ->orderBy('levels.sort_order')->orderBy('links.confidence')->paginate(50);
        $findings = AuditFinding::query()->with('level')->where('status', 'open')->orderByRaw("FIELD(severity, 'warning', 'info')")->get();
        return view('audit.index', compact('statusCounts', 'links', 'findings'));
    }
}
