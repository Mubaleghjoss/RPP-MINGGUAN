<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\RppProgressTarget;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CurriculumWorkbookExporter
{
    private const GREEN = '166534';

    private const GREEN_DARK = '14532D';

    private const BORDER = 'CBD5E1';

    public function __construct(private readonly RppProgressService $progress) {}

    public function activeYearLabel(): string
    {
        return AcademicYear::query()->where('is_active', true)->value('label') ?? '2026/2027';
    }

    /**
     * Kompatibilitas internal: workbook penuh dihentikan dan metode ini mengekspor
     * jenjang pertama Semester 1.
     */
    public function export(?string $destination = null, ?string $templatePath = null): string
    {
        return $this->exportLevelSemester(Level::query()->orderBy('sort_order')->firstOrFail(), 1, $destination);
    }

    public function exportLevelSemester(Level $level, int $semester, ?string $destination = null): string
    {
        abort_unless(in_array($semester, [1, 2], true), 422);
        $year = AcademicYear::query()->where('is_active', true)->firstOrFail();
        $plan = RppPlan::query()
            ->where('academic_year_id', $year->id)
            ->where('level_id', $level->id)
            ->where('semester', $semester)
            ->with([
                'items.week',
                'items.syllabusItem.document',
                'progressTargets.syllabusItem.document',
            ])->firstOrFail();
        $weeks = $year->weeks()->where('semester', $semester)->orderBy('week_number')->get();

        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $book->getProperties()
            ->setCreator('Sistem RPP PPG')
            ->setTitle("RPP {$level->name} Semester {$semester} {$year->label}")
            ->setDescription('Preview RPP per jenjang dan semester dengan target progres serta sumber halaman.');

        $this->buildSummary($book, $plan);
        $this->buildRpp($book, $plan, $weeks);
        $book->setActiveSheetIndex(0);

        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '_', $level->code);
        $yearLabel = str_replace('/', '-', $year->label);
        $destination ??= storage_path("app/exports/RPP_{$yearLabel}_{$safeCode}_Semester_{$semester}.xlsx");
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0775, true);
        }
        IOFactory::createWriter($book, 'Xlsx')->save($destination);
        $book->disconnectWorksheets();

        return $destination;
    }

    private function buildSummary(Spreadsheet $book, RppPlan $plan): void
    {
        $sheet = new Worksheet($book, 'Ringkasan');
        $book->addSheet($sheet);
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', "RINGKASAN RPP {$plan->level->name} · SEMESTER {$plan->semester}");
        $sheet->fromArray([
            ['Tahun Ajaran', $plan->academicYear->label, 'Status', $plan->status === 'validated' ? 'Tervalidasi' : 'Draf'],
            ['Jenjang', $plan->level->name, 'Cakupan', (float) $plan->coverage_percent / 100],
            ['Semester', $plan->semester, 'Dibuat', now()->format('d-m-Y H:i')],
        ], null, 'A3');
        $sheet->getStyle('D4')->getNumberFormat()->setFormatCode('0.0%');

        $sheet->fromArray(['Kode Materi', 'Target', 'Pencapaian', 'Sisa', 'Status', 'Alokasi', 'Sumber', 'Semester Sumber'], null, 'A8');
        $row = 9;
        foreach ($plan->progressTargets as $target) {
            /** @var RppProgressTarget $target */
            $summary = $this->progress->progressSummary($target);
            $values = [
                $target->syllabusItem->stable_code.' · '.$target->syllabusItem->title,
                ucfirst($target->unit_label)." {$target->range_start}–{$target->range_end}",
                $summary['achieved'].'/'.$summary['total'],
                $summary['remaining'],
                $summary['complete'] ? 'Tercapai' : 'Belum Tercapai',
                $target->syllabusItem->allocation_text,
                $target->syllabusItem->document->title.' hlm. '.$target->syllabusItem->source_page,
                $target->syllabusItem->source_semester === 'both' ? 'Semester 1 & 2' : 'Semester '.$target->syllabusItem->source_semester,
            ];
            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $row], (string) $value, DataType::TYPE_STRING);
            }
            $row++;
        }
        if ($row === 9) {
            $sheet->mergeCells('A9:H9');
            $sheet->setCellValue('A9', 'Belum ada target progres terukur pada semester ini.');
            $row++;
        }

        $this->styleTitle($sheet, 'A1:H1');
        $this->styleHeader($sheet, 'A8:H8');
        $sheet->getStyle('A8:H'.($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A3:H'.($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        foreach (['A' => 46, 'B' => 24, 'C' => 16, 'D' => 12, 'E' => 18, 'F' => 34, 'G' => 40, 'H' => 20] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A9');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
    }

    private function buildRpp(Spreadsheet $book, RppPlan $plan, $weeks): void
    {
        $name = "RPP Semester {$plan->semester}";
        $sheet = new Worksheet($book, $name);
        $book->addSheet($sheet);
        $sheet->mergeCells('A1:J1');
        $sheet->mergeCells('A2:J2');
        $sheet->setCellValue('A1', 'RENCANA PROGRAM PEMBELAJARAN GLOBAL MINGGUAN');
        $sheet->setCellValue('A2', strtoupper($plan->level->name)." · SEMESTER {$plan->semester} · TAHUN AJARAN {$plan->academicYear->label}");
        $sheet->fromArray(['Pekan', 'Tanggal', 'Jenis Minggu', 'Kategori', 'Materi', 'Rentang Progres', 'Jenis Progres', 'Posisi', 'Kunci', 'Sumber'], null, 'A4');

        $itemsByWeek = $plan->items->sortBy(fn ($item) => sprintf('%03d-%03d', $item->week->week_number, $item->position))->groupBy('calendar_week_id');
        $row = 5;
        foreach ($weeks as $week) {
            $items = collect($itemsByWeek->get($week->id, []));
            if ($items->isEmpty()) {
                $values = [
                    'M'.$week->week_number,
                    $week->starts_on->format('d-m-Y'),
                    $this->weekType($week->type),
                    '',
                    $week->is_effective ? '' : ($week->label ?: $this->weekType($week->type)),
                    '', '', '', '', '',
                ];
                $this->writeTextRow($sheet, $row++, $values);

                continue;
            }
            foreach ($items as $item) {
                $range = $item->progress_start !== null
                    ? ($item->progress_start === $item->progress_end ? (string) $item->progress_start : "{$item->progress_start}–{$item->progress_end}")
                    : '';
                $values = [
                    'M'.$week->week_number,
                    $week->starts_on->format('d-m-Y'),
                    $this->weekType($week->type),
                    $item->strand,
                    $item->content,
                    $range,
                    match ($item->progress_kind) {
                        'materi_baru' => 'Materi Baru', 'penguatan' => 'Penguatan', default => ''
                    },
                    $item->position,
                    $item->is_locked ? 'Dikunci' : 'Terbuka',
                    $item->syllabusItem->document->title.' hlm. '.$item->syllabusItem->source_page,
                ];
                $this->writeTextRow($sheet, $row++, $values);
            }
        }

        $end = $row - 1;
        $this->styleTitle($sheet, 'A1:J2');
        $this->styleHeader($sheet, 'A4:J4');
        $sheet->getStyle("A4:J{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color(self::BORDER));
        $sheet->getStyle("A5:J{$end}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        foreach (['A' => 10, 'B' => 16, 'C' => 18, 'D' => 28, 'E' => 58, 'F' => 18, 'G' => 18, 'H' => 10, 'I' => 14, 'J' => 40] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('D5');
        $sheet->setAutoFilter("A4:J{$end}");
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 4);
    }

    private function writeTextRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $column => $value) {
            $sheet->setCellValueExplicit([$column + 1, $row], (string) ($value ?? ''), DataType::TYPE_STRING);
        }
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
        ]);
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
