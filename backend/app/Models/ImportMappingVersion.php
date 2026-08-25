<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportMappingVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'mapping_template_id',
        'version_number',
        'mappings_json',
        'is_active',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'mappings_json' => 'array',
        'is_active' => 'boolean',
    ];

    public function template()
    {
        return $this->belongsTo(ImportMappingTemplate::class, 'mapping_template_id');
    }
}
