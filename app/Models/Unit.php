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
    use Notifiable, SoftDeletes, HasFactory, LogsActivity;

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

    public static function query()
    {
        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_units');

        if ($hasPermission) {
            return parent::query();
        } else {
            return parent::query()->where('created_by', auth()->id());
        }
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'unit_id');
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
}
