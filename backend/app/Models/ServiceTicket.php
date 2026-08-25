<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'customer_name',
        'customer_phone',
        'customer_address',
        'device_brand',
        'device_model',
        'imei_or_serial',
        'passcode_or_pattern',
        'physical_condition',
        'initial_complaint',
        'estimated_cost',
        'final_cost',
        'estimated_completion_at',
        'branch_id',
        'period_id',
        'intake_by_employee_id',
        'technician_employee_id',
        'status',
        'result_status',
        'diagnosis_notes',
        'action_notes',
        'qc_checklist_json',
        'is_warranty_return',
        'warranty_returned_from_ticket_id',
        'started_at',
        'completed_at',
        'delivered_at',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'final_cost' => 'decimal:2',
        'estimated_completion_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'qc_checklist_json' => 'array',
        'is_warranty_return' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(KpiPeriod::class, 'period_id');
    }

    public function intakeEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'intake_by_employee_id');
    }

    public function technicianEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_employee_id');
    }

    public function sparepartRequests(): HasMany
    {
        return $this->hasMany(SparepartRequest::class);
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(CustomerFeedback::class);
    }

    public function originalTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'warranty_returned_from_ticket_id');
    }
}
