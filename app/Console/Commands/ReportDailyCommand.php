<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ReportDailyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:daily';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'API üzerinden günlük rapor verilerini toplu olarak alır ve kaydeder.';

    /**
     * Execute the console command.
     */
    public function handle(\App\Services\ReportService $reportService): int
    {
        $this->info('📅 Günlük rapor senkronizasyonu başlatılıyor...');

        try {
            $count = $reportService->reportsDailyService();
            $this->info("✅ Günlük rapor tamamlandı. Toplam {$count} kayıt işlendi.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Hata: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
