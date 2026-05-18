<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Employee extends Model
{
    use HasFactory, Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'employee_id', 'tc_no', 'name', 'email', 'phone',
        'status', 'title', 'profession',
        'company_name', 'company_id',
        'created_by', 'updated_by', 'deleted_by',
        'performance_score',
        'current_threshold',
    ];

    protected $casts = [
        'status' => ActiveStatusEnum::class,
    ];

    // --- İlişkiler ---

    public function tickets()
    {
        return $this->hasMany(\App\Models\Ticket::class, 'employee_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email')
            ->where('status', ActiveStatusEnum::ACTIVE)
            ->whereNotNull('username')
            ->whereNull('deleted_at');
    }

    // İlişkilerdeki ->with() kısımlarını temizledik.
    // Bunları ihtiyacın olan Resource (EmployeeResource) içinde çağırmak sistemi yormaz.
    public function managedGroups()
    {
        return $this->hasMany(Group::class, 'employee_id');
    }

    public function groupMemberships()
    {
        return $this->hasMany(GroupMember::class, 'employee_id');
    }

    // --- Performans ve Kayıt İzleme ---

    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }
    public function deletedBy() { return $this->belongsTo(User::class, 'deleted_by'); }

    protected static $logName = 'employees';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    // --- PERFORMANS MANTIĞI ---

    /**
     * Triggered whenever a ticket closes. Recomputes performance_score and
     * current_threshold against the sla_breached signal — the canonical
     * Reform-era SLA outcome consumed by PerformanceService and the
     * dashboards.
     *
     * "Sealed" cohort = closed-on-time + breached. A ticket counts as
     * on-time when sla_breached=false AND closed_at is set; breached when
     * sla_breached=true (whether closed or still active). Cancelled
     * tickets sit outside the cohort (markCancelled clears sla_breached
     * and they have no closed_at OR closed_at without an SLA cohort).
     */
    public function refreshPerformanceMetrics()
    {
        $stats = $this->tickets()
            ->selectRaw('
                SUM(CASE WHEN sla_breached = 0 AND resolved_at IS NOT NULL THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN sla_breached = 1 THEN 1 ELSE 0 END) as failed_count
            ')
            ->first();

        $success = (int) ($stats->success_count ?? 0);
        $failed  = (int) ($stats->failed_count ?? 0);
        $total   = $success + $failed;

        if ($total === 0) {
            $this->update([
                'performance_score' => null,
                'current_threshold' => null,
            ]);
            return;
        }

        $actualRate = ($success / $total) * 100;

        // Threshold averaged across the units this employee has actually
        // closed/breached tickets for (same cohort definition).
        $unitIds = $this->tickets()
            ->where(function ($q) {
                $q->where('sla_breached', true)
                  ->orWhere(function ($q) {
                      $q->where('sla_breached', false)->whereNotNull('resolved_at');
                  });
            })
            ->pluck('unit_id')
            ->unique();

        $averageThreshold = SlaPolicy::whereIn('unit_id', $unitIds)->avg('success_threshold');

        $this->update([
            'performance_score' => $actualRate,
            'current_threshold' => $averageThreshold,
        ]);
    }


    public function accessibleAreaIds()
    {
        return Area::query()
            ->whereIn('id', function ($query) {
                $query->select('area_id')
                    ->from('groups')
                    ->join('group_members', 'groups.id', '=', 'group_members.group_id')
                    ->where('group_members.employee_id', $this->id)
                    ->whereNull('group_members.deleted_at');
            })
            ->whereExists(function ($query) {
                $query->selectRaw(1)
                    ->from('sla_policies')
                    ->whereColumn('sla_policies.area_id', 'areas.id');
            })
            ->pluck('id');
    }

    public function getUnitPerformanceStats()
    {
        return $this->tickets()
            ->select('unit_id')
            ->selectRaw('
                SUM(CASE WHEN sla_breached = 0 AND resolved_at IS NOT NULL THEN 1 ELSE 0 END) +
                SUM(CASE WHEN sla_breached = 1 THEN 1 ELSE 0 END) as total
            ')
            ->selectRaw('SUM(CASE WHEN sla_breached = 0 AND resolved_at IS NOT NULL THEN 1 ELSE 0 END) as success_count')
            ->groupBy('unit_id')
            ->with('unit')
            ->get();
    }

    public function slaPolicies()
    {
        return $this->belongsToMany(SlaPolicy::class, 'employee_sla_policies')
            ->using(EmployeeSlaPolicy::class)
            ->withPivot(['id', 'created_by', 'updated_by', 'deleted_by']) // id'yi de ekledik ki loglar karışmasın
            //->withPivot(['id', 'status', 'created_by', 'updated_by', 'deleted_by']) // id'yi de ekledik ki loglar karışmasın
            ->withTimestamps();
    }
}