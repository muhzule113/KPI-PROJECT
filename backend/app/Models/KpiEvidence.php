<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiEvidence extends Model
{
    use HasFactory;

    protected $table = 'kpi_evidences';

    protected $fillable = [
        'employee_kpi_item_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'sha256_hash',
        'uploaded_by',
        'description',
    ];

    public function item()
    {
        return $this->belongsTo(EmployeeKpiItem::class, 'employee_kpi_item_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
