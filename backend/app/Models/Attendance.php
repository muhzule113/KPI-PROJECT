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

    /** Status yang tetap dihitung sebagai "hadir" untuk kehadiran & disiplin */
    public const ATTENDED_STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_LATE,
        self::STATUS_PERMISSION,
        self::STATUS_SICK_LEAVE,
    ];

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
