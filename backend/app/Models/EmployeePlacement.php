<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeePlacement extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'position_id',
        'branch_id',
        'supervisor_id',
        'effective_from',
        'effective_until',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function scopeEffectiveOn($query, mixed $date)
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn ($scope) => $scope->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date));
    }
}
