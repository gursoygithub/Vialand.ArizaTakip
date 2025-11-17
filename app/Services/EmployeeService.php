<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmployeeService
{
    protected $sqlsrvConnection;
    /**
     * Create a new class instance.
     */
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
                         ->orderBy('ADI')
                         ->cursor() as $data) {

                $buffer[] = [
                    'employee_id'    => $data->UNIQUE_ID,
                    'name'           => trim($data->ADI . ' ' . $data->SOYADI),
                    'tc_no'          => $data->TC_KIMLIK_NO,
                    'email'          => $data->E_POSTA,
                    'phone'          => trim($data->GSM_NO),
                    'status'         => $data->AKTIF_MI,
                    'title'          => $data->UNVANI,
                    'profession'     => $data->MESLEGI,
                    'created_by'     => 1,
                    'created_at'     => now(),                  // 👍 Insert için
                    'updated_at'     => now(),                  // 👍 Update için
                ];

                if (count($buffer) >= $batchSize) {
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

                    $totalProcessed += count($buffer);
                    $buffer = [];
                }
            }

            // Son batch'i gönder
            if (!empty($buffer)) {
                DB::table('employees')->upsert(
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
            Log::error('Personel senkronizasyon hatası: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
