<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashierTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'period_id',
        'source_application',
        'cashier_employee_id',
        'cashier_name_raw',
        'transaction_number',
        'business_key',
        'transaction_date',
        'transaction_amount',
        'system_cash_amount',
        'actual_cash_amount',
        'cash_difference',
        'duration_seconds',
        'status',
        'is_duplicate',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'transaction_amount' => 'decimal:2',
        'system_cash_amount' => 'decimal:2',
        'actual_cash_amount' => 'decimal:2',
        'cash_difference' => 'decimal:2',
        'duration_seconds' => 'integer',
        'is_duplicate' => 'boolean',
    ];

    public function importBatch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function period()
    {
        return $this->belongsTo(KpiPeriod::class, 'period_id');
    }

    public function cashier()
    {
        return $this->belongsTo(Employee::class, 'cashier_employee_id');
    }
}
