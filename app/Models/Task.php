<?php

namespace App\Models;

use App\Notifications\TaskAssigned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Support\Facades\Cache;

class Task extends Model Implements HasMedia
{
    use Notifiable, SoftDeletes, InteractsWithMedia, LogsActivity;

    protected $fillable = [
        'title',
        'description',
        'user_id',
        'area_id',
        'group_id',
        'sub_area_id',
        'unit_id',
        'unit_description',
        'type_id',
        'priority',
        'status',
        'employee_id',
        'assigned_person_type_id',
        'task_date',
        'completed_by',
        'due_date',
        'resolution_notes',
        'reopen_reason',
        'reopened_by',
        'reopened_at',
        'created_by',
        'updated_by',
        'deleted_by',
        'updated_at',
        'sla_outcome', // PERFORMANS İÇİN EKLENDİ
    ];

    protected $casts = [
        'task_date' => 'date',
        'due_date' => 'datetime',
        'type_id' => \App\Enums\TaskTypeEnum::class,
        'status' => \App\Enums\TaskStatusEnum::class,
        'priority' => \App\Enums\TaskPriorityEnum::class,
        'assigned_person_type_id' => \App\Enums\AssignedPersonTypeEnum::class,
        'reopened_at' => 'datetime',
    ];

    // --- MEVCUT İLİŞKİLER (DOKUNULMADI) ---

    public function employee() { return $this->belongsTo(Employee::class); }

    public function subcontractorEmployee()
    {
        return $this->belongsTo(SubcontractorEmployee::class, 'employee_id', 'id')
            ->with('subcontractor');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id')
            ->with(['company', 'subAreas']);
    }

    public function subArea() { return $this->belongsTo(SubArea::class, 'sub_area_id'); }

