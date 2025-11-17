<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportStreamCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:stream';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'API üzerinden streaming modda rapor verilerini alır ve kaydeder.';

    /**
     * Execute the console command.
     */
    public function handle(\App\Services\ReportService $reportService): int
    {
        $this->info('🔄 Eski veriler temizleniyor...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('reports')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $this->info('✅ reports tablosu temizlendi.');

        $this->info('📡 Streaming başlatılıyor...');

        try {
            $count = $reportService->reportsStreamService();
            $this->info("✅ Streaming tamamlandı. Toplam {$count} kayıt işlendi.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Hata: ' . $e->getMessage());
            return Command::FAILURE;
        }

    }
}
