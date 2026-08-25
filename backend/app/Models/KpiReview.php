<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_kpi_id',
        'reviewer_id',
        'status',
        'notes',
    ];

    public function employeeKpi()
    {
        return $this->belongsTo(EmployeeKpi::class, 'employee_kpi_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function reviewItems()
    {
        return $this->hasMany(KpiReviewItem::class, 'kpi_review_id');
    }
}
