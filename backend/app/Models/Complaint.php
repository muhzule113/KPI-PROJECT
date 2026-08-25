<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complaint extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'code',
        'complaint_date',
        'employee_id',
        'service_ticket_id',
        'channel',
        'category',
        'severity',
        'status',
        'description',
        'sla_deadline',
        'resolved_at',
        'resolution_notes',
        'recorded_by',
    ];

    protected $casts = [
        'complaint_date' => 'date',
        'sla_deadline' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function serviceTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => 'Terbuka',
            self::STATUS_IN_PROGRESS => 'Ditindaklanjuti',
            self::STATUS_RESOLVED => 'Terselesaikan',
            self::STATUS_CLOSED => 'Ditutup',
            default => ucfirst($status),
        };
    }

    public static function channelLabel(string $channel): string
    {
        return match ($channel) {
            'in_store' => 'Di Toko',
            'phone' => 'Telepon',
            'whatsapp' => 'WhatsApp',
            'google_review' => 'Google Review',
            default => ucfirst($channel),
        };
    }
}
