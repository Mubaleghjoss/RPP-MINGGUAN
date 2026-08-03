<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\CurriculumWorkbookExporter;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('rpp:export {destination?}', function () {
    $destination = $this->argument('destination');
    $path = app(CurriculumWorkbookExporter::class)->export($destination ?: null);
    $this->info('Workbook dibuat: '.$path);
})->purpose('Membuat workbook Overview dan 17 RPP terverifikasi');
