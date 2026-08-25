<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'employee_number',
        'name',
        'email',
        'phone',
        'position_id',
        'branch_id',
        'supervisor_id',
        'joined_at',
        'ended_at',
        'status',
    ];

    protected $casts = [
        'joined_at' => 'date',
        'ended_at' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
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

    public function subordinates()
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    public function placements()
    {
        return $this->hasMany(EmployeePlacement::class);
    }

    public function kpis()
    {
        return $this->hasMany(EmployeeKpi::class);
    }
}
