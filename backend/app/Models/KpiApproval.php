<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_kpi_id',
        'approver_id',
        'action',
        'reason',
        'row_version_snapshot',
    ];

    public function employeeKpi()
    {
        return $this->belongsTo(EmployeeKpi::class, 'employee_kpi_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
