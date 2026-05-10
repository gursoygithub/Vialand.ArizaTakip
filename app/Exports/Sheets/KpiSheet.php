<?php

namespace App\Exports\Sheets;

use App\Support\DurationFormatter;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class KpiSheet implements FromArray, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    public function __construct(
        private readonly array  $overview,
        private readonly string $context,
    ) {}

    public function title(): string
    {
        return 'KPI Özeti';
    }

    public function headings(): array
    {
        return ['Metrik', 'Değer'];
    }

    public function array(): array
    {
        $o = $this->overview;

        return [
            ['Filtre Kapsamı', $this->context],
            [''],
            ['Toplam Talep', $o['total_assigned']],
            ['Zamanında Çözülen', $o['closed_on_time']],
            ['İhlal Sayısı', $o['total_breached']],
            ['SLA Uyum Oranı (%)', number_format($o['sla_compliance_rate'], 1, ',', '.')],
            ['Risk Altındaki Talepler', $o['at_risk']],
            ['Yeniden Açılma Oranı (%)', number_format($o['reopen_rate'], 1, ',', '.')],
            ['Yeniden Açılma Sayısı', $o['reopen_count']],
            ['Ort. Yanıt Süresi (dk)', $o['avg_response_time_minutes']],
            ['Ort. Çözüm Süresi Brüt (dk)', $o['avg_resolution_minutes']],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // Bold white text on dark header row
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F2937']],
            ],
            // Context row in italic gray
            2 => [
                'font' => ['italic' => true, 'color' => ['argb' => 'FF6B7280']],
            ],
        ];
    }
}
