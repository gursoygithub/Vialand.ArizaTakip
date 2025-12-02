<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\BooleanStatusEnum;
use App\Enums\ManagerStatusEnum;
use App\Enums\UserTypeEnum;
use App\Mail\SendPasswordToUser;
use App\Notifications\UserCreated;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use App\Enums\ActiveStatusEnum;
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use SoftDeletes;
    use HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'tc_no',
        'name',
        'email',
        'phone',
        'status',
        'is_manager',
        'title',
        'profession',
        'password',
        'project_id',
        'project_name',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => ManagerStatusEnum::class,
            'is_manager' => BooleanStatusEnum::class,
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status->is(ManagerStatusEnum::ACTIVE);
    }

    // created_by relation
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // updated_by relation
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // deleted_by relation
    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    // relation with areas
    public function areas()
    {
        return $this->hasMany(Area::class, 'created_by');
    }

    // relation with sub_areas
    public function subAreas()
    {
        return $this->hasMany(SubArea::class, 'created_by');
    }

    // relation with tasks
    public function tasks()
    {
        return $this->hasMany(Task::class, 'created_by');
    }


    // send mail to user after creation
//    protected static function booted()
//    {
//        static::created(function ($user) {
//            $password = Str::substr($user->phone, -8);
//            //$password =  Str::mask($user->phone, '*', 0, strlen($user->tc_no) - 4);
//            //$password =  Str::mask($user->tc_no, '*', 0, strlen($user->tc_no) - 4);
//            // Send welcome email or notification
//            Mail::to($user->email)->send(new SendPasswordToUser($user, $password));
//        });
//    }
}
