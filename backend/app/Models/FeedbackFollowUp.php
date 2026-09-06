<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackFollowUp extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NO_RESPONSE = 'no_response';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_EXCEPTION = 'exception';

    protected $fillable = [
        'customer_feedback_id',
        'service_ticket_id',
        'assigned_employee_id',
        'assigned_by_user_id',
        'status',
        'due_at',
        'first_contacted_at',
        'completed_at',
        'contact_channel',
        'outcome',
        'response_summary',
        'evidence_json',
        'completed_by_user_id',
        'row_version',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'first_contacted_at' => 'datetime',
        'completed_at' => 'datetime',
        'evidence_json' => 'array',
        'row_version' => 'integer',
    ];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(CustomerFeedback::class, 'customer_feedback_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'service_ticket_id');
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }
}
