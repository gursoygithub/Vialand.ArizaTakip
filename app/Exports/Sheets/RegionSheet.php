<?php

namespace App\Exports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RegionSheet implements FromArray, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    private array $sorted;

    public function __construct(
        Collection             $regionBreakdown,
        private readonly string $context,
    ) {
        $this->sorted = $regionBreakdown->sortByDesc('total')->values()->all();
    }

    public function title(): string
    {
        return 'Bölge Dağılımı';
    }

    public function headings(): array
    {
        return ['Bölge', 'Toplam', 'Çözülen', 'Zamanında', 'İhlal', 'Uyum (%)'];
    }

    public function array(): array
    {
        $rows = [
            ['Filtre Kapsamı: ' . $this->context, '', '', '', '', ''],
            [''],
        ];

        foreach ($this->sorted as $row) {
            $rows[] = [
                $row['area_name'],
                $row['total'],
                $row['closed'],
                $row['on_time'],
                $row['breached'],
                number_format($row['compliance'], 1, ',', '.'),
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

        $dataStartRow = 4;
        foreach ($this->sorted as $i => $row) {
            $excelRow = $dataStartRow + $i;
            $rate     = $row['compliance'];

            $argb = $rate >= 80 ? 'FF047857' : ($rate >= 50 ? 'FFB45309' : 'FFB91C1C');

            $styles["F{$excelRow}"] = [
                'font' => ['bold' => true, 'color' => ['argb' => $argb]],
            ];
        }

        return $styles;
    }
}
