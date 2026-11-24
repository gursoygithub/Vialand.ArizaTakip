<?php

namespace App\Services;

use App\Enums\ActiveStatusEnum;
use App\Models\Employee;
use App\Models\User;
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
        $seenIds = [];

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

                $seenIds[] = trim($data->UNIQUE_ID);

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

            $deactivated = $this->deactivateMissing($seenIds);

            // Relink orphan users whose email matches an employee.
            $relinked = 0;
            User::whereNull('employee_id')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->chunkById(500, function ($users) use (&$relinked) {
                    foreach ($users as $user) {
                        $employee = Employee::where('email', $user->email)->first();
                        if ($employee) {
                            $user->update(['employee_id' => $employee->employee_id]);
                            $relinked++;
                        }
                    }
                });

            if ($relinked > 0) {
                Log::info("Orphan user relink completed", ['relinked' => $relinked]);
            }

            Log::info("Personel senkronizasyonu tamamlandı", [
                'total_processed' => $totalProcessed,
                'relinked_users'  => $relinked,
                'deactivated'     => $deactivated,
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

    /**
     * People who left the company no longer come from HR (only active ones
     * are read), so mark everyone active here but missing from this run as
     * inactive: they drop out of the team, CC and assignee pickers.
     *
     * Only after a complete run, and only when HR returned at least half of
     * the people currently active here — an empty or cut-off result must
     * never switch the whole company off.
     *
     * @param  array<int, string>  $seenIds  HR UNIQUE_IDs read in this run
     */
    public function deactivateMissing(array $seenIds): int
    {
        $active = Employee::query()->where('status', ActiveStatusEnum::ACTIVE)->whereNotNull('employee_id');
        $activeCount = (clone $active)->count();
        $seenIds = array_values(array_unique(array_filter($seenIds)));

        if ($activeCount === 0) {
            return 0;
        }

        if (count($seenIds) < $activeCount / 2) {
            Log::warning('Personel senkronizasyonu: pasife alma atlandı, İK çok az kayıt döndürdü.', [
                'hr_records' => count($seenIds),
                'active_here' => $activeCount,
            ]);

            return 0;
        }

        $missing = (clone $active)->whereNotIn('employee_id', $seenIds);
        $names = (clone $missing)->limit(50)->pluck('name');
        $count = $missing->update(['status' => ActiveStatusEnum::INACTIVE, 'updated_at' => now()]);

        if ($count > 0) {
            Log::info('Personel senkronizasyonu: İK listesinde olmayanlar pasife alındı.', [
                'count' => $count,
                'names' => $names->all(),
            ]);
        }

        return $count;
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

        // 3. Upsert employees — split by email presence so blank email
        //    from SQL Server never overwrites a previously-correct value.
        $withEmail = [];
        $withoutEmail = [];

        foreach ($buffer as $row) {
            if (is_null($row['email']) || trim($row['email']) === '') {
                $row['email'] = $row['email'] ?? '';
                $withoutEmail[] = $row;
            } else {
                $withEmail[] = $row;
            }
        }

        $updateColumns = [
            'name',
            'tc_no',
            'phone',
            'status',
            'title',
            'profession',
            'company_name',
            'company_id',
            'updated_at',
        ];

        if (!empty($withEmail)) {
            DB::table('employees')->upsert(
                $withEmail,
                ['employee_id'],
                array_merge($updateColumns, ['email']),
            );
        }

        if (!empty($withoutEmail)) {
            DB::table('employees')->upsert(
                $withoutEmail,
                ['employee_id'],
                $updateColumns,
            );
        }

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