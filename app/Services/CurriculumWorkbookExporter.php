<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\GgbItem;
use App\Models\Level;
use App\Models\RppPlan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CurriculumWorkbookExporter
{
    private const GREEN = '166534';
    private const GREEN_DARK = '14532D';
    private const GREEN_LIGHT = 'DCFCE7';
    private const AMBER = 'D97706';
    private const RED = 'B91C1C';
    private const SLATE = '334155';
    private const BORDER = 'CBD5E1';

    public function export(?string $destination = null, ?string $templatePath = null): string
    {
        $template = $templatePath ?? base_path('3. RPP 26_27 daerah TangKot.xlsx');
        $book = is_file($template) ? IOFactory::load($template) : new Spreadsheet();
        while ($book->getSheetCount() > 0) {
            $book->removeSheetByIndex(0);
        }
        $book->getProperties()
            ->setCreator('Sistem RPP PPG')
            ->setTitle('RPP Global Mingguan 2026/2027 Terverifikasi')
            ->setDescription('Turunan GGB ke silabus dan RPP global mingguan dengan sumber halaman.');

        $year = AcademicYear::query()->where('is_active', true)->with('weeks')->firstOrFail();
        $levels = Level::query()->with(['documents', 'plans' => fn ($query) => $query->where('academic_year_id', $year->id)])->orderBy('sort_order')->get();
        $this->buildOverview($book, $levels, $year);
        foreach ($levels as $level) {
            $this->buildRppSheet($book, $level, $year);
        }
        $book->setActiveSheetIndex(0);

        $destination ??= storage_path('app/exports/RPP_26_27_TangKot_Terverifikasi.xlsx');
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0775, true);
        }
        IOFactory::createWriter($book, 'Xlsx')->save($destination);
        $book->disconnectWorksheets();
        return $destination;
    }

    private function buildOverview(Spreadsheet $book, $levels, AcademicYear $year): void
    {
        $sheet = new Worksheet($book, 'Overview');
        $book->addSheet($sheet);
        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', 'AUDIT KELENGKAPAN GGB → SILABUS → RPP GLOBAL MINGGUAN');
        $sheet->setCellValue('A2', 'Tahun Ajaran');
        $sheet->setCellValue('B2', $year->label);
        $sheet->setCellValue('D2', 'Acuan utama');
        $sheet->setCellValue('E2', 'GGB');
        $sheet->setCellValue('G2', 'Dokumen');
        $sheet->setCellValue('H2', '17 GGB + 17 Silabus');
        $sheet->mergeCells('A3:J3');
        $sheet->setCellValue('A3', 'Legenda ID: 8-SMP / FAQIH / 001 = jenjang / aspek / urutan. Kode lama seperti a1, b4, c17-20 diganti nama materi lengkap, status turunan, dan halaman sumber.');
        $sheet->getStyle('A3')->getAlignment()->setWrapText(true);

        $summaryHeaders = ['Jenjang', 'Materi GGB', 'Silabus Total', 'Terjadwal', 'Perlu Alokasi', 'Duplikat', 'Belum Terjadwal', 'Sesuai GGB', 'Cakupan RPP', 'Status'];
        $sheet->fromArray($summaryHeaders, null, 'A5');
        $row = 6;
        foreach ($levels as $level) {
            $linkCounts = DB::table('ggb_syllabus_links')
                ->join('syllabus_items', 'syllabus_items.id', '=', 'ggb_syllabus_links.syllabus_item_id')
                ->where('syllabus_items.level_id', $level->id)
                ->whereNull('ggb_syllabus_links.deleted_at')
                ->selectRaw('status, count(*) total')->groupBy('status')->pluck('total', 'status');
            $plan = $level->plans->first();
            $planned = $plan?->items()->distinct('syllabus_item_id')->count('syllabus_item_id') ?? 0;
            $syllabus = $level->syllabusItems()->count();
            $duplicates = $level->syllabusItems()->where('is_duplicate', true)->count();
            $needsAllocation = $level->syllabusItems()->where('is_duplicate', false)->where('needs_allocation', true)->count();
            $canonical = $syllabus - $duplicates;
            $sheet->fromArray([
                $level->name,
                $level->ggbItems()->count(),
                $syllabus,
                $planned,
                $needsAllocation,
                $duplicates,
                max(0, $canonical - $planned),
                (int) ($linkCounts['sesuai'] ?? 0),
                (float) ($plan?->coverage_percent ?? 0) / 100,
                $plan?->status === 'validated' ? 'Tervalidasi' : 'Draf',
            ], null, "A{$row}");
            $sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode('0.0%');
            $row++;
        }

        $detailStart = $row + 3;
        $detailHeaders = ['ID Materi', 'Jenjang', 'Aspek', 'Subaspek', 'Materi GGB', 'Turunan Silabus', 'Alokasi', 'Status GGB→Silabus', 'Minggu RPP', 'Status RPP', 'Sumber / Halaman'];
        $sheet->fromArray($detailHeaders, null, "A{$detailStart}");
        $row = $detailStart + 1;

        GgbItem::query()
            ->with(['level', 'document', 'syllabusItems.document', 'syllabusItems.placements.week'])
            ->orderBy('level_id')->orderBy('sort_order')
            ->chunk(250, function ($items) use ($sheet, &$row) {
                foreach ($items as $ggb) {
                    $syllabus = $ggb->syllabusItems;
                    $titles = $syllabus->pluck('title')->unique()->implode("\n");
                    $allocations = $syllabus->pluck('allocation_text')->filter()->unique()->implode("\n");
                    $statuses = $syllabus->pluck('pivot.status')->unique();
                    $status = $statuses->isEmpty() ? 'Belum Diturunkan' : $statuses->map(fn ($value) => match ($value) {
                        'sesuai' => 'Sesuai',
                        'sebagian' => 'Sebagian',
                        default => 'Perlu Verifikasi',
                    })->implode(', ');
                    $weeks = $syllabus->flatMap->placements->pluck('week.week_number')->filter()->unique()->sort()->map(fn ($week) => 'M'.$week)->implode(', ');
                    $rppStatus = $weeks ? 'Terjadwal' : match (true) {
                        $syllabus->isEmpty() => 'Belum Masuk',
                        $syllabus->every(fn ($item) => $item->is_duplicate) => 'Duplikat',
                        $syllabus->contains(fn ($item) => $item->needs_allocation && ! $item->is_duplicate) => 'Perlu Alokasi',
                        default => 'Belum Dijadwalkan',
                    };
                    $documents = $syllabus->map(fn ($item) => $item->document->title.' hlm. '.$item->source_page)->unique()->implode("\n");
                    $source = $ggb->document->title.' hlm. '.$ggb->source_page.($documents ? "\nSilabus: ".$documents : '');
                    $values = [$ggb->stable_code, $ggb->level->name, $ggb->aspect, $ggb->subaspect, $ggb->title, $titles, $allocations, $status, $weeks, $rppStatus, $source];
                    foreach ($values as $column => $value) {
                        $coordinate = Coordinate::stringFromColumnIndex($column + 1).$row;
                        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                    }
                    $row++;
                }
            });

        $summaryEnd = 5 + $levels->count();
        $detailEnd = $row - 1;
        $this->styleTitle($sheet, 'A1:J1');
        $this->styleHeader($sheet, 'A5:J5');
        $this->styleHeader($sheet, "A{$detailStart}:K{$detailStart}");
        $sheet->getStyle("A6:J{$summaryEnd}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::BORDER));
        $sheet->getStyle("A".($detailStart + 1).":K{$detailEnd}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("A{$detailStart}:K{$detailEnd}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('E2E8F0'));
        $sheet->freezePane("A".($detailStart + 1));
        $sheet->setAutoFilter("A{$detailStart}:K{$detailEnd}");
        foreach (['A' => 28, 'B' => 18, 'C' => 22, 'D' => 24, 'E' => 48, 'F' => 48, 'G' => 38, 'H' => 20, 'I' => 24, 'J' => 20, 'K' => 42] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($detailStart, $detailStart);
    }

    private function buildRppSheet(Spreadsheet $book, Level $level, AcademicYear $year): void
    {
        $name = $this->sheetName($level->code);
        $sheet = new Worksheet($book, $name);
        $book->addSheet($sheet);
        $plan = RppPlan::query()->where('academic_year_id', $year->id)->where('level_id', $level->id)
            ->with(['items.syllabusItem', 'items.week'])->firstOrFail();
        $weeks = $year->weeks->sortBy('week_number')->values();
        $strands = $level->syllabusItems()->where('is_duplicate', false)->select('category')->distinct()->orderByRaw('MIN(sort_order)')->groupBy('category')->pluck('category')->values();
        $lastColumn = Coordinate::stringFromColumnIndex(4 + max(1, $strands->count()));

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A1', 'RENCANA PROGRAM PEMBELAJARAN GLOBAL MINGGUAN');
        $sheet->setCellValue('A2', strtoupper($level->name).' · TAHUN AJARAN '.$year->label);
        $headers = ['Bulan', 'Pekan', 'Tanggal', 'Jenis Minggu', ...$strands->all()];
        $sheet->fromArray($headers, null, 'A4');
        $itemsByWeek = $plan->items->groupBy('calendar_week_id');
        $row = 5;
        foreach ($weeks as $week) {
            $sheet->setCellValueExplicit("A{$row}", $week->month_label, DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", $week->week_number);
            $sheet->setCellValue("C{$row}", $week->starts_on);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('dd mmmm yyyy');
            $sheet->setCellValueExplicit("D{$row}", $this->weekType($week->type), DataType::TYPE_STRING);
            foreach ($strands as $index => $strand) {
                $column = Coordinate::stringFromColumnIndex(5 + $index);
                $content = collect($itemsByWeek->get($week->id, []))->where('strand', $strand)->pluck('content')->unique()->implode("\n");
                if (! $week->is_effective) {
                    $content = $week->label ?: $this->weekType($week->type);
                }
                $sheet->setCellValueExplicit("{$column}{$row}", $content, DataType::TYPE_STRING);
            }
            $row++;
        }
        $end = $row - 1;
        $this->styleTitle($sheet, "A1:{$lastColumn}2");
        $this->styleHeader($sheet, "A4:{$lastColumn}4");
        $sheet->getStyle("A5:{$lastColumn}{$end}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("A4:{$lastColumn}{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::BORDER));
        $sheet->freezePane('E5');
        $sheet->setAutoFilter("A4:{$lastColumn}{$end}");
        foreach (['A' => 14, 'B' => 9, 'C' => 16, 'D' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        for ($column = 5; $column <= 4 + $strands->count(); $column++) {
            $sheet->getColumnDimensionByColumn($column)->setWidth(34);
        }
        for ($dataRow = 5; $dataRow <= $end; $dataRow++) {
            $sheet->getRowDimension($dataRow)->setRowHeight(42);
        }
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 4);
        $sheet->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.25)->setRight(0.25);
    }

    private function styleTitle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GREEN_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GREEN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER]]],
        ]);
    }

    private function sheetName(string $code): string
    {
        return match ($code) {
            'PAUD' => 'paud',
            '1-SD', '2-SD', '3-SD', '4-SD', '5-SD', '6-SD' => strtolower(str_replace('-', '_', $code)),
            '1-SMP' => '7_smp', '2-SMP' => '8_smp', '3-SMP' => '9_smp',
            '1-SMA' => '10_sma', '2-SMA' => '11_sma', '3-SMA' => '12_sma',
            'PM-1' => 'pra_nikah_1', 'PM-2' => 'pra_nikah_2', 'PM-3' => 'pra_nikah_3', 'PM-4' => 'pra_nikah_4',
            default => strtolower(str_replace('-', '_', $code)),
        };
    }

    private function weekType(string $type): string
    {
        return match ($type) {
            'evaluation' => 'Evaluasi',
            'holiday' => 'Libur',
            'religious_holiday' => 'Hari Raya',
            default => 'Minggu Efektif',
        };
    }
}
