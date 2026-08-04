<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CalendarEvent;
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

    public function test_selected_semester_export_contains_summary_rpp_and_two_semester_material_catalog(): void
    {
        $this->seed(CurriculumSeeder::class);
        $destination = storage_path('framework/testing/rpp-fallback.xlsx');

        $level = Level::query()->where('code', 'PAUD')->firstOrFail();
        $year = AcademicYear::query()->where('is_active', true)->firstOrFail();
        CalendarEvent::query()->create([
            'academic_year_id' => $year->id, 'type' => 'exam', 'title' => 'Ujian serentak PAUD',
            'details' => 'Evaluasi akhir triwulan dengan keterangan lengkap.',
            'starts_on' => '2026-07-08', 'ends_on' => '2026-07-09', 'applies_to_all' => true,
        ]);
        app(CurriculumWorkbookExporter::class)->exportLevelSemester($level, 1, $destination);

        $this->assertFileExists($destination);
        $workbook = IOFactory::load($destination);
        $this->assertSame(3, $workbook->getSheetCount());
        $this->assertSame(['Ringkasan', 'RPP Semester 1', 'Materi PAUD'], $workbook->getSheetNames());
        $this->assertStringContainsString('PAUD', (string) $workbook->getSheet(0)->getCell('A1')->getValue());
        $summaryText = collect($workbook->getSheet(0)->toArray())->flatten()->implode('|');
        $this->assertStringContainsString('Katalog materi', $summaryText);
        $this->assertStringContainsString('Cakupan GGB', $summaryText);
        $this->assertStringContainsString('Belum terpasang', $summaryText);
        $this->assertStringContainsString('Ujian serentak PAUD', $summaryText);
        $this->assertSame(DataType::TYPE_STRING, $workbook->getSheet(0)->getCell('B9')->getDataType());
        $this->assertStringContainsString('1–22', (string) $workbook->getSheet(0)->getCell('B9')->getValue());
        $rppText = collect($workbook->getSheet(1)->toArray())->flatten()->implode('|');
        $this->assertStringContainsString('TRIWULAN 1', $rppText);
        $this->assertStringContainsString('TRIWULAN 2', $rppText);
        $this->assertStringNotContainsString('TRIWULAN 3', $rppText);
        $this->assertStringContainsString('I. Alim-Faqih', $rppText);
        $this->assertStringContainsString('Evaluasi akhir triwulan dengan keterangan lengkap.', $rppText);
        $this->assertNotEmpty($workbook->getSheet(1)->getMergeCells());
        $this->assertTrue(collect($workbook->getSheet(1)->getComments())->contains(fn ($comment) => str_contains($comment->getText()->getPlainText(), 'Silabus:')));
        $this->assertTrue(collect($workbook->getSheet(1)->getHyperlinkCollection())->contains(fn ($link) => str_contains($link->getUrl(), "#'Materi PAUD'!")));
        $materialText = collect($workbook->getSheet(2)->toArray())->flatten()->implode('|');
        $this->assertStringContainsString('SEMESTER 1 DAN 2', $materialText);
        $this->assertStringContainsString('GGB', $materialText);
        $this->assertTrue(collect($workbook->getSheet(2)->getHyperlinkCollection())->contains(fn ($link) => str_contains($link->getUrl(), "#'RPP Semester 1'!")));
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
