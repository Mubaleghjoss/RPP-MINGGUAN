<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\RppProgressTarget;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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

    private const BORDER = '64748B';

    public function __construct(
        private readonly RppProgressService $progress,
        private readonly RppMatrixService $matrix,
        private readonly RppMatrixPresetService $presets,
    ) {}

    public function activeYearLabel(): string
    {
        return AcademicYear::query()->where('is_active', true)->value('label') ?? '2026/2027';
    }

    public function export(?string $destination = null, ?string $templatePath = null): string
    {
        return $this->exportLevelSemester(Level::query()->orderBy('sort_order')->firstOrFail(), 1, $destination);
    }

    public function exportLevelSemester(Level $level, int $semester, ?string $destination = null): string
    {
        abort_unless(in_array($semester, [1, 2], true), 422);
        $year = AcademicYear::query()->where('is_active', true)->firstOrFail();
        $this->presets->syncLevel($level);
        $plan = RppPlan::query()
            ->where('academic_year_id', $year->id)
            ->where('level_id', $level->id)
            ->where('semester', $semester)
            ->with([
                'level', 'academicYear', 'items.week', 'items.matrixColumn',
                'items.syllabusItem.document', 'items.syllabusItem.ggbItems.document',
                'progressTargets.syllabusItem.document',
            ])->firstOrFail();
        $this->matrix->ensureMonthFocuses($plan);
        $plan->load('monthFocuses');
        $weeks = $year->weeks()->where('semester', $semester)->orderBy('week_number')->get();

        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $book->getProperties()
            ->setCreator('Sistem RPP PPG')
            ->setTitle("RPP {$level->name} Semester {$semester} {$year->label}")
            ->setDescription('Matriks RPP per jenjang dan semester berdasarkan GGB dan Silabus.');

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
            $this->writeTextRow($sheet, $row++, [
                $target->syllabusItem->stable_code.' · '.$target->syllabusItem->title,
                ucfirst($target->unit_label)." {$target->range_start}–{$target->range_end}",
                $summary['achieved'].'/'.$summary['total'], $summary['remaining'],
                $summary['complete'] ? 'Tercapai' : 'Belum Tercapai', $target->syllabusItem->allocation_text,
                $target->syllabusItem->document->title.' hlm. '.$target->syllabusItem->source_page,
                $target->syllabusItem->source_semester === 'both' ? 'Semester 1 & 2' : 'Semester '.$target->syllabusItem->source_semester,
            ]);
        }
        if ($row === 9) {
            $sheet->mergeCells('A9:H9');
            $sheet->setCellValue('A9', 'Belum ada target progres terukur pada semester ini.');
            $row++;
        }

        $row += 2;
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->setCellValue("A{$row}", 'PEMETAAN KOLOM RPP');
        $this->styleHeader($sheet, "A{$row}:H{$row}");
        $row++;
        $sheet->fromArray(['Aspek GGB', 'Subaspek', 'Kolom RPP', 'Jumlah Materi', 'Status', '', '', ''], null, "A{$row}");
        $this->styleHeader($sheet, "A{$row}:H{$row}");
        $row++;
        foreach ($this->matrix->columns($plan) as $column) {
            $this->writeTextRow($sheet, $row++, [
                $column->aspect_label, $column->subaspect_label, $column->label,
                $column->mappings_count, $column->is_active ? 'Aktif' : 'Nonaktif', '', '', '',
            ]);
        }

        $this->styleTitle($sheet, 'A1:H1');
        $sheet->getStyle('A8:H'.($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color(self::BORDER));
        $sheet->getStyle('A3:H'.($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        foreach (['A' => 46, 'B' => 28, 'C' => 26, 'D' => 14, 'E' => 20, 'F' => 34, 'G' => 42, 'H' => 20] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A9');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
    }

    private function buildRpp(Spreadsheet $book, RppPlan $plan, $weeks): void
    {
        $sheet = new Worksheet($book, "RPP Semester {$plan->semester}");
        $book->addSheet($sheet);
        $columns = $this->matrix->columns($plan);
        $itemsByCell = $this->matrix->itemsByCell($plan);
        $lastColumnIndex = 5 + $columns->count();
        $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);
        $materialStart = 5;
        $materialEnd = 4 + $columns->count();
        $row = 1;

        foreach ($weeks->chunk(13)->values() as $trimesterIndex => $trimesterWeeks) {
            $trimester = $this->matrix->trimesterNumber((int) $plan->semester, $trimesterIndex);
            $titleRow = $row;
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->setCellValue("A{$row}", 'RENCANA PROGRAM PEMBELAJARAN');
            $row++;
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->setCellValue("A{$row}", strtoupper($plan->level->name)." · SEMESTER {$plan->semester} · TRIWULAN {$trimester} · {$plan->academicYear->label}");
            $row += 2;

            $headerStart = $row;
            foreach ([1 => 'BULAN', 2 => "Fokus\n29 Karakter Luhur", 3 => 'Pekan', 4 => 'Tanggal'] as $column => $label) {
                $coordinate = Coordinate::stringFromColumnIndex($column);
                $sheet->mergeCells("{$coordinate}{$row}:{$coordinate}".($row + 2));
                $sheet->setCellValue("{$coordinate}{$row}", $label);
            }
            $sheet->mergeCells("{$lastColumn}{$row}:{$lastColumn}".($row + 2));
            $sheet->setCellValue("{$lastColumn}{$row}", "Paraf\nPengajar");

            $this->writeGroupedHeaders($sheet, $columns, 'aspect_label', $materialStart, $row);
            $this->writeGroupedHeaders($sheet, $columns, 'subaspect_label', $materialStart, $row + 1);
            foreach ($columns->values() as $index => $column) {
                $sheet->setCellValue([5 + $index, $row + 2], $column->label);
            }
            $row += 3;

            $monthRanges = [];
            foreach ($trimesterWeeks as $week) {
                $monthKey = $week->starts_on->format('Y-m');
                $monthRanges[$monthKey] ??= ['start' => $row, 'end' => $row];
                $monthRanges[$monthKey]['end'] = $row;
                $sheet->setCellValueExplicit([1, $row], mb_strtoupper($week->month_label), DataType::TYPE_STRING);
                $focus = $plan->monthFocuses->firstWhere('month_key', $monthKey);
                $sheet->setCellValueExplicit([2, $row], (string) $focus?->focus_text, DataType::TYPE_STRING);
                $monthOrdinal = $trimesterWeeks->filter(fn ($candidate) => $candidate->starts_on->format('Y-m') === $monthKey && $candidate->week_number <= $week->week_number)->count();
                $sheet->setCellValueExplicit([3, $row], (string) $monthOrdinal, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([4, $row], $week->starts_on->format('d M Y'), DataType::TYPE_STRING);

                if (! $week->is_effective) {
                    $start = Coordinate::stringFromColumnIndex($materialStart);
                    $end = Coordinate::stringFromColumnIndex($materialEnd);
                    if ($start !== $end) {
                        $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
                    }
                    $sheet->setCellValue("{$start}{$row}", $week->label ?: $this->weekType($week->type));
                    $sheet->getStyle("{$start}{$row}:{$end}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
                } else {
                    foreach ($columns->values() as $index => $column) {
                        $cellItems = collect($itemsByCell->get($week->id.':'.$column->id, []));
                        $value = $cellItems->map(function ($item) {
                            $progress = $item->progress_start === null ? '' : "\n".($item->progress_kind === 'penguatan' ? 'Penguatan ' : '').$item->progress_start.'–'.$item->progress_end;

                            return $item->content.$progress;
                        })->implode("\n\n");
                        $sheet->setCellValueExplicit([5 + $index, $row], $value, DataType::TYPE_STRING);
                        if ($cellItems->isNotEmpty()) {
                            $coordinate = Coordinate::stringFromColumnIndex(5 + $index).$row;
                            $comment = $sheet->getComment($coordinate);
                            $comment->setAuthor('Sistem RPP PPG');
                            $comment->getText()->createTextRun($cellItems->map(fn ($item) => $this->matrix->sourceNote($item))->implode("\n\n---\n\n"));
                        }
                    }
                }
                $sheet->setCellValueExplicit([$lastColumnIndex, $row], '', DataType::TYPE_STRING);
                $sheet->getRowDimension($row)->setRowHeight(52);
                $row++;
            }

            foreach ($monthRanges as $range) {
                if ($range['start'] < $range['end']) {
                    $sheet->mergeCells("A{$range['start']}:A{$range['end']}");
                    $sheet->mergeCells("B{$range['start']}:B{$range['end']}");
                }
            }

            $endRow = $row - 1;
            $this->styleTitle($sheet, "A{$titleRow}:{$lastColumn}".($titleRow + 1));
            $this->styleHeader($sheet, "A{$headerStart}:{$lastColumn}".($headerStart + 2));
            $sheet->getStyle("A{$headerStart}:{$lastColumn}{$endRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color(self::BORDER));
            $sheet->getStyle("A{$headerStart}:{$lastColumn}{$endRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $sheet->getStyle("A{$headerStart}:{$lastColumn}".($headerStart + 2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if ($trimesterIndex === 0) {
                $sheet->setBreak('A'.$row, Worksheet::BREAK_ROW);
            }
            $row += 2;
        }

        foreach ([1 => 10, 2 => 20, 3 => 8, 4 => 15] as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth($width);
        }
        foreach ($columns->values() as $index => $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex(5 + $index))->setWidth(max(14, min(36, $column->width)));
        }
        $sheet->getColumnDimension($lastColumn)->setWidth(14);
        $sheet->freezePane('E7');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.2)->setRight(0.2);
        $sheet->getPageSetup()->setPrintArea("A1:{$lastColumn}".($row - 1));
    }

    private function writeGroupedHeaders(Worksheet $sheet, $columns, string $field, int $startColumn, int $row): void
    {
        $cursor = $startColumn;
        foreach ($this->matrix->headerGroups($columns, $field) as $group) {
            $start = Coordinate::stringFromColumnIndex($cursor);
            $end = Coordinate::stringFromColumnIndex($cursor + $group['span'] - 1);
            if ($start !== $end) {
                $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
            }
            $sheet->setCellValue("{$start}{$row}", $group['label']);
            $cursor += $group['span'];
        }
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
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 13],
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
            'evaluation' => 'Evaluasi', 'holiday' => 'Libur', 'religious_holiday' => 'Hari Raya', default => 'Minggu Efektif',
        };
    }
}
