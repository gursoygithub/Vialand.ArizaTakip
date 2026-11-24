<?php

namespace App\Exports;

use App\Models\Employee;
use App\Support\CompanyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Active personnel whose HR e-mail needs fixing: missing, personal,
 * mistyped, or shared with another record. Handed to HR; once they fix the
 * HR system the employee sync picks it up.
 */
class EmployeeEmailIssuesExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @var array<string, int>
     */
    private array $shared = [];

    public function __construct()
    {
        $this->shared = DB::table('employees')
            ->whereNull('deleted_at')
            ->where('email', '!=', '')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck(DB::raw('COUNT(*)'), 'email')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    public function query(): Builder
    {
        return Employee::query()
            ->with('company')
            ->where('status', 1)
            ->where(fn (Builder $query) => $query
                ->whereNot(fn (Builder $q) => $q->emailStatus(CompanyEmail::CORPORATE))
                ->orWhere(fn (Builder $q) => $q->emailStatus('duplicate')))
            ->orderBy('name');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Sicil (UNIQUE_ID)', 'Ad Soyad', 'Şirket', 'Unvan', 'E-posta', 'Sorun', 'Aynı adresi kullanan kayıt'];
    }

    /**
     * @param  Employee  $employee
     * @return array<int, string|int|null>
     */
    public function map($employee): array
    {
        $status = CompanyEmail::status($employee->email);
        $shared = $this->shared[$employee->email] ?? 0;

        $problem = match ($status) {
            CompanyEmail::MISSING => __('ui.email_status_missing'),
            CompanyEmail::INVALID => __('ui.email_status_invalid'),
            CompanyEmail::PERSONAL => __('ui.email_status_personal'),
            default => null,
        };

        return [
            $employee->employee_id,
            $employee->name,
            $employee->company?->name ?? $employee->company_name,
            $employee->title,
            $employee->email ?: null,
            collect([$problem, $shared > 1 ? __('ui.email_status_duplicate') : null])->filter()->implode(' + '),
            $shared > 1 ? $shared : null,
        ];
    }
}
