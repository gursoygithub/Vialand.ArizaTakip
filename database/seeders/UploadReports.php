<?php

namespace Database\Seeders;

use App\Services\ReportService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UploadReports extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('reports')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $service = new ReportService();

            $this->command->info('Personel giriş-çıkış verileri yükleniyor...');

            $processedCount = $service->reportsStreamService();

            $this->command->info("Toplam {$processedCount} kayıt işlendi.");
        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;'); // Hata durumunda da foreign key kontrollerini geri aç
            $this->command->error('Hata: ' . $e->getMessage());
            Log::error('UploadReports seeder hatası: ' . $e->getMessage());
        }
    }
}
