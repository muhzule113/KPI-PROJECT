<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'file_name',
        'file_path',
        'file_hash_sha256',
        'mapping_version_id',
        'period_id',
        'uploader_id',
        'status',
        'total_rows',
        'valid_rows',
        'warning_rows',
        'error_rows',
        'duplicate_rows',
        'summary_json',
        'issues_json',
        'confirmed_at',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'warning_rows' => 'integer',
        'error_rows' => 'integer',
        'duplicate_rows' => 'integer',
        'summary_json' => 'array',
        'issues_json' => 'array',
        'confirmed_at' => 'datetime',
    ];

    public function period()
    {
        return $this->belongsTo(KpiPeriod::class, 'period_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function mappingVersion()
    {
        return $this->belongsTo(ImportMappingVersion::class, 'mapping_version_id');
    }

    public function transactions()
    {
        return $this->hasMany(CashierTransaction::class, 'import_batch_id');
    }
}
