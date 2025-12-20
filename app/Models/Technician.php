<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Technician extends Model
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

    // relation with Task model
    public function tasks()
    {
        return $this->hasMany(Task::class, 'technician_id');
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

    protected static $logName = 'technicians';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }
}
