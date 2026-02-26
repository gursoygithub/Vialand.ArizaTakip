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

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function subcontractorEmployee()
    {
        return $this->belongsTo(SubcontractorEmployee::class, 'employee_id', 'id')
            ->with('subcontractor');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id')
            ->with([
                'company',
                'subAreas',
            ]);
    }

    public function subArea()
    {
        return $this->belongsTo(SubArea::class, 'sub_area_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('task_attachments')
            ->useDisk('s3')
            ->singleFile();
    }

    public static function query()
    {
        $user = auth()->user();

        $hasPermission =
            $user->hasRole('super_admin') ||
            $user->can('view_all_tasks');

        if ($hasPermission) {
            return parent::query();
        }

        return parent::query()
            ->where(function ($query) use ($user) {
                $query
                    ->where('created_by', $user->id)
                    ->orWhere('employee_id', function ($subQuery) use ($user) {
                        $subQuery->select('id')
                            ->from('employees')
                            ->where('email', $user->email);
                    });
            });
    }

//    public static function query()
//    {
//        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_tasks');
//
//        if ($hasPermission) {
//            return parent::query();
//        } else {
//            return parent::query()
//                ->where('created_by', auth()->user()->id)
//                ->orWhere('employee_id', function ($query) {
//                    $query->select('id')
//                        ->from('employees')
//                        ->where('email', auth()->user()->email);
//                });
//        }
//    }

    protected static function booted()
    {
        static::creating(function ($task) {
            $task->created_by = auth()->id();

            // send email notification to assigned employee
            if ($task->employee_id) {
                $employee = Employee::find($task->employee_id);
                if ($employee) {
                    $employee->notify(new TaskAssigned($task));
                }
            }
        });

        static::updating(function ($task) {
            $task->updated_by = auth()->id();

            // send email notification to assigned employee if changed
            if ($task->isDirty('employee_id')) {
                $employee = Employee::find($task->employee_id);
                if ($employee) {
                    $employee->notify(new TaskAssigned($task));
                }
            }
        });

        static::deleting(function ($task) {
            $task->deleted_by = auth()->id();
            $task->deleted_at = now();
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

    // reopenedBy
    public function reopenedBy()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function getTargetDateAttribute()
    {
        // Bu görev için tanımlanmış SLA politikasını bul
        $policy = SlaPolicy::where('area_id', $this->area_id)
            ->where('priority', $this->priority)
            ->first();

        if (!$policy) return null;

        // Oluşturulma tarihine SLA süresini ekle
        return $this->created_at->addHours($policy->resolution_time_hours);
    }

    public function getSlaStatusAttribute()
    {
        $target = $this->target_date;
        $completedAt = $this->due_date; // Senin senaryonda due_date = completed_at

        if (!$completedAt) {
            return now() > $target ? 'SLA_BREACHED' : 'IN_PROGRESS';
        }

        return $completedAt <= $target ? 'SUCCESS' : 'FAILED';
    }

    // relation with group
    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id')
            ->with([
                'company',
                'area',
                'unit',
                'manager',
                'members',
            ]);
    }
}
