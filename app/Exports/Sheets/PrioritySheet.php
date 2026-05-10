<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PrioritySheet implements FromArray, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    public function __construct(
        private readonly array  $priorityBreakdown,
        private readonly string $context,
    ) {}

    public function title(): string
    {
        return 'Öncelik Dağılımı';
    }

    public function headings(): array
    {
        return ['Öncelik', 'Toplam', 'Zamanında', 'İhlal', 'Uyum (%)'];
    }

    public function array(): array
    {
        $rows = [
            ['Filtre Kapsamı: ' . $this->context, '', '', '', ''],
            [''],
        ];

        foreach ($this->priorityBreakdown as $row) {
            $rows[] = [
                $row['label'],
                $row['total'],
                $row['closed_on_time'],
                $row['breached'],
                number_format($row['compliance_rate'], 1, ',', '.'),
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => [
                'font' => ['italic' => true, 'color' => ['argb' => 'FF6B7280']],
            ],
            3 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F2937']],
            ],
        ];

        // Conditional color on compliance % column (E) for data rows starting at row 4
        $dataStartRow = 4;
        foreach ($this->priorityBreakdown as $i => $row) {
            $excelRow = $dataStartRow + $i;
            $rate     = $row['compliance_rate'];

            $argb = $rate >= 80 ? 'FF047857' : ($rate >= 50 ? 'FFB45309' : 'FFB91C1C');

            $styles["E{$excelRow}"] = [
                'font' => ['bold' => true, 'color' => ['argb' => $argb]],
            ];
        }

        return $styles;
    }
}
