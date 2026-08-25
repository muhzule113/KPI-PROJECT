<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiReviewItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_review_id',
        'employee_kpi_item_id',
        'decision',
        'supervisor_note',
        'reason',
    ];

    public function review()
    {
        return $this->belongsTo(KpiReview::class, 'kpi_review_id');
    }

    public function item()
    {
        return $this->belongsTo(EmployeeKpiItem::class, 'employee_kpi_item_id');
    }
}
