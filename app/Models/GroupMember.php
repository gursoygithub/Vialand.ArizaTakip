<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class GroupMember extends Model
{
    use HasFactory, Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'group_id',
        'employee_id',
        'created_by',
        'updated_by',
        'deleted_by',
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

    public function group()
    {
        return $this->belongsTo(Group::class)
            ->with('manager');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)
            ->with('tasks');
    }

    protected static $logName = 'group_members';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    protected static function booted()
    {
        static::creating(function ($groupMember) {
            $groupMember->created_by = auth()->id();
        });

        static::updating(function ($groupMember) {
            $groupMember->updated_by = auth()->id();
        });

        static::deleting(function ($groupMember) {
            $groupMember->deleted_by = auth()->id();
            $groupMember->save();
        });
    }
}
