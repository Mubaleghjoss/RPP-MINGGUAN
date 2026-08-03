<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use App\Services\CurriculumWorkbookExporter;
use Database\Seeders\CurriculumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class WorkbookFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_semester_export_contains_only_summary_and_rpp_sheets(): void
    {
        $this->seed(CurriculumSeeder::class);
        $destination = storage_path('framework/testing/rpp-fallback.xlsx');

        $level = Level::query()->where('code', 'PAUD')->firstOrFail();
        app(CurriculumWorkbookExporter::class)->exportLevelSemester($level, 1, $destination);

        $this->assertFileExists($destination);
        $workbook = IOFactory::load($destination);
        $this->assertSame(2, $workbook->getSheetCount());
        $this->assertSame(['Ringkasan', 'RPP Semester 1'], $workbook->getSheetNames());
        $this->assertStringContainsString('PAUD', (string) $workbook->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $workbook->getSheet(0)->getCell('B9')->getDataType());
        $this->assertStringContainsString('1–22', (string) $workbook->getSheet(0)->getCell('B9')->getValue());
        $rppText = collect($workbook->getSheet(1)->toArray())->flatten()->implode('|');
        $this->assertStringContainsString('TRIWULAN 1', $rppText);
        $this->assertStringContainsString('TRIWULAN 2', $rppText);
        $this->assertStringNotContainsString('TRIWULAN 3', $rppText);
        $this->assertStringContainsString('I. Alim-Faqih', $rppText);
        $this->assertNotEmpty($workbook->getSheet(1)->getMergeCells());
        $this->assertTrue(collect($workbook->getSheet(1)->getComments())->contains(fn ($comment) => str_contains($comment->getText()->getPlainText(), 'Silabus:')));
        $workbook->disconnectWorksheets();
        unlink($destination);
    }

    public function test_legacy_export_endpoint_requires_level_and_semester_selection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('exports.workbook'))
            ->assertRedirect(route('exports.index'))
            ->assertSessionHas('notice');
    }
}
