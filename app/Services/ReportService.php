<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReportService
{
    /**
     * STREAMING (gerçek zamanlı akış) yöntemiyle raporları alır ve kaydeder.
     */
    public function reportsStreamService(): int
    {
        $apiUrl = 'http://10.10.50.6:8080/api/zk/cardreadingsall';
        $batchSize = 1000;
        $buffer = [];
        $totalProcessed = 0;

        try {
            Log::info("Streaming başlatılıyor: {$apiUrl}");

            $response = Http::withOptions(['stream' => true])
                ->timeout(0)
                ->get($apiUrl);

            if ($response->failed()) {
                throw new \Exception("API isteği başarısız oldu. HTTP Kod: " . $response->status());
            }

            foreach ($response->toPsrResponse()->getBody() as $chunk) {
                $line = trim($chunk);

                if (empty($line)) {
                    continue;
                }

                if (str_starts_with($line, 'data:')) {
                    $line = trim(substr($line, 5));
                }

                $data = json_decode($line, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning('JSON çözümleme hatası', ['chunk' => $line]);
                    continue;
                }

                $buffer[] = $this->formatData($data);

                if (count($buffer) >= $batchSize) {
                    $this->upsertReports($buffer);
                    $totalProcessed += count($buffer);
                    $buffer = [];
                }
            }

            if (!empty($buffer)) {
                $this->upsertReports($buffer);
                $totalProcessed += count($buffer);
            }

            Log::info("Streaming tamamlandı. Toplam kayıt: {$totalProcessed}");
            return $totalProcessed;

        } catch (\Throwable $e) {
            Log::error('Report streaming hatası: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * GÜNLÜK (tam veri) rapor çekimi.
     */
    public function reportsDailyService(): int
    {
        $apiUrl = 'http://10.10.50.6:8080/api/zk/cardreadingsdaily';
        $batchSize = 1000;
        $buffer = [];
        $totalProcessed = 0;

        try {
            Log::info("Günlük rapor çekiliyor: {$apiUrl}");

            $response = Http::timeout(60)->get($apiUrl);

            if ($response->failed()) {
                Log::error('API hatası', [
                    'url' => $apiUrl,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception("API isteği başarısız oldu. HTTP Kod: " . $response->status());
            }

            $dataList = $response->json();

            foreach ($dataList as $data) {
                $buffer[] = $this->formatData($data);

                if (count($buffer) >= $batchSize) {
                    $this->upsertReports($buffer);
                    $totalProcessed += count($buffer);
                    $buffer = [];
                }
            }

            if (!empty($buffer)) {
                $this->upsertReports($buffer);
                $totalProcessed += count($buffer);
            }

            Log::info("Daily report tamamlandı. Toplam kayıt: {$totalProcessed}");
            return $totalProcessed;

        } catch (\Throwable $e) {
            Log::error('Report daily hatası: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * SON 90 GÜNLÜK rapor çekimi.
     */
    public function reportsLast90DaysService(): int
    {
        $apiUrl = 'http://10.10.50.6:8080/api/zk/cardreadingsninedays';
        $batchSize = 1000;
        $buffer = [];
        $totalProcessed = 0;

        try {
            Log::info("90 günlük rapor çekiliyor: {$apiUrl}");

            $response = Http::timeout(120)->get($apiUrl);

            if ($response->failed()) {
                Log::error('API hatası', [
                    'url' => $apiUrl,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception("API isteği başarısız oldu. HTTP Kod: " . $response->status());
            }

            $dataList = $response->json();

            foreach ($dataList as $data) {
                $buffer[] = $this->formatData($data);

                if (count($buffer) >= $batchSize) {
                    $this->upsertReports($buffer);
                    $totalProcessed += count($buffer);
                    $buffer = [];
                }
            }

            if (!empty($buffer)) {
                $this->upsertReports($buffer);
                $totalProcessed += count($buffer);
            }

            Log::info("90 günlük rapor tamamlandı. Toplam kayıt: {$totalProcessed}");
            return $totalProcessed;

        } catch (\Throwable $e) {
            Log::error('Report 90 günlük hatası: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }


    /**
     * Tek kayıt formatlama metodu — hem stream hem daily için ortak.
     */
    protected function formatData(array $data): array
    {
        return [
            'external_id'     => $data['external_id'] ?? null,
            'tc_no'           => $data['tc_no'] ?? null,
            'full_name'       => $data['full_name'] ?? null,
            'department_name' => $data['department_name'] ?? null,
            'position_name'   => $data['position_name'] ?? null,
            'date'            => $data['date'] ?? null,
            'day'             => $data['day'] ?? null,
            'first_reading'   => self::parseDate($data['first_reading'] ?? null),
            'last_reading'    => self::parseDate($data['last_reading'] ?? null),
            'working_time'    => $data['working_time'] ?? null,
            'status'          => $data['status'] ?? null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ];
    }

    /**
     * Tarihleri MySQL formatına dönüştürür.
     */
    protected static function parseDate(?string $value): ?string
    {
        if (empty($value) || $value === '?') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+$/', $value)) {
                try {
                    return Carbon::createFromFormat('Y-m-d H:i:s.u', $value)->format('Y-m-d H:i:s');
                } catch (\Exception $ex) {
                    Log::warning('Tarih dönüştürme hatası (milisaniyeli)', ['value' => $value]);
                    return null;
                }
            }
            Log::warning('Tarih dönüştürme hatası', ['value' => $value]);
            return null;
        }
    }

    /**
     * Toplu upsert işlemi.
     */
    protected function upsertReports(array $buffer): void
    {
        DB::table('reports')->upsert(
            $buffer,
            ['external_id'],
            [
                'tc_no',
                'full_name',
                'department_name',
                'position_name',
                'date',
                'day',
                'first_reading',
                'last_reading',
                'working_time',
                'status',
                'updated_at',
            ]
        );
    }
}

//class ReportService
//{
//    public function reportsStreamService(): int
//    {
//        $apiUrl = 'http://10.10.50.6:8080/api/zk/cardreadingsall';
//        $batchSize = 1000;
//        $buffer = [];
//        $totalProcessed = 0;
//
//        try {
//            Log::info("Streaming başlatılıyor: {$apiUrl}");
//
//            $response = Http::withOptions(['stream' => true])
//                ->timeout(0) // sonsuz akışa izin ver
//                ->get($apiUrl);
//
//            if ($response->failed()) {
//                throw new \Exception("API isteği başarısız oldu. HTTP Kod: " . $response->status());
//            }
//
//            // Stream'i satır satır oku
//            foreach ($response->toPsrResponse()->getBody() as $chunk) {
//                $line = trim($chunk);
//
//                if (empty($line)) {
//                    continue;
//                }
//
//                // "data:" önekini temizle (streaming API için kritik!)
//                if (str_starts_with($line, 'data:')) {
//                    $line = trim(substr($line, 5));
//                }
//
//                $data = json_decode($line, true);
//
//                if (json_last_error() !== JSON_ERROR_NONE) {
//                    Log::warning('JSON çözümleme hatası', ['chunk' => $line]);
//                    continue;
//                }
//
//                $buffer[] = [
//                    'external_id'     => $data['external_id'] ?? null,
//                    'tc_no'           => $data['tc_no'] ?? null,
//                    'full_name'       => $data['full_name'] ?? null,
//                    'department_name' => $data['department_name'] ?? null,
//                    'position_name'   => $data['position_name'] ?? null,
//                    'date'            => $data['date'] ?? null,
//                    'first_reading'   => self::parseDate($data['first_reading'] ?? null),
//                    'last_reading'    => self::parseDate($data['last_reading'] ?? null),
//                    'working_time'    => $data['working_time'] ?? null,
//                    'status'          => $data['status'] ?? null,
//                    'created_at'      => now(),
//                    'updated_at'      => now(),
//                ];
//
//                if (count($buffer) >= $batchSize) {
//                    $this->upsertReports($buffer);
//                    $totalProcessed += count($buffer);
//                    $buffer = [];
//                }
//            }
//
//            // Son kalanlar
//            if (!empty($buffer)) {
//                $this->upsertReports($buffer);
//                $totalProcessed += count($buffer);
//            }
//
//            Log::info("Streaming tamamlandı. Toplam: {$totalProcessed}");
//            return $totalProcessed;
//
//        } catch (\Throwable $e) {
//            Log::error('Report streaming hatası: ' . $e->getMessage(), [
//                'exception' => $e
//            ]);
//            throw $e;
//        }
//    }
//
//    protected function upsertReports(array $buffer): void
//    {
//        DB::table('reports')->upsert(
//            $buffer,
//            ['external_id'],
//            [
//                'tc_no',
//                'full_name',
//                'department_name',
//                'position_name',
//                'date',
//                'first_reading',
//                'last_reading',
//                'working_time',
//                'status',
//                'updated_at',
//            ]
//        );
//    }
//
//    protected static function parseDate(?string $value): ?string
//    {
//        if (empty($value) || $value === '?') {
//            return null;
//        }
//
//        try {
//            return Carbon::parse($value)->format('Y-m-d H:i:s');
//        } catch (\Exception $e) {
//            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+$/', $value)) {
//                try {
//                    return Carbon::createFromFormat('Y-m-d H:i:s.u', $value)->format('Y-m-d H:i:s');
//                } catch (\Exception $ex) {
//                    Log::warning('Tarih dönüştürme hatası (milisaniyeli)', ['value' => $value]);
//                    return null;
//                }
//            }
//
//            Log::warning('Tarih dönüştürme hatası', ['value' => $value]);
//            return null;
//        }
//    }
//}

//class ReportService
//{
//    public function reportsService(): int
//    {
//        $batchSize = 1000;
//        $totalProcessed = 0;
//        $buffer = [];
//
//        $apiUrl = 'http://10.10.50.6:8080/api/zk/cardreadingsall'; // daily data API endpoint
//
//        try {
//            $response = Http::timeout(60)->get($apiUrl);
//
//            if ($response->failed()) {
//                Log::error('API hatası', [
//                    'url' => $apiUrl,
//                    'status' => $response->status(),
//                    'body' => $response->body(),
//                ]);
//
//                throw new \Exception("API isteği başarısız oldu. HTTP Kod: " . $response->status());
//            }
//
//            $dataList = $response->json();
//
//            foreach ($dataList as $data) {
//                // Tarih biçimlerini dönüştür
//                $firstReading = self::parseDate($data['first_reading'] ?? null);
//                $lastReading  = self::parseDate($data['last_reading'] ?? null);
//
//                $buffer[] = [
//                    'external_id'     => $data['external_id'] ?? null,
//                    'tc_no'           => $data['tc_no'] ?? null,
//                    'full_name'       => $data['full_name'] ?? null,
//                    'department_name' => $data['department_name'] ?? null,
//                    'position_name'   => $data['position_name'] ?? null,
//                    'date'            => $data['date'] ?? null,
//                    'first_reading'   => $firstReading,
//                    'last_reading'    => $lastReading,
//                    'working_time'    => $data['working_time'] ?? null,
//                    'status'          => $data['status'] ?? null,
//                    'created_at'      => now(),
//                    'updated_at'      => now(),
//                ];
//
//                if (count($buffer) >= $batchSize) {
//                    $this->upsertReports($buffer);
//                    $totalProcessed += count($buffer);
//                    $buffer = [];
//                }
//            }
//
//            if (!empty($buffer)) {
//                $this->upsertReports($buffer);
//                $totalProcessed += count($buffer);
//            }
//
//            return $totalProcessed;
//
//        } catch (\Throwable $e) {
//            Log::error('Report senkronizasyon hatası: ' . $e->getMessage(), [
//                'exception' => $e
//            ]);
//            throw $e;
//        }
//    }
//
//    /**
//     * GMT formatlı tarihi MySQL formatına dönüştürür.
//     */
//    protected static function parseDate(?string $value): ?string
//    {
//        if (empty($value) || $value === '?') {
//            return null;
//        }
//
//        try {
//            return Carbon::parse($value)->format('Y-m-d H:i:s');
//        } catch (\Exception $e) {
//            Log::warning('Tarih dönüştürme hatası', ['value' => $value]);
//            return null;
//        }
//    }
//
//    protected function upsertReports(array $buffer): void
//    {
//        DB::table('reports')->upsert(
//            $buffer,
//            ['external_id'],
//            [
//                'tc_no',
//                'full_name',
//                'department_name',
//                'position_name',
//                'date',
//                'first_reading',
//                'last_reading',
//                'working_time',
//                'status',
//                'updated_at',
//            ]
//        );
//    }
//}

//class ReportService
//{
//    protected $sqlsrvConnection;
//
//    public function __construct()
//    {
//        $this->sqlsrvConnection = DB::connection('sqlsrv');
//    }
//
//    /**
//     * Sadece bugünün verilerini VIEW'dan çekip upsert yapar.
//     *
//     * @return int İşlenen toplam kayıt sayısı
//     */
//    public function reportsService(): int
//    {
//        $batchSize = 1000;
//        $buffer = [];
//        $totalProcessed = 0;
//
//        $today = now()->toDateString(); // YYYY-MM-DD
//
//        try {
//            foreach ($this->sqlsrvConnection
//                         ->table('VW_PDKS_LAST_3_MONTHS_REPORT')
//                         //->whereDate('date', $today)
//                         ->orderBy('external_id')
//                         ->cursor() as $data) {
//
//                $buffer[] = [
//                    'external_id'    => $data->external_id,
//                    'tc_no'          => $data->tc_no,
//                    'full_name'      => $data->full_name,
//                    'department_name'=> $data->department_name,
//                    'position_name'  => $data->position_name,
//                    'date'           => $data->date,
//                    'first_reading'  => $data->first_reading,
//                    'last_reading'   => $data->last_reading,
//                    'working_time'   => $data->working_time,
//                    'status'         => $data->status,
//                    'created_at'     => now(),
//                    'updated_at'     => now(),
//                ];
//
//                if (count($buffer) >= $batchSize) {
//                    DB::table('reports')->upsert(
//                        $buffer,
//                        ['external_id'],
//                        [
//                            'tc_no',
//                            'full_name',
//                            'department_name',
//                            'position_name',
//                            'date',
//                            'first_reading',
//                            'last_reading',
//                            'working_time',
//                            'status',
//                            'updated_at',
//                        ]
//                    );
//
//                    $totalProcessed += count($buffer);
//                    $buffer = [];
//                }
//            }
//
//            // Kalan buffer varsa ekle
//            if (!empty($buffer)) {
//                DB::table('reports')->upsert(
//                    $buffer,
//                    ['external_id'],
//                    [
//                        'tc_no',
//                        'full_name',
//                        'department_name',
//                        'position_name',
//                        'date',
//                        'first_reading',
//                        'last_reading',
//                        'working_time',
//                        'status',
//                        'updated_at',
//                    ]
//                );
//
//                $totalProcessed += count($buffer);
//            }
//
//            return $totalProcessed;
//
//        } catch (\Exception $e) {
//            Log::error('Report senkronizasyon hatası: ' . $e->getMessage(), [
//                'exception' => $e
//            ]);
//            throw $e;
//        }
//    }
//}
