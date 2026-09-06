<?php

namespace App\Modules\Organization;

use App\Models\Employee;
use App\Models\EmployeePlacement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EmployeePlacementService
{
    public function place(
        Employee $employee,
        int $positionId,
        int $branchId,
        ?string $supervisorId,
        string $effectiveFrom,
        ?string $notes = null,
    ): EmployeePlacement {
        return DB::transaction(function () use ($employee, $positionId, $branchId, $supervisorId, $effectiveFrom, $notes): EmployeePlacement {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $from = Carbon::parse($effectiveFrom)->startOfDay();
            $placements = EmployeePlacement::where('employee_id', $employee->id)->lockForUpdate()->orderBy('effective_from')->get();
            if ($placements->contains(fn (EmployeePlacement $row): bool => $row->effective_from->gte($from))) {
                throw new RuntimeException('Placement baru bertumpang tindih dengan placement yang sudah tercatat.');
            }
            $current = $placements->last(fn (EmployeePlacement $row): bool => $row->effective_from->lte($from) && ($row->effective_until === null || $row->effective_until->gte($from))
            );
            if ($placements->filter(fn (EmployeePlacement $row): bool => $row->effective_from->lte($from) && ($row->effective_until === null || $row->effective_until->gte($from))
            )->count() > 1) {
                throw new RuntimeException('Data placement lama bertumpang tindih dan harus diperbaiki lebih dahulu.');
            }
            if ($current) {
                $current->update(['effective_until' => $from->copy()->subDay()->toDateString()]);
            }
            $placement = EmployeePlacement::create([
                'employee_id' => $employee->id,
                'position_id' => $positionId,
                'branch_id' => $branchId,
                'supervisor_id' => $supervisorId,
                'effective_from' => $from->toDateString(),
                'notes' => $notes,
            ]);
            $employee->update([
                'position_id' => $positionId,
                'branch_id' => $branchId,
                'supervisor_id' => $supervisorId,
            ]);

            return $placement;
        });
    }
}
