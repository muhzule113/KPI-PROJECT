<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_id', 'employee_id', 'report_type', 'report_date', 'deadline_at', 'submitted_at',
        'is_on_time', 'status', 'source', 'content_snapshot', 'submitted_by',
    ];

    protected $casts = [
        'report_date' => 'date',
        'deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'is_on_time' => 'boolean',
        'content_snapshot' => 'array',
    ];

    public function period()
    {
        return $this->belongsTo(KpiPeriod::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
