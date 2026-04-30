<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Group extends Model
{
    use HasFactory, Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'company_id',
        'area_id',
        'unit_id',
        'employee_id',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'status' => \App\Enums\ActiveStatusEnum::class,
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

    // The company that the group belongs to
    public function company()
    {
        return $this->belongsTo(Company::class)
            ->with('areas');
    }

    // The area that the group belongs to
    public function area()
    {
        return $this->belongsTo(Area::class)
            ->with('subAreas');
    }

    // The unit that the group members belong to
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    // The manager of the group
    public function manager()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // The members of the group
    public function members()
    {
        return $this->hasMany(GroupMember::class);
    }

    protected static $logName = 'group';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    protected static function booted()
    {
        static::creating(function ($group) {
            $group->created_by = auth()->id();
        });

        static::updating(function ($group) {
            $group->updated_by = auth()->id();
        });

        static::deleting(function ($group) {
            $group->deleted_by = auth()->id();
            $group->save();
        });
    }

    // relation with sla policy
    public function slaPolicy()
    {
        return $this->belongsTo(SlaPolicy::class, 'area_id', 'area_id');
    }
}
