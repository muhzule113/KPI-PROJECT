<?php

namespace App\Support;

use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class KpiVisibility
{
    public static function published(KpiPeriod $period): bool
    {
        return $period->published_at !== null;
    }

    public static function applyScope(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin')) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->hasAnyRole(['kpi_admin', 'auditor'])) {
            return $query;
        }
        $employee = $user->employee;
        if (! $employee) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($employee, $user): void {
            $scope->where('employee_kpis.employee_id', $employee->id);
            if ($user->hasAnyRole(['supervisor', 'owner_manager'])) {
                $scope->orWhere(function (Builder $team) use ($employee, $user): void {
                    $team->where('employee_kpis.branch_id_snapshot', $employee->branch_id)
                        ->where(function (Builder $assigned) use ($employee, $user): void {
                            if ($user->hasRole('supervisor')) {
                                $assigned->orWhere('employee_kpis.supervisor_id_snapshot', $employee->id);
                            }
                            if ($user->hasRole('owner_manager')) {
                                $assigned->orWhere('employee_kpis.manager_id_snapshot', $employee->id);
                            }
                        });
                });
            }
        });
    }

    public static function canRead(User $user, EmployeeKpi $kpi): bool
    {
        return self::applyScope(EmployeeKpi::query(), $user)->whereKey($kpi->id)->exists();
    }

    /** Terapkan setelah applyScope: penilai boleh membaca hasil tim sebelum publikasi. */
    public static function visibleScores(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $scores) use ($user): void {
            $scores->whereHas('period', fn (Builder $period) => $period->whereNotNull('published_at'));
            if ($user->hasAnyRole(['kpi_admin', 'auditor', 'supervisor', 'owner_manager'])) {
                if ($user->employee) {
                    $scores->orWhere('employee_kpis.employee_id', '<>', $user->employee->id);
                } else {
                    $scores->orWhereRaw('1 = 1');
                }
            }
        });
    }

    public static function scoreVisible(User $user, EmployeeKpi $kpi): bool
    {
        return self::visibleScores(self::applyScope(EmployeeKpi::query(), $user), $user)
            ->whereKey($kpi->id)->exists();
    }
}
