<?php

namespace App\Http\Controllers;

use App\Models\Level;
use Illuminate\View\View;

class CurriculumController extends Controller
{
    public function index(): View
    {
        $levels = Level::query()->withCount(['ggbItems', 'syllabusItems'])->with('documents')->orderBy('sort_order')->get();
        return view('curriculum.index', compact('levels'));
    }

    public function show(Level $level): View
    {
        $level->load('documents');
        $ggbItems = $level->ggbItems()->orderBy('sort_order')->paginate(60, ['*'], 'ggb_page');
        $syllabusItems = $level->syllabusItems()
            ->with(['ggbItems' => fn ($query) => $query->select('ggb_items.id', 'ggb_items.title', 'ggb_items.source_page')])
            ->orderBy('sort_order')->paginate(40, ['*'], 'silabus_page');
        return view('curriculum.show', compact('level', 'ggbItems', 'syllabusItems'));
    }
}
