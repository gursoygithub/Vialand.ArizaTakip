<?php

namespace App\Console\Commands;

use App\Services\ReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StreamReports extends Command
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
    protected $description = 'Personel giriş-çıkış verilerini streaming API üzerinden dinle ve kaydet.';

    /**
     * Execute the console command.
     */
    public function handle(ReportService $reportService)
    {
        $this->info('🔄 Eski veriler temizleniyor...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('reports')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $this->info('✅ reports tablosu temizlendi.');

        try {
            $total = $reportService->reportsStreamService();

            $this->info("Streaming tamamlandı. Toplam {$total} kayıt işlendi.");
        } catch (\Exception $e) {
            $this->error('Streaming hatası: ' . $e->getMessage());
            Log::error('Streaming hatası', ['exception' => $e]);
        }

        return 0;
    }
}
