<?php

namespace App\Console\Commands;

use App\Services\ReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Reports90DaysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:90days';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'API üzerinden son 90 günlük raporları çeker ve veritabanına kaydeder.';

    /**
     * Execute the console command.
     */
    public function handle(ReportService $reportService): int
    {
        $this->info('⏳ Son 90 günlük rapor çekimi başlatılıyor...');

        try {
            $count = $reportService->reportsLast90DaysService();
            $this->info("✅ Son 90 günlük rapor çekimi tamamlandı. Toplam {$count} kayıt işlendi.");
            Log::info("Reports90DaysCommand başarıyla tamamlandı. Toplam kayıt: {$count}");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Hata: ' . $e->getMessage());
            Log::error('Reports90DaysCommand sırasında hata: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
