<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Unit extends Model
{
    use Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'description',
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

    public function tasks()
    {
        return $this->hasMany(Task::class, 'unit_id');
    }

    // relation with group
    public function groups()
    {
        return $this->hasMany(Group::class, 'unit_id')
            ->with([
                'company',
                'area',
            ]);
    }

    protected static function booted()
    {
        static::creating(function ($unit) {
            $unit->created_by = auth()->id();
        });

        static::updating(function ($unit) {
            $unit->updated_by = auth()->id();
        });

        static::deleting(function ($unit) {
            $unit->deleted_by = auth()->id();
            $unit->deleted_at = now();
            $unit->save();
        });
    }

    protected static $logName = 'units';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    // relation with sla policies
    public function slaPolicies()
    {
        return $this->hasMany(SlaPolicy::class, 'unit_id');
    }
}
