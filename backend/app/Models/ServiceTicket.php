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

    protected $attributes = ['row_version' => 1];

    public const STATUS_INTAKE = 'intake';

    public const STATUS_DIAGNOSING = 'diagnosing';

    public const STATUS_WAITING_SPAREPART = 'waiting_sparepart';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_QC_READY = 'qc_ready';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    public const ESTIMATED_COST_EDITABLE_STATUSES = [
        self::STATUS_INTAKE,
        self::STATUS_DIAGNOSING,
        self::STATUS_WAITING_SPAREPART,
        self::STATUS_IN_PROGRESS,
        self::STATUS_QC_READY,
    ];

    public const RESULT_PENDING = 'pending';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_UNREPAIRABLE = 'unrepairable';

    public const RESULT_CUSTOMER_DECLINED = 'customer_declined';

    public const REQUIRED_QC_KEYS = [
        'display', 'touch', 'camera', 'mic', 'speaker', 'cellular', 'charging', 'biometric',
    ];

    public const SLA_WORKDAYS = [
        'light' => 1,
        'medium' => 3,
        'heavy' => 7,
    ];

    public const TRANSITIONS = [
        self::STATUS_INTAKE => [self::STATUS_DIAGNOSING, self::STATUS_CANCELLED],
        self::STATUS_DIAGNOSING => [self::STATUS_WAITING_SPAREPART, self::STATUS_IN_PROGRESS, self::STATUS_QC_READY, self::STATUS_COMPLETED],
        self::STATUS_WAITING_SPAREPART => [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED],
        self::STATUS_IN_PROGRESS => [self::STATUS_WAITING_SPAREPART, self::STATUS_QC_READY, self::STATUS_COMPLETED],
        self::STATUS_QC_READY => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELLED => [],
    ];

    public function assertTransition(string $nextStatus): void
    {
        if ($nextStatus !== $this->status && ! in_array($nextStatus, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw new \RuntimeException("Tiket berstatus '{$this->status}' tidak dapat diubah menjadi '{$nextStatus}'.");
        }
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_CANCELLED], true);
    }

    public function hasPendingSparepartRequests(): bool
    {
        return $this->sparepartRequests()->where('status', 'pending')->exists();
    }

    public function hasUnconfirmedSparepartRequests(): bool
    {
        return $this->sparepartRequests()
            ->where('status', 'fulfilled')
            ->whereNull('confirmed_at')
            ->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (ServiceTicket $ticket): void {
            if (! in_array($ticket->status, ['completed', 'delivered'], true)) {
                return;
            }

            $ticket->completed_at ??= now();
            if ($ticket->status === 'delivered') {
                $ticket->delivered_at ??= now();
            }
        });
    }

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
        'customer_needs',
        'estimated_cost',
        'final_cost',
        'estimated_completion_at',
        'branch_id',
        'period_id',
        'intake_by_employee_id',
        'cashier_employee_id',
        'technician_employee_id',
        'status',
        'row_version',
        'result_status',
        'diagnosis_notes',
        'action_notes',
        'qc_checklist_json',
        'is_warranty_return',
        'warranty_returned_from_ticket_id',
        'started_at',
        'completed_at',
        'delivered_at',
        'customer_consent_status',
        'customer_consent_at',
        'customer_consent_by_employee_id',
        'customer_consent_notes',
        'payment_status',
        'paid_amount',
        'payment_recorded_at',
        'payment_recorded_by_employee_id',
        'payment_exception_type',
        'payment_exception_reason',
        'payment_exception_approved_by_user_id',
        'payment_exception_approved_at',
        'delivery_recipient_type',
        'delivery_recipient_name',
        'delivered_by_employee_id',
        'delivery_notes',
        'unrepairable_reason',
        'customer_declined_reason',
        'cancellation_reason',
        'technical_evidence_json',
        'service_category',
        'service_complexity',
        'sla_version',
        'sla_baseline_due_at',
        'sla_due_at',
        'sla_breached_at',
        'sparepart_wait_minutes',
        'sla_snapshot_json',
        'warranty_expires_at',
        'warranty_review_status',
        'warranty_review_reason',
        'warranty_reviewed_by_user_id',
        'warranty_reviewed_at',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'final_cost' => 'decimal:2',
        'estimated_completion_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'qc_checklist_json' => 'array',
        'row_version' => 'integer',
        'is_warranty_return' => 'boolean',
        'passcode_or_pattern' => 'encrypted',
        'customer_consent_at' => 'datetime',
        'paid_amount' => 'decimal:2',
        'payment_recorded_at' => 'datetime',
        'payment_exception_approved_at' => 'datetime',
        'technical_evidence_json' => 'array',
        'sla_baseline_due_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'sla_breached_at' => 'datetime',
        'sparepart_wait_minutes' => 'integer',
        'sla_snapshot_json' => 'array',
        'warranty_expires_at' => 'datetime',
        'warranty_reviewed_at' => 'datetime',
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

    public function pelayanEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'intake_by_employee_id');
    }

    public function cashierEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cashier_employee_id');
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

    public function feedbackFollowUp(): HasOne
    {
        return $this->hasOne(FeedbackFollowUp::class);
    }

    public function originalTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'warranty_returned_from_ticket_id');
    }
}
