<?php

use App\Models\Level;
use App\Services\CurriculumWorkbookExporter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('rpp:export {level : Kode jenjang, contoh PAUD} {semester : 1 atau 2} {destination?}', function () {
    $level = Level::query()->where('code', $this->argument('level'))->firstOrFail();
    $semester = (int) $this->argument('semester');
    if (! in_array($semester, [1, 2], true)) {
        $this->error('Semester harus 1 atau 2.');

        return 1;
    }
    $destination = $this->argument('destination');
    $path = app(CurriculumWorkbookExporter::class)->exportLevelSemester($level, $semester, $destination ?: null);
    $this->info('Workbook dibuat: '.$path);
})->purpose('Membuat workbook dua sheet untuk satu jenjang dan semester');
