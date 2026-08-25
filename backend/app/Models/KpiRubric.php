<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiRubric extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_item_id',
        'name',
        'description',
    ];

    public function templateItem()
    {
        return $this->belongsTo(KpiTemplateItem::class, 'template_item_id');
    }

    public function criteria()
    {
        return $this->hasMany(KpiRubricCriterion::class, 'rubric_id')->orderBy('sort_order');
    }
}
