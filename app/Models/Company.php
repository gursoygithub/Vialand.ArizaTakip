<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Company extends Model
{
    use Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

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

    protected static $logName = 'company';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    protected static function booted()
    {
        static::creating(function ($company) {
            $company->created_by = auth()->id();
        });

        static::updating(function ($company) {
            $company->updated_by = auth()->id();
        });

        static::deleting(function ($company) {
            $company->deleted_by = auth()->id();
        });
    }

    // relation with areas
    public function areas()
    {
        return $this->hasMany(Area::class)
            ->with([
                'subAreas',
                'createdBy:id,name',
                'updatedBy:id,name',
                'deletedBy:id,name'
            ]);
    }
}
