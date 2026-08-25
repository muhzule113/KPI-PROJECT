<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'occurred_at',
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before_json',
        'after_json',
        'reason',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'before_json' => 'array',
        'after_json' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function log(
        string $action,
        string $subjectType,
        string $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?int $actorId = null,
        string $actorType = 'user'
    ): self {
        return self::create([
            'occurred_at' => now(),
            'actor_type' => $actorType,
            'actor_id' => $actorId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'before_json' => $before,
            'after_json' => $after,
            'reason' => $reason,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->header('X-Request-ID') ?? (string) \Illuminate\Support\Str::uuid(),
        ]);
    }
}
