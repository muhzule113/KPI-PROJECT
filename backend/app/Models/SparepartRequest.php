<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SparepartRequest extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            $request->pending_unique_key = $request->status === 'pending'
                ? "ticket:{$request->service_ticket_id}:part:{$request->sparepart_id}"
                : null;
        });
    }

    protected $fillable = [
        'service_ticket_id',
        'sparepart_id',
        'technician_employee_id',
        'warehouse_employee_id',
        'quantity',
        'status',
        'requested_at',
        'fulfilled_at',
        'notes',
        'pending_unique_key',
        'availability_note',
        'sla_deadline_at',
        'confirmed_at',
        'confirmed_by_employee_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'requested_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'sla_deadline_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'service_ticket_id');
    }

    public function sparepart(): BelongsTo
    {
        return $this->belongsTo(Sparepart::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_employee_id');
    }

    public function warehouseEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'warehouse_employee_id');
    }
}
