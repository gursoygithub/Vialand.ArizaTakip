<?php

namespace App\Exports;

use App\Support\DurationFormatter;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PerformanceReportExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array      $sections,
        private readonly array      $overview,
        private readonly Collection $regionBreakdown,
        private readonly Collection $teamStats,
        private readonly string     $dateFrom,
        private readonly string     $dateTo,
        private readonly string     $areaName,
    ) {}

    public function sheets(): array
    {
        $sheets = [];

        $context = "{$this->dateFrom} — {$this->dateTo} | Bölge: {$this->areaName}";

        if (in_array('kpi', $this->sections)) {
            $sheets[] = new Sheets\KpiSheet($this->overview, $context);
        }

        if (in_array('priority', $this->sections) && !empty($this->overview['priority_breakdown'] ?? [])) {
            $sheets[] = new Sheets\PrioritySheet($this->overview['priority_breakdown'], $context);
        }

        if (in_array('region', $this->sections) && $this->regionBreakdown->isNotEmpty()) {
            $sheets[] = new Sheets\RegionSheet($this->regionBreakdown, $context);
        }

        if (in_array('team', $this->sections) && $this->teamStats->isNotEmpty()) {
            $sheets[] = new Sheets\TeamSheet($this->teamStats, $context);
        }

        return $sheets;
    }
}
