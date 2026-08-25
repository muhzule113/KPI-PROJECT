<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiActualEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_kpi_item_id',
        'input_by',
        'actual_value',
        'actual_json',
        'notes',
    ];

    protected $casts = [
        'actual_value' => 'decimal:2',
        'actual_json' => 'array',
    ];

    public function item()
    {
        return $this->belongsTo(EmployeeKpiItem::class, 'employee_kpi_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'input_by');
    }
}
