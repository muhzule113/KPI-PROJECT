<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportMappingTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'source_application',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function versions()
    {
        return $this->hasMany(ImportMappingVersion::class, 'mapping_template_id')->orderByDesc('version_number');
    }

    public function activeVersion()
    {
        return $this->hasOne(ImportMappingVersion::class, 'mapping_template_id')->where('is_active', true);
    }
}
