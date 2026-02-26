<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Employee extends Model
{
    use Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'employee_id',
        'tc_no',
        'name',
        'email',
        'phone',
        'status',
        'title',
        'profession',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    protected $casts = [
        'status' => ActiveStatusEnum::class,
    ];

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

    protected static $logName = 'employees';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    // relationship with User model
    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }

    public function getSlaPerformanceScore()
    {
        $tasks = $this->tasks()->whereNotNull('due_date')->get();
        if ($tasks->isEmpty()) return 100;

        $onTimeTasks = $tasks->filter(fn($task) => $task->sla_status === 'SUCCESS')->count();

        // Yüzdelik başarı oranı
        return ($onTimeTasks / $tasks->count()) * 100;
    }

    // relationship with Group model as manager
    public function managedGroups()
    {
        return $this->hasMany(Group::class, 'employee_id')
            ->with('manager');
    }

    // relationship with GroupMember model as member
    public function groupMemberships()
    {
        return $this->hasMany(GroupMember::class, 'employee_id')
            ->with('group');
    }
}
