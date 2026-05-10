<?php

namespace App\Exports\Sheets;

use App\Support\DurationFormatter;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TeamSheet implements FromArray, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    private array $sorted;

    public function __construct(
        Collection              $teamStats,
        private readonly string $context,
    ) {
        $this->sorted = $teamStats->sortByDesc('sla_compliance_rate')->values()->all();
    }

    public function title(): string
    {
        return 'Teknisyen Performansı';
    }

    public function headings(): array
    {
        return [
            'Teknisyen',
            'Toplam',
            'Aktif',
            'Beklemede',
            'Zamanında',
            'İhlal',
            'Uyum (%)',
            'Yanıt Süresi (dk)',
            'Çözüm Brüt (dk)',
            'Çözüm Net (dk)',
        ];
    }

    public function array(): array
    {
        $rows = [
            ['Filtre Kapsamı: ' . $this->context, '', '', '', '', '', '', '', '', ''],
            [''],
        ];

        foreach ($this->sorted as $row) {
            $rows[] = [
                $row['user']->name ?? '?',
                $row['total_assigned'],
                $row['currently_open'],
                $row['currently_on_hold'],
                $row['closed_on_time'],
                $row['closed_breached'],
                number_format($row['sla_compliance_rate'], 1, ',', '.'),
                $row['avg_response_time_minutes'],
                $row['avg_resolution_minutes'],
                $row['avg_resolution_active_minutes'],
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
            $rate     = $row['sla_compliance_rate'];
            $hi       = $row['employee_threshold'] ?? 80.0;
            $lo       = $hi * 0.75;

            $argb = $rate >= $hi ? 'FF047857' : ($rate >= $lo ? 'FFB45309' : 'FFB91C1C');

            $styles["G{$excelRow}"] = [
                'font' => ['bold' => true, 'color' => ['argb' => $argb]],
            ];
        }

        return $styles;
    }
}
