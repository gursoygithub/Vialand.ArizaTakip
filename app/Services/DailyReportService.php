<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyReportService
{
    protected $sqlsrvConnection;

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        $this->sqlsrvConnection = DB::connection('sqlsrv');
    }

    /**
     * Sadece bugünün verilerini VIEW'dan çekip upsert yapar.
     *
     * @return int İşlenen toplam kayıt sayısı
     */

    public function dailyReportsService(): int
    {
        $batchSize = 1000;
        $buffer = [];
        $totalProcessed = 0;

        $today = now()->toDateString(); // YYYY-MM-DD

        try {
            foreach ($this->sqlsrvConnection
                         ->table('VW_PDKS_LAST_3_MONTHS_REPORT')
                         ->whereDate('date', $today)
                         ->orderBy('external_id')
                         ->cursor() as $data) {

                $buffer[] = [
                    'external_id'    => $data->external_id,
                    'tc_no'          => $data->tc_no,
                    'full_name'      => $data->full_name,
                    'department_name'=> $data->department_name,
                    'position_name'  => $data->position_name,
                    'date'           => $data->date,
                    'first_reading'  => $data->first_reading,
                    'last_reading'   => $data->last_reading,
                    'working_time'   => $data->working_time,
                    'status'         => $data->status,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];

                if (count($buffer) >= $batchSize) {
                    DB::table('reports')->upsert(
                        $buffer,
                        ['external_id'],
                        [
                            'tc_no',
                            'full_name',
                            'department_name',
                            'position_name',
                            'date',
                            'first_reading',
                            'last_reading',
                            'working_time',
                            'status',
                            'updated_at',
                        ]
                    );

                    $totalProcessed += count($buffer);
                    $buffer = [];
                }
            }

            // Kalan buffer varsa ekle
            if (!empty($buffer)) {
                DB::table('reports')->upsert(
                    $buffer,
                    ['external_id'],
                    [
                        'tc_no',
                        'full_name',
                        'department_name',
                        'position_name',
                        'date',
                        'first_reading',
                        'last_reading',
                        'working_time',
                        'status',
                        'updated_at',
                    ]
                );

                $totalProcessed += count($buffer);
            }

            return $totalProcessed;

        } catch (\Exception $e) {
            Log::error('Report senkronizasyon hatası: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
