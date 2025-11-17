<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TechnicianService
{
    protected $sqlsrvConnection;
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        $this->sqlsrvConnection = DB::connection('sqlsrv2');
    }

    public function getTechnicians(): int
    {
        $batchSize = 1000;
        $buffer = [];
        $totalProcessed = 0;

        $today = now()->toDateString(); // YYYY-MM-DD

        try {
            foreach ($this->sqlsrvConnection
                         ->table('_TGRY_PERSONEL')
                         //->whereDate('date', $today)
                         ->where('AKTIF_MI', 1)
                         ->where('VERITABANI_ADI' , '=', 'VIALAND_EGLENCE')
                         ->orderBy('ADI')
                         ->cursor() as $data) {

                $buffer[] = [
                    'employee_id'    => $data->UNIQUE_ID,
                    'name'           => trim($data->ADI . ' ' . $data->SOYADI),
                    'tc_no'          => $data->TC_KIMLIK_NO,
                    'email'          => $data->E_POSTA,
                    'phone'          => $data->GSM_NO,
                    'status'         => $data->AKTIF_MI,
                    'title'          => $data->UNVANI,
                    'profession'     => $data->MESLEGI,
                    'created_by'     => 1,
                ];

                if (count($buffer) >= $batchSize) {
                    DB::table('technicians')->upsert(
                        $buffer,
                        ['employee_id'],
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

                    $totalProcessed += count($buffer);
                    $buffer = [];
                }
            }

            // Kalan buffer varsa ekle
            if (!empty($buffer)) {
                DB::table('technicians')->upsert(
                    $buffer,
                    ['employee_id'],
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

                $totalProcessed += count($buffer);
            }

            return $totalProcessed;

        } catch (\Exception $e) {
            Log::error('Teknisyen senkronizasyon hatası: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
