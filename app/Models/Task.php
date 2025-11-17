<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\MediaLibrary\HasMedia;

class Task extends Model Implements HasMedia
{
    use Notifiable, SoftDeletes, \Spatie\MediaLibrary\InteractsWithMedia;
    protected $fillable = [
        'title',
        'description',
        'user_id',
        'area_id',
        'sub_area_id',
        'unit_id',
        'type_id',
        'status',
        'employee_id',
        'task_date',
        'due_date',
        'resolution_notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'task_date' => 'date',
        'due_date' => 'datetime',
        'type_id' => \App\Enums\TaskTypeEnum::class,
        'status' => \App\Enums\TaskStatusEnum::class,
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id');
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('task_attachments')
            ->singleFile();
    }

    public static function query()
    {
        $hasPermission = auth()->user()->hasRole('suepr_admin') || auth()->user()->can('view_all_tasks');

        if ($hasPermission) {
            return parent::query();
        } else {
            return parent::query()->where('user_id', auth()->user()->id);
        }
    }
}
