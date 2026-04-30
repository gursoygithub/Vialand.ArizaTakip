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
                         //->where('VERITABANI_ADI', 'VIALAND_EGLENCE')
                         ->whereNotNull('UNIQUE_ID')  // 👈 NULL kontrolü ekleyin
                        ->orWhere('UNIQUE_ID', '3BD03815FBF99B1A9ADE10C67D963F38') // Test kaydı (Almira)
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
                    'company_name'   => trim($data->SGK_ISYERI_ADI ?? ''),
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
        // 1. Collect distinct non-empty company names from the batch.
        //    Dedupe key is upper-cased, so " ab " and "AB" collapse to one
        //    upsert in this batch. The unique normalized_name index handles
        //    cross-batch / cross-run idempotency.
        $companyNames = collect($buffer)
            ->pluck('company_name')
            ->map(fn (?string $n) => trim((string) $n))
            ->filter()
            ->unique(fn (string $n) => strtoupper($n))
            ->values();

        // 2. Idempotent company upsert. Conflict target is normalized_name;
        //    on conflict we only bump updated_at — original name/casing is
        //    preserved, so AB and aB stay distinct (different normalized forms).
        foreach ($companyNames as $name) {
            $normalized = strtoupper(trim($name));

            DB::table('companies')->upsert(
                [[
                    'name'            => $name,
                    'normalized_name' => $normalized,
                    'status'          => 1,
                    'created_by'      => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]],
                ['normalized_name'],
                ['updated_at']
            );
        }

        // 3. Upsert employees with the new company_name / company_id columns.
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
                'company_name',
                'company_id',
                'updated_at',
            ]
        );

        // 4. Resolve company_id via normalized join. Re-running this is safe;
        //    join is on UPPER(TRIM(...)) so casing/whitespace shifts upstream
        //    still resolve to the canonical company row.
        DB::statement("
            UPDATE employees e
            INNER JOIN companies c
                ON c.normalized_name = UPPER(TRIM(e.company_name))
            SET e.company_id = c.id
            WHERE e.company_name IS NOT NULL
              AND e.company_name != ''
              AND e.deleted_at IS NULL
        ");
    }
}