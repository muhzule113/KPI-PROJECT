<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerFeedback extends Model
{
    use HasFactory;

    protected $table = 'customer_feedbacks';

    protected $fillable = [
        'service_ticket_id',
        'cs_employee_id',
        'customer_name',
        'rating',
        'comments',
        'follow_up_ontime',
        'feedback_channel',
    ];

    protected $casts = [
        'rating' => 'integer',
        'follow_up_ontime' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'service_ticket_id');
    }

    public function csEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cs_employee_id');
    }
}
