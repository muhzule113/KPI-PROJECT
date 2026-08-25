<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminWorkLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'period_id',
        'work_date',
        'records_input',
        'records_corrected',
        'documents_eligible',
        'documents_complete',
        'reconciliations_total',
        'reconciliations_success',
        'notes',
        'evidence_path',
        'recorded_by',
    ];

    protected $casts = [
        'work_date' => 'date',
        'records_input' => 'integer',
        'records_corrected' => 'integer',
        'documents_eligible' => 'integer',
        'documents_complete' => 'integer',
        'reconciliations_total' => 'integer',
        'reconciliations_success' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(KpiPeriod::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
