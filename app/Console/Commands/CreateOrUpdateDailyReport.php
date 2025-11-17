<?php

namespace App\Console\Commands;

use App\Services\DailyReportService;
use Illuminate\Console\Command;

class CreateOrUpdateDailyReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cu-daily-report';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Günlük raporları oluşturur veya günceller';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $reportService = new DailyReportService();

        try {
            $this->info('Günlük rapor işleme başladı...');
            $totalProcessed = $reportService->dailyReportsService();
            $this->info("Bugün için toplam {$totalProcessed} kayıt işlendi.");
        } catch (\Exception $e) {
            $this->error("Hata oluştu: " . $e->getMessage());
        }
    }
}
