<?php

namespace Tests\Feature;

use App\Services\CurriculumWorkbookExporter;
use Database\Seeders\CurriculumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class WorkbookFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_without_local_template_contains_overview_and_seventeen_rpp_sheets(): void
    {
        $this->seed(CurriculumSeeder::class);
        $destination = storage_path('framework/testing/rpp-fallback.xlsx');

        app(CurriculumWorkbookExporter::class)->export($destination, storage_path('missing-template.xlsx'));

        $this->assertFileExists($destination);
        $workbook = IOFactory::load($destination);
        $this->assertSame(18, $workbook->getSheetCount());
        $this->assertSame('Overview', $workbook->getSheet(0)->getTitle());
        $this->assertContains('pra_nikah_4', $workbook->getSheetNames());
        $workbook->disconnectWorksheets();
        unlink($destination);
    }
}
