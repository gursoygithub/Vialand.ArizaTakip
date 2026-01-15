<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;

class SubcontractorEmployee extends Model
{
    protected $fillable = [
        'subcontractor_id',
        'name',
        'active',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'active' => ActiveStatusEnum::class,
    ];

    public function subcontractor()
    {
        return $this->belongsTo(Subcontractor::class);
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
        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_subcontractor_employees');

        if ($hasPermission) {
            return parent::query();
        } else {
            return parent::query()->where('created_by', auth()->user()->id);
        }
    }

    protected static function booted()
    {
        static::creating(function ($subcontractorEmployee) {
            $subcontractorEmployee->created_by = auth()->id();
        });

        static::updating(function ($subcontractorEmployee) {
            $subcontractorEmployee->updated_by = auth()->id();
        });

        static::deleting(function ($subcontractorEmployee) {
            $subcontractorEmployee->deleted_by = auth()->id();
            $subcontractorEmployee->deleted_at = now();
            $subcontractorEmployee->save();
        });
    }

    protected static $logName = 'subcontractor_employees';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }
}
