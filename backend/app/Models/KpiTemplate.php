<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'position_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function versions()
    {
        return $this->hasMany(KpiTemplateVersion::class)->orderByDesc('version_number');
    }

    public function activeVersion()
    {
        return $this->hasOne(KpiTemplateVersion::class)->where('status', 'active');
    }
}
