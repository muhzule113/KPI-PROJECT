<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerFeedback extends Model
{
    use HasFactory;

    protected $table = 'customer_feedbacks';

    protected $fillable = [
        'service_ticket_id',
        'cs_employee_id',
        'technician_employee_id',
        'customer_name',
        'rating',
        'technician_rating',
        'comments',
        'follow_up_ontime',
        'feedback_channel',
    ];

    protected $casts = [
        'rating' => 'integer',
        'technician_rating' => 'integer',
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

    public function pelayanEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cs_employee_id');
    }

    public function technicianEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_employee_id');
    }

    public function followUp(): HasOne
    {
        return $this->hasOne(FeedbackFollowUp::class);
    }
}
