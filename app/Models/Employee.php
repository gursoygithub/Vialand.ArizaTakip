<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Employee extends Model
{
    use Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'employee_id', 'tc_no', 'name', 'email', 'phone',
        'status', 'title', 'profession',
        'created_by', 'updated_by', 'deleted_by',
        'performance_score',
        'current_threshold',
    ];

    protected $casts = [
        'status' => ActiveStatusEnum::class,
    ];

    // --- İlişkiler ---

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email')
            ->where('status', ActiveStatusEnum::ACTIVE)
            ->whereNotNull('username')
            ->whereNull('deleted_at');
    }

    // İlişkilerdeki ->with() kısımlarını temizledik.
    // Bunları ihtiyacın olan Resource (EmployeeResource) içinde çağırmak sistemi yormaz.
    public function managedGroups()
    {
        return $this->hasMany(Group::class, 'employee_id');
    }

    public function groupMemberships()
    {
        return $this->hasMany(GroupMember::class, 'employee_id');
    }

    // --- Performans ve Kayıt İzleme ---

    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }
    public function deletedBy() { return $this->belongsTo(User::class, 'deleted_by'); }

    protected static $logName = 'employees';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    // --- PERFORMANS MANTIĞI ---

    /**
     * Bu metot görev her kapandığında tetiklenir.
     * Personelin kendi atandığı birimlerin zorluk eşiğine göre puanını hesaplar ve DB'ye yazar.
     */
    public function refreshPerformanceMetrics()
    {
        // Sadece politikası olan ve sonucu SUCCESS veya FAILED olarak mühürlenmiş görevleri al
        // Boş (null) olanlar hesaplamaya dahil edilmez
        $stats = $this->tasks()
            ->whereIn('sla_outcome', ['SUCCESS', 'FAILED'])
            ->selectRaw('COUNT(*) as total, COUNT(CASE WHEN sla_outcome = "SUCCESS" THEN 1 END) as success_count')
            ->first();

        // Eğer personelin hiç mühürlü görevi yoksa puanı 0 yap ve çık
        if (!$stats || $stats->total == 0) {
            $this->update(['performance_score' => 0]);
            return;
        }

        $actualRate = ($stats->success_count / $stats->total) * 100;

        // Eşik değeri hesaplanırken de sadece mühürlü görevlerin birimlerine bak
        $unitIds = $this->tasks()->whereIn('sla_outcome', ['SUCCESS', 'FAILED'])->pluck('unit_id')->unique();

        $averageThreshold = SlaPolicy::whereIn('unit_id', $unitIds)->avg('success_threshold') ?? 61;

        $this->update([
            'performance_score' => $actualRate,
            'current_threshold' => $averageThreshold
        ]);
    }


    /**
     * Dashboard veya Resource üzerinden kolay erişim için accessor.
     * Artık hesap yapmaz, direkt veritabanındaki hazır kolonu döner.
     */
    public function getSlaPerformanceScoreAttribute()
    {
        return round($this->performance_score ?? 0, 1);
    }

    public function accessibleAreaIds()
    {
        return $this->groupMemberships()
            ->whereNull('deleted_at') // Sadece silinmemiş kayıtlar
            ->whereHas('group')       // Sadece geçerli bir grubu olanlar
            ->with('group')           // Eager load ile performansı artır
            ->get()
            ->map(fn($membership) => $membership->group?->area_id) // Gruba git ve area_id al
            ->filter()                // Null olanları (boş area_id) temizle
            ->unique()                // Tekrar edenleri kaldır
            ->values();               // Diziyi yeniden indeksle
    }
}