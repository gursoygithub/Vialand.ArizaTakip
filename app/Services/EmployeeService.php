<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeService
{
    protected $sqlsrvConnection;

    public function __construct()
    {
        $this->sqlsrvConnection = DB::connection('sqlsrv2');
    }

    public function getEmployees(): int
    {
        $batchSize = 1000;
        $buffer = [];
        $totalProcessed = 0;

        try {
            foreach ($this->sqlsrvConnection
                         ->table('_TGRY_PERSONEL')
                         ->where('AKTIF_MI', 1)
                         ->where('VERITABANI_ADI', 'VIALAND_EGLENCE')
                         ->whereNotNull('UNIQUE_ID')  // 👈 NULL kontrolü ekleyin
                ->orderBy('ADI')
                         ->cursor() as $data) {

                // Ekstra güvenlik kontrolü
                if (empty(trim($data->UNIQUE_ID ?? ''))) {
                    Log::warning('UNIQUE_ID boş olan kayıt atlandı', [
                        'name' => trim(($data->ADI ?? '') . ' ' . ($data->SOYADI ?? ''))
                    ]);
                    continue;
                }

                $buffer[] = [
                    'employee_id'    => trim($data->UNIQUE_ID),  // 👈 Trim ekleyin
                    'name'           => trim(($data->ADI ?? '') . ' ' . ($data->SOYADI ?? '')),
                    'tc_no'          => $data->TC_KIMLIK_NO,
                    'email'          => $data->E_POSTA,
                    'phone'          => trim($data->GSM_NO ?? ''),  // 👈 Null kontrolü
                    'status'         => $data->AKTIF_MI,
                    'title'          => $data->UNVANI,
                    'profession'     => $data->MESLEGI,
                    'created_by'     => 1,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];

                if (count($buffer) >= $batchSize) {
                    $this->processBatch($buffer);
                    $totalProcessed += count($buffer);
                    $buffer = [];
                }
            }

            // Son batch'i gönder
            if (!empty($buffer)) {
                $this->processBatch($buffer);
                $totalProcessed += count($buffer);
            }

            Log::info("Personel senkronizasyonu tamamlandı", [
                'total_processed' => $totalProcessed
            ]);

            return $totalProcessed;

        } catch (\Exception $e) {
            Log::error('Personel senkronizasyon hatası: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function processBatch(array $buffer): void
    {
        DB::table('employees')->upsert(
            $buffer,
            ['employee_id'],  // unique key
            [
                'name',
                'tc_no',
                'email',
                'phone',
                'status',
                'title',
                'profession',
                'updated_at',
            ]
        );
    }
}