    public function unit() { return $this->belongsTo(Unit::class, 'unit_id'); }

    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }

    public function deletedBy() { return $this->belongsTo(User::class, 'deleted_by'); }

    public function completedBy() { return $this->belongsTo(User::class, 'completed_by'); }

    public function reopenedBy() { return $this->belongsTo(User::class, 'reopened_by'); }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id')
            ->with(['company', 'area', 'unit', 'manager', 'members']);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('task_attachments')
            ->useDisk('s3')
            ->singleFile();
    }

    // --- MEVCUT YETKİ SORGUSU (DOKUNULMADI) ---

    public static function query()
    {
        $user = auth()->user();

        $hasPermission = $user?->hasRole('super_admin') || $user?->can('view_all_tasks');

        if ($hasPermission) {
            return parent::query();
        }

        return parent::query()
            ->where(function ($query) use ($user) {
                $query
                    ->where('created_by', $user?->id)
                    ->orWhere('employee_id', function ($subQuery) use ($user) {
                        $subQuery->select('id')
                            ->from('employees')
                            ->where('email', $user?->email);
                    });
            });
    }

    // --- SLA OPTİMİZASYONLARI (NİHAİ GÜNCEL HALİ) ---
    public function getTargetDateAttribute()
    {
        return Cache::remember("task_target_{$this->id}", 3600, function() {
            $policy = SlaPolicy::where('area_id', $this->area_id)
                ->where('sub_area_id', $this->sub_area_id)
                ->where('unit_id', $this->unit_id)
                ->where('priority', $this->priority)
                ->first();

            if (!$policy) {
                $policy = SlaPolicy::where('area_id', $this->area_id)
                    ->where('priority', $this->priority)
                    ->whereNull('sub_area_id')
                    ->first();
            }

            if (!$policy || !$policy->deadline_minutes) return null;
            return $this->created_at->addMinutes($policy->deadline_minutes);
        });
    }

    public function getSlaStatusAttribute()
    {
        if (!empty($this->sla_outcome)) return $this->sla_outcome;

        $target = $this->target_date;
        $completedAt = $this->due_date;

        if (!$completedAt) return now() > $target ? 'SLA_BREACHED' : 'IN_PROGRESS';
        return $completedAt <= $target ? 'SUCCESS' : 'FAILED';
    }

    protected static function booted()
    {
        static::created(function ($task) {
            $task->created_by = auth()->id();
            if ($task->employee_id) {
                $employee = Employee::find($task->employee_id);
                if ($employee) $employee->notify(new TaskAssigned($task));
            }
        });

        static::saving(function ($task) {
            if ($task->due_date) {
                // 1. Spesifik eşleşmeyi ara (Bölge + Lokasyon + Birim + Öncelik)
                $policy = \App\Models\SlaPolicy::where([
                    'area_id'     => $task->area_id,
                    'sub_area_id' => $task->sub_area_id,
                    'unit_id'     => $task->unit_id,
                    'priority'    => $task->priority,
                ])->first();

                // 2. Fallback: Lokasyon bağımsız ara (Bölge + Birim + Öncelik)
                if (!$policy) {
                    $policy = \App\Models\SlaPolicy::where([
                        'area_id'  => $task->area_id,
                        'unit_id'  => $task->unit_id,
                        'priority' => $task->priority,
                    ])->first();
                }

                if ($policy && $policy->deadline_minutes) {
                    // Target Date: Görev oluşturma tarihi üzerine politika süresini ekle
                    $startTime = $task->created_at ?? now();
                    $targetDate = $startTime->copy()->addMinutes($policy->deadline_minutes);

                    // Kapanış tarihi hedef tarihten önceyse SUCCESS, değilse FAILED
                    $task->sla_outcome = $task->due_date <= $targetDate ? 'SUCCESS' : 'FAILED';
                } else {
                    // ÖNEMLİ DEĞİŞİKLİK: Politika yoksa FAILED yapma, NULL bırak.
                    // Bu sayede eski veriler performansı düşürmez.
                    $task->sla_outcome = null;
                }
            }
        });

        static::saved(function ($task) {
            \Illuminate\Support\Facades\Cache::forget('dashboard_stats_overview');

            if ($task->employee_id) {
                \Illuminate\Support\Facades\Cache::forget("emp_perf_{$task->employee_id}");

                // Personel puanını sadece bu görev mühürlendiyse (SUCCESS/FAILED) veya
                // personelin genel durumunu her halükarda tazelemek için çağır
                if ($task->employee) {
                    $task->employee->refreshPerformanceMetrics();
                }
            }
        });

        static::updating(function ($task) {
            $task->updated_by = auth()->id();
            if ($task->isDirty('employee_id')) {
                $employee = Employee::find($task->employee_id);
                if ($employee) $employee->notify(new TaskAssigned($task));
            }
        });

        static::deleting(function ($task) {
            $task->deleted_by = auth()->id();
            $task->save();
        });
    }

    protected static $logName = 'tasks';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    // --- SLA İLE İLGİLİ EK METOTLAR ---
    public function getSlaLimitMinutes(): int
    {
        // Mevcut politikayı bulur, yoksa 0 döner
        $policy = \App\Models\SlaPolicy::where('unit_id', $this->unit_id)
            ->where('area_id', $this->area_id)
            ->where('priority', $this->priority)
            ->first();

        return $policy ? (int) $policy->deadline_minutes : 0;
    }

    public function calculateSlaStatus(): string
    {
        // Eğer görev tamamlanmışsa mühürlenmiş veriyi döner
        if ($this->status === \App\Enums\TaskStatusEnum::COMPLETED) {
            return $this->sla_outcome === 'SUCCESS' ? 'SUCCESS' : 'FAILED';
        }

        // Görev açıksa canlı hesaplama yapar
        $limit = $this->getSlaLimitMinutes();
        if ($limit === 0) return 'NO_POLICY';

        $elapsed = (int) $this->created_at->diffInMinutes(now());

        return $elapsed > $limit ? 'FAILED' : 'SUCCESS';
    }
}