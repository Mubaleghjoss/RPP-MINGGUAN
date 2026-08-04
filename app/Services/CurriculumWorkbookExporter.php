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
        private readonly RppMaterialCatalogService $catalog,
        private readonly AcademicCalendarService $calendar,
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
        $this->catalog->syncLevel($level);
        $plan = RppPlan::query()
            ->where('academic_year_id', $year->id)
            ->where('level_id', $level->id)
            ->where('semester', $semester)
            ->with([
                'level', 'academicYear', 'items.week', 'items.matrixColumn',
                'items.syllabusItem.document', 'items.syllabusItem.ggbItems.document',
                'items.materials.ggbItem.document', 'items.materials.ggbItem.syllabusItems.document',
                'items.materials.syllabusItem.document',
                'progressTargets.syllabusItem.document',
            ])->firstOrFail();
        $this->matrix->ensureMonthFocuses($plan);
        $plan->load('monthFocuses');
        $weeks = $this->calendar->weeksForPlan($plan);

        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $book->getProperties()
            ->setCreator('Sistem RPP PPG')
            ->setTitle("RPP {$level->name} Semester {$semester} {$year->label}")
            ->setDescription('Matriks RPP per jenjang dan semester berdasarkan GGB dan Silabus.');

        $this->buildSummary($book, $plan);
        $rppLinks = $this->buildRpp($book, $plan, $weeks);
        $materialAnchors = $this->buildMaterials($book, $plan, $rppLinks['catalog_cells']);
        foreach ($rppLinks['column_cells'] as $columnId => $coordinates) {
            if (! isset($materialAnchors[$columnId])) {
                continue;
            }
            foreach ($coordinates as $coordinate) {
                $rppLinks['sheet']->getCell($coordinate)->getHyperlink()
                    ->setUrl("#'{$materialAnchors[$columnId]['sheet']}'!A{$materialAnchors[$columnId]['row']}")
                    ->setTooltip('Buka kategori materi');
                $rppLinks['sheet']->getStyle($coordinate)->getFont()->setColor(new Color('0563C1'))->setUnderline(true);
            }
        }
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
        $catalogQuery = $plan->level->materialCatalogItems();
        $catalogTotal = (clone $catalogQuery)->count();
        $catalogUsed = (clone $catalogQuery)->whereHas('placements', fn ($query) => $query->where('rpp_plan_id', $plan->id))->count();
        $catalogMissing = max(0, $catalogTotal - $catalogUsed);
        $catalogPercent = $catalogTotal ? $catalogUsed / $catalogTotal : 1;
        $ggbCoverage = $this->catalog->coverage($plan);
        $sheet->fromArray([
            ['Tahun Ajaran', $plan->academicYear->label, 'Status', $plan->status === 'validated' ? 'Tervalidasi' : 'Draf', 'Katalog materi', $catalogTotal, 'Terpasang', $catalogUsed],
            ['Jenjang', $plan->level->name, 'Cakupan Silabus', (float) $plan->coverage_percent / 100, 'Belum terpasang', $catalogMissing, 'Cakupan katalog', $catalogPercent],
            ['Semester RPP', $plan->semester, 'Dibuat', now()->format('d-m-Y H:i'), 'Cakupan GGB', $ggbCoverage['percent'] / 100, 'GGB belum masuk', $ggbCoverage['missing']],
        ], null, 'A3');
        $sheet->getStyle('D4')->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle('H4')->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle('F5')->getNumberFormat()->setFormatCode('0.0%');
        $sheet->setCellValue('A6', 'Buka kamus materi lengkap dua semester');
        $sheet->mergeCells('A6:H6');
        $sheet->getCell('A6')->getHyperlink()->setUrl("#'{$this->materialSheetName($plan->level)}'!A1")->setTooltip('Buka sheet materi');
        $sheet->getStyle('A6')->getFont()->setColor(new Color('0563C1'))->setUnderline(true)->setBold(true);

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
        $sheet->setCellValue("A{$row}", 'KALENDER LIBUR, EVALUASI, DAN UJIAN');
        $this->styleHeader($sheet, "A{$row}:H{$row}");
        $row++;
        $events = $this->calendar->eventsForLevel($plan->academic_year_id, $plan->level_id)
            ->filter(fn ($event) => $event->starts_on->lte($plan->academicYear->ends_on) && $event->ends_on->gte($plan->academicYear->starts_on));
        if ($events->isEmpty()) {
            $sheet->mergeCells("A{$row}:H{$row}");
            $sheet->setCellValue("A{$row}", 'Tidak ada rentang kalender non-efektif.');
            $row++;
        } else {
            foreach ($events as $event) {
                $this->writeTextRow($sheet, $row++, [
                    $this->calendar->typeLabel($event->type), $event->title,
                    $event->starts_on->format('d-m-Y'), $event->ends_on->format('d-m-Y'),
                    $event->details, $event->applies_to_all ? 'Semua jenjang' : $event->levels->pluck('name')->implode(', '), '', '',
                ]);
            }
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

    private function buildRpp(Spreadsheet $book, RppPlan $plan, $weeks): array
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
        $columnCells = [];
        $catalogCells = [];

        foreach ($weeks->chunk(max(1, (int) ceil($weeks->count() / 2)))->values() as $trimesterIndex => $trimesterWeeks) {
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

            $this->writeMatrixHeaders($sheet, $columns, $materialStart, $row);
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

                if (! $week->resolved_is_effective) {
                    $start = Coordinate::stringFromColumnIndex($materialStart);
                    $end = Coordinate::stringFromColumnIndex($materialEnd);
                    if ($start !== $end) {
                        $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
                    }
                    $sheet->setCellValueExplicit("{$start}{$row}", (string) $week->resolved_label, DataType::TYPE_STRING);
                    $sheet->getStyle("{$start}{$row}:{$end}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
                } else {
                    foreach ($columns->values() as $index => $column) {
                        $cellItems = collect($itemsByCell->get($week->id.':'.$column->id, []));
                        $value = $cellItems->map(function ($item) {
                            $progress = $item->progress_start === null ? '' : "\n".($item->progress_kind === 'penguatan' ? 'Penguatan ' : '').$item->progress_start.'–'.$item->progress_end;

                            return $this->catalog->placementLabel($item).$progress;
                        })->implode("\n\n");
                        $sheet->setCellValueExplicit([5 + $index, $row], $value, DataType::TYPE_STRING);
                        if ($cellItems->isNotEmpty()) {
                            $coordinate = Coordinate::stringFromColumnIndex(5 + $index).$row;
                            $columnCells[$column->id][] = $coordinate;
                            foreach ($cellItems->flatMap->materials->unique('id') as $material) {
                                $catalogCells[$material->id] ??= $coordinate;
                            }
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

        return ['sheet' => $sheet, 'column_cells' => $columnCells, 'catalog_cells' => $catalogCells];
    }

    private function buildMaterials(Spreadsheet $book, RppPlan $plan, array $rppCatalogCells): array
    {
        $sheetName = $this->materialSheetName($plan->level);
        $sheet = new Worksheet($book, $sheetName);
        $book->addSheet($sheet);
        $sheet->mergeCells('A1:O1');
        $sheet->setCellValue('A1', 'KAMUS MATERI '.mb_strtoupper($plan->level->name).' · SEMESTER 1 DAN 2');
        $sheet->mergeCells('A2:O2');
        $sheet->setCellValue('A2', 'Kode berlaku tetap untuk dua semester. Klik kode yang sudah digunakan untuk kembali ke RPP semester terpilih.');
        $headers = [
            'Kode Ringkas', 'Sumber', 'Semester', 'Aspek GGB', 'Subaspek', 'Kolom RPP', 'Judul Materi',
            'Rincian GGB', 'Silabus Terkait', 'Alokasi', 'Status S1', 'Minggu S1', 'Status S2', 'Minggu S2', 'Dokumen / Halaman / Stable Code',
        ];
        $sheet->fromArray($headers, null, 'A4');
        $this->styleTitle($sheet, 'A1:O1');
        $this->styleHeader($sheet, 'A4:O4');

        $items = $plan->level->materialCatalogItems()->with([
            'matrixColumn',
            'ggbItem.document',
            'ggbItem.syllabusItems.document',
            'syllabusItem.document',
            'placements.plan',
            'placements.week',
        ])->get()->sortBy(fn ($item) => sprintf('%010d-%010d', $item->matrixColumn?->sort_order ?? 999999, $item->sort_order));
        $anchors = [];
        $row = 5;
        foreach ($items->groupBy(fn ($item) => $item->rpp_matrix_column_id ?: 0) as $columnId => $group) {
            $column = $group->first()->matrixColumn;
            $sheet->mergeCells("A{$row}:O{$row}");
            $sheet->setCellValue("A{$row}", $column
                ? $column->aspect_label.' · '.($column->subaspect_label ?: 'Tanpa Subaspek').' · '.$column->label
                : 'BELUM DIPETAKAN');
            $this->styleHeader($sheet, "A{$row}:O{$row}");
            $sheet->getRowDimension($row)->setRowHeight(24);
            if ($column) {
                $anchors[$column->id] = ['sheet' => $sheetName, 'row' => $row];
            }
            $row++;

            foreach ($group as $material) {
                $ggb = $material->ggbItem;
                $linkedSyllabus = $ggb ? $ggb->syllabusItems->where('is_duplicate', false) : collect([$material->syllabusItem])->filter();
                $yearPlacements = $material->placements->filter(fn ($placement) => (int) $placement->plan?->academic_year_id === (int) $plan->academic_year_id);
                $semesterOne = $yearPlacements->filter(fn ($placement) => (int) $placement->plan?->semester === 1)->sortBy(fn ($placement) => $placement->week?->week_number);
                $semesterTwo = $yearPlacements->filter(fn ($placement) => (int) $placement->plan?->semester === 2)->sortBy(fn ($placement) => $placement->week?->week_number);
                $source = $ggb ? ($linkedSyllabus->isNotEmpty() ? 'GGB + Silabus' : 'GGB') : 'Silabus tambahan';
                $sourceTrace = $ggb
                    ? $ggb->document->title.' hlm. '.$ggb->source_page.' · '.$ggb->stable_code
                    : $material->syllabusItem->document->title.' hlm. '.$material->syllabusItem->source_page.' · '.$material->syllabusItem->stable_code;
                $this->writeTextRow($sheet, $row, [
                    $material->display_code,
                    $source,
                    ! $material->semester_confirmed && $material->source_semester_scope === 'general'
                        ? 'Perlu konfirmasi Admin'
                        : ($material->semester_scope === 'both' ? 'Semester 1 & 2' : 'Semester '.$material->semester_scope),
                    $ggb?->aspect ?: $column?->aspect_label,
                    $ggb?->subaspect ?: $column?->subaspect_label,
                    $column?->label ?: 'Belum dipetakan',
                    $material->title,
                    $ggb?->raw_text ?: $material->syllabusItem?->description,
                    $linkedSyllabus->map(fn ($syllabus) => $syllabus->stable_code.' — '.$syllabus->title)->implode("\n"),
                    $linkedSyllabus->pluck('allocation_text')->filter()->unique()->implode("\n"),
                    $semesterOne->isNotEmpty() ? 'Terpasang' : 'Belum masuk',
                    $semesterOne->map(fn ($placement) => 'M'.$placement->week?->week_number)->unique()->implode(', '),
                    $semesterTwo->isNotEmpty() ? 'Terpasang' : 'Belum masuk',
                    $semesterTwo->map(fn ($placement) => 'M'.$placement->week?->week_number)->unique()->implode(', '),
                    $sourceTrace,
                ]);
                if (isset($rppCatalogCells[$material->id])) {
                    $sheet->getCell("A{$row}")->getHyperlink()
                        ->setUrl("#'RPP Semester {$plan->semester}'!{$rppCatalogCells[$material->id]}")
                        ->setTooltip('Kembali ke penggunaan pertama pada RPP');
                    $sheet->getStyle("A{$row}")->getFont()->setColor(new Color('0563C1'))->setUnderline(true);
                }
                $sheet->getRowDimension($row)->setRowHeight(46);
                $row++;
            }
        }

        $lastRow = max(4, $row - 1);
        $sheet->getStyle("A4:O{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color(self::BORDER));
        $sheet->getStyle("A2:O{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle('A2:O2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        foreach ([
            'A' => 20, 'B' => 18, 'C' => 18, 'D' => 22, 'E' => 22, 'F' => 24, 'G' => 38, 'H' => 42,
            'I' => 46, 'J' => 32, 'K' => 16, 'L' => 20, 'M' => 16, 'N' => 20, 'O' => 50,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:O{$lastRow}");
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);

        return $anchors;
    }

    private function materialSheetName(Level $level): string
    {
        $name = preg_replace('#[\\\\/?*\[\]:]+#u', '-', 'Materi '.$level->code) ?: 'Materi';

        return mb_substr($name, 0, 31);
    }

    private function writeMatrixHeaders(Worksheet $sheet, $columns, int $startColumn, int $row): void
    {
        $cursor = $startColumn;
        foreach ($this->catalog->headerTree($columns) as $aspect) {
            $start = Coordinate::stringFromColumnIndex($cursor);
            $end = Coordinate::stringFromColumnIndex($cursor + $aspect['span'] - 1);
            if ($start !== $end) {
                $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
            }
            $sheet->setCellValue("{$start}{$row}", $aspect['label']);
            $cursor += $aspect['span'];
        }

        $cursor = $startColumn;
        foreach ($this->catalog->headerTree($columns) as $aspect) {
            foreach ($aspect['subaspects'] as $subaspect) {
                $start = Coordinate::stringFromColumnIndex($cursor);
                $end = Coordinate::stringFromColumnIndex($cursor + $subaspect['span'] - 1);
                if ($start !== $end) {
                    $sheet->mergeCells("{$start}".($row + 1).":{$end}".($row + 1));
                }
                $sheet->setCellValue("{$start}".($row + 1), $subaspect['label']);
                $cursor += $subaspect['span'];
            }
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
