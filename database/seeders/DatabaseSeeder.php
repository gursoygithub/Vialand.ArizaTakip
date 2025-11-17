<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CustomPermissionSeeder::class,
            InitSeeder::class,
            UnitSeeder::class,
        ]);

        // Teknisyenleri çek
        try {
            $this->command->info('⏳ Personel veri çekimi başlatılıyor...');
            Artisan::call('employee:sync');
            $this->command->info(Artisan::output());
            $this->command->info('✅ Teknisyen veri çekimi tamamlandı.');
        } catch (\Throwable $e) {
            $this->command->error('❌ Teknisyen verileri çekiminde hata: ' . $e->getMessage());
            Log::error('Teknisyen verileri çekim hatası', ['exception' => $e]);
        }

        // Son 90 günlük raporları çek
//        try {
//            $this->command->info('⏳ Son 90 günlük rapor çekimi başlatılıyor...');
//            Artisan::call('reports:90days');
//            $this->command->info(Artisan::output());
//            $this->command->info('✅ Son 90 günlük rapor çekimi tamamlandı.');
//        } catch (\Throwable $e) {
//            $this->command->error('❌ 90 günlük rapor çekiminde hata: ' . $e->getMessage());
//            Log::error('90 günlük rapor hatası', ['exception' => $e]);
//        }

        // Personel verileri çekimi
//        try {
//            $this->command->info('⏳ Personel verileri çekimi başlatılıyor...');
//            Artisan::call('employee:daily');
//            $this->command->info(Artisan::output());
//            $this->command->info('✅ Personel verileri çekimi tamamlandı.');
//        } catch (\Throwable $e) {
//            $this->command->error('❌ Personel verileri çekiminde hata: ' . $e->getMessage());
//            Log::error('Personel verileri çekim hatası', ['exception' => $e]);
//        }
    }
}
