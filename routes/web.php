<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\SourceDocumentController;
use App\Livewire\CalendarManager;
use App\Livewire\CurriculumEditor;
use App\Livewire\ExportPreview;
use App\Livewire\Planner;
use App\Livewire\RevisionHistory;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/kurikulum', [CurriculumController::class, 'index'])->name('curriculum.index');
    Route::get('/kurikulum/{level}/editor', CurriculumEditor::class)->name('curriculum.edit');
    Route::get('/kurikulum/{level}', [CurriculumController::class, 'show'])->name('curriculum.show');
    Route::get('/revisi', RevisionHistory::class)->name('revisions.index');
    Route::get('/audit', AuditController::class)->name('audit.index');
    Route::get('/kalender', CalendarManager::class)->name('calendar.index');
    Route::get('/rpp/{level}', Planner::class)->name('planner.show');
    Route::get('/dokumen/{sourceDocument}', SourceDocumentController::class)->name('documents.show');
    Route::get('/ekspor', ExportPreview::class)->name('exports.index');
    Route::get('/ekspor/workbook', [ExportController::class, 'workbook'])->name('exports.workbook');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
