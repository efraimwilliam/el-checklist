<?php

namespace App\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Membuat berkas Excel (.xlsx) untuk unduhan: lembar berjudul, judul kolom tebal, panel beku, filter, lebar menyesuaikan isi. */
final class ExcelReport
{
    /**
     * @param  list<array{title: string, headers: list<string>, rows: list<list<mixed>>, formats?: array<string, string>}>  $sheets
     */
    public static function download(string $file, array $sheets): StreamedResponse
    {
        $book = new Spreadsheet();
        foreach ($sheets as $index => $sheet) {
            $target = $index === 0 ? $book->getActiveSheet() : $book->createSheet();
            $target->setTitle($sheet['title']);
            self::writeTable($target, $sheet['headers'], $sheet['rows'], $sheet['formats'] ?? []);
        }
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Lembar checklist: satu baris per checklist (sama untuk Laporan dan Riwayat).
     *
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>, formats: array<string, string>}
     */
    public static function checklistSheet(Collection $checklists, string $title = 'Checklist'): array
    {
        return [
            'title' => $title,
            'headers' => ['Tanggal', 'Departemen', __('Staff'), 'Jabatan', 'Skor (%)', __('Done'), __('Ongoing'), __('Pending'), __('Belum diisi'), __('Status review'), __('Dicek oleh'), __('Lengkap')],
            'rows' => $checklists->map(function ($checklist) {
                $counts = $checklist->counts();

                return [
                    ExcelDate::dateTimeToExcel(Date::instance($checklist->date)),
                    $checklist->department->name,
                    $checklist->employee->name,
                    $checklist->position_name,
                    $checklist->score() ?? '',
                    $counts['done'],
                    $counts['ongoing'],
                    $counts['pending'],
                    $checklist->items->whereNull('status')->count(),
                    $checklist->status->label(),
                    $checklist->checked_by ?? __('Diisi sendiri'),
                    $checklist->isComplete() ? 'Ya' : 'Belum',
                ];
            })->values()->all(),
            'formats' => ['A' => 'dd mmm yyyy'],
        ];
    }

    /**
     * Lembar isian tugas: satu baris per isian (hasil DataExplorer).
     *
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>, formats: array<string, string>}
     */
    public static function itemSheet(iterable $items, string $title = 'Data tugas'): array
    {
        $labels = ['done' => 'Done', 'ongoing' => 'Ongoing', 'pending' => 'Pending'];
        $rows = [];
        foreach ($items as $r) {
            $rows[] = [
                ExcelDate::dateTimeToExcel(Date::parse($r->date)),
                $r->department, $r->employee, $r->position, $r->title,
                $labels[$r->status] ?? __('Belum diisi'),
                $r->note ?? '', $r->params ?? '', (int) $r->photos,
                \App\Enums\ChecklistStatus::from($r->review)->label(),
            ];
        }

        return [
            'title' => $title,
            'headers' => ['Tanggal', 'Departemen', __('Staff'), 'Jabatan', 'Tugas', 'Status', 'Catatan', 'Nilai parameter', 'Foto', __('Status review')],
            'rows' => $rows,
            'formats' => ['A' => 'dd mmm yyyy'],
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     * @param  array<string, string>  $formats  kolom => format angka
     */
    private static function writeTable(Worksheet $sheet, array $headers, array $rows, array $formats): void
    {
        $sheet->fromArray([$headers], null, 'A1');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2');
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '2C241E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FBF6EA']],
        ]);
        foreach ($formats as $column => $format) {
            $sheet->getStyle("{$column}2:{$column}".max(2, count($rows) + 1))->getNumberFormat()->setFormatCode($format);
        }
        foreach (range(1, count($headers)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}".max(1, count($rows) + 1));
    }
}
