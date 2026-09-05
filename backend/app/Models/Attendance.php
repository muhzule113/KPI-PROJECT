<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    public const STATUS_PRESENT = 'present';
    public const STATUS_LATE = 'late';
    public const STATUS_PERMISSION = 'permission';
    public const STATUS_SICK_LEAVE = 'sick_leave';
    public const STATUS_ABSENT = 'absent';

    /** Status yang benar-benar masuk kerja dan menjadi pembilang rasio. */
    public const WORKED_STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_LATE,
    ];

    /** Status beralasan yang dikeluarkan dari pembagi rasio. */
    public const EXCUSED_STATUSES = [
        self::STATUS_PERMISSION,
        self::STATUS_SICK_LEAVE,
    ];

    public const STATUSES = [
        ...self::WORKED_STATUSES,
        ...self::EXCUSED_STATUSES,
        self::STATUS_ABSENT,
    ];

    /** Alias kompatibilitas untuk pemanggil lama. */
    public const ATTENDED_STATUSES = self::WORKED_STATUSES;

    protected $fillable = [
        'employee_id',
        'branch_id',
        'attendance_date',
        'status',
        'check_in_time',
        'check_out_time',
        'note',
        'recorded_by',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_time' => 'datetime:H:i',
        'check_out_time' => 'datetime:H:i',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PRESENT => 'Hadir',
            self::STATUS_LATE => 'Terlambat',
            self::STATUS_PERMISSION => 'Izin',
            self::STATUS_SICK_LEAVE => 'Sakit',
            self::STATUS_ABSENT => 'Alpha',
            default => ucfirst($status),
        };
    }
}
