<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Subcontractor extends Model
{
    use Notifiable, SoftDeletes, LogsActivity;
    protected $fillable = [
        'name',
        'active',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'active' => ActiveStatusEnum::class,
    ];

    public function employees()
    {
        return $this->hasMany(SubcontractorEmployee::class);
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

    public static function query()
    {
        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_subcontractors');

        if ($hasPermission) {
            return parent::query();
        } else {
            return parent::query()->where('created_by', auth()->user()->id);
        }
    }

    protected static function booted()
    {
        static::creating(function ($subcontractor) {
            $subcontractor->created_by = auth()->id();
        });

        static::updating(function ($subcontractor) {
            $subcontractor->updated_by = auth()->id();
        });

        static::deleting(function ($subcontractor) {
            $subcontractor->deleted_by = auth()->id();
            $subcontractor->deleted_at = now();
            $subcontractor->save();
        });
    }

    protected static $logName = 'subcontractors';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }
}
