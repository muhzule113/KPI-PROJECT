<?php

namespace App\Support;

use App\Models\EmployeeKpi;
use App\Models\KpiCorrectionRequest;
use App\Models\KpiPeriod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class KpiWorkflow
{
    public const FINAL_KPI_STATUSES = ['approved', 'locked'];

    private const PERIOD_TRANSITIONS = [
        'DRAFT' => ['READY', 'CANCELLED'],
        'READY' => ['OPEN', 'CANCELLED'],
        'OPEN' => ['SUBMISSION_CLOSED', 'CANCELLED'],
        'SUBMISSION_CLOSED' => ['IN_REVIEW', 'CANCELLED'],
        'IN_REVIEW' => ['WAITING_APPROVAL', 'CANCELLED'],
        'WAITING_APPROVAL' => ['PUBLISHED', 'CANCELLED'],
        'PUBLISHED' => ['LOCKED'],
        'LOCKED' => [],
        'CANCELLED' => [],
    ];

    private const KPI_TRANSITIONS = [
        'draft' => ['submitted', 'revision_required'],
        'submitted' => ['under_review', 'pending_approval'],
        'under_review' => ['revision_required', 'verified', 'pending_approval'],
        'revision_required' => ['submitted', 'under_review', 'pending_approval'],
        'verified' => ['pending_approval'],
        'pending_approval' => ['approved', 'under_review'],
        'approved' => ['locked'],
        'locked' => [],
    ];

    public static function assertPeriodTransition(KpiPeriod $period, string $next): void
    {
        if (! in_array($next, self::PERIOD_TRANSITIONS[$period->status] ?? [], true)) {
            throw new RuntimeException("Periode berstatus '{$period->status}' tidak dapat diubah menjadi '{$next}'.");
        }
    }

    public static function assertKpiTransition(EmployeeKpi $kpi, string $next): void
    {
        if (! in_array($next, self::KPI_TRANSITIONS[$kpi->status] ?? [], true)) {
            throw new RuntimeException("KPI berstatus '{$kpi->status}' tidak dapat diubah menjadi '{$next}'.");
        }
    }

    public static function assertMutableKpi(EmployeeKpi $kpi): void
    {
        if (in_array($kpi->status, self::FINAL_KPI_STATUSES, true)) {
            throw new RuntimeException('KPI yang sudah final hanya dapat diubah melalui alur koreksi resmi.');
        }
    }

    public static function canSystemSyncKpi(EmployeeKpi $kpi): bool
    {
        return ! in_array($kpi->status, self::FINAL_KPI_STATUSES, true)
            && ! in_array($kpi->period?->status, ['PUBLISHED', 'LOCKED', 'CANCELLED'], true);
    }

    public static function assertExpectedVersion(Model $model, mixed $expected): void
    {
        if ($expected !== null && (int) $expected !== (int) ($model->row_version ?? 0)) {
            throw new RuntimeException('Data telah berubah oleh pengguna lain. Muat ulang sebelum menyimpan kembali.');
        }
    }

    public static function canManageKpi(User $user, EmployeeKpi $kpi): bool
    {
        $employee = $user->employee;

        return CapabilityMatrix::has($user, 'kpi.manager.approval')
            && $employee?->status === 'active'
            && (string) $kpi->employee_id !== (string) $employee->id
            && (string) $kpi->manager_id_snapshot === (string) $employee->id
            && (string) $kpi->branch_id_snapshot === (string) $employee->branch_id;
    }

    public static function canApproveKpi(User $user, EmployeeKpi $kpi): bool
    {
        return self::canManageKpi($user, $kpi);
    }

    public static function availableActions(User $user, EmployeeKpi $kpi): array
    {
        $actions = [];
        if (self::canReviewKpi($user, $kpi) && in_array($kpi->status, ['submitted', 'under_review', 'revision_required', 'verified'], true)) {
            $actions[] = 'review';
            $actions[] = 'forward';
        }
        if (self::canManageKpi($user, $kpi)) {
            if ($kpi->status === 'pending_approval') {
                $actions = [...$actions, 'decide', 'return', 'approve'];
            } elseif ($kpi->isSupervisorKpi() && in_array($kpi->status, ['submitted', 'under_review', 'revision_required', 'verified'], true)) {
                $actions[] = 'approve';
            }
        }

        return $actions;
    }

    public static function canReviewKpi(User $user, EmployeeKpi $kpi): bool
    {
        $employee = $user->employee;

        return CapabilityMatrix::has($user, 'kpi.supervisor.review')
            && $employee?->status === 'active'
            && ! $kpi->isSupervisorKpi()
            && (string) $kpi->employee_id !== (string) $employee->id
            && (string) $kpi->supervisor_id_snapshot === (string) $employee->id
            && (string) $kpi->branch_id_snapshot === (string) $employee->branch_id;
    }

    public static function canEmployeeWriteKpi(User $user, EmployeeKpi $kpi): bool
    {
        // Fakta dan submit harian sekarang dimiliki Supervisor; karyawan read-only.
        return false;
    }

    /** Nilai KPI hanya ditulis oleh reviewer/sumber resmi; karyawan tetap read-only. */
    public static function canWriteKpi(User $user, EmployeeKpi $kpi): bool
    {
        return self::canEmployeeWriteKpi($user, $kpi)
            || self::canReviewKpi($user, $kpi)
            || ($kpi->isSupervisorKpi() && self::canManageKpi($user, $kpi));
    }

    public static function assertCanWriteKpi(?User $user, EmployeeKpi $kpi): void
    {
        // null hanya dipakai oleh job sinkronisasi internal sebagai system actor.
        if ($user === null || self::canWriteKpi($user, $kpi)) {
            return;
        }

        throw new AuthorizationException('Anda tidak berwenang mengubah KPI ini.');
    }

    public static function canRequestCorrection(User $user, EmployeeKpi $kpi): bool
    {
        if ($user->hasRole('super_admin')) {
            return false;
        }

        $employee = $user->employee;
        if (! $employee || $employee->status !== 'active' || ! KpiVisibility::canRead($user, $kpi)) {
            return false;
        }

        return (string) $kpi->employee_id === (string) $employee->id
            || ($user->hasRole('supervisor')
                && (string) $kpi->supervisor_id_snapshot === (string) $employee->id)
            || ($user->hasRole('owner_manager')
                && (string) $kpi->manager_id_snapshot === (string) $employee->id);
    }

    public static function canApproveCorrection(User $user, KpiCorrectionRequest $request): bool
    {
        if ($user->hasRole('super_admin')) {
            return false;
        }

        $employee = $user->employee;
        $kpi = $request->employeeKpi;

        if (! $employee || ! $kpi || (string) $request->requested_by === (string) $user->id) {
            return false;
        }

        return self::canManageKpi($user, $kpi);
    }
}
