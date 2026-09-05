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
        'DRAFT' => ['READY', 'OPEN', 'CANCELLED'],
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
        'submitted' => ['under_review'],
        'under_review' => ['revision_required', 'verified', 'pending_approval'],
        'revision_required' => ['submitted'],
        'verified' => ['pending_approval'],
        'pending_approval' => ['approved', 'under_review'],
        'approved' => ['locked'],
        'locked' => [],
    ];

    public static function assertPeriodTransition(KpiPeriod $period, string $next): void
    {
        if (!in_array($next, self::PERIOD_TRANSITIONS[$period->status] ?? [], true)) {
            throw new RuntimeException("Periode berstatus '{$period->status}' tidak dapat diubah menjadi '{$next}'.");
        }
    }

    public static function assertKpiTransition(EmployeeKpi $kpi, string $next): void
    {
        if (!in_array($next, self::KPI_TRANSITIONS[$kpi->status] ?? [], true)) {
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
        // Nilai sistem harus bisa diperbarui setelah karyawan submit, sebelum review dimulai.
        return in_array($kpi->status, ['draft', 'submitted', 'revision_required'], true);
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
        return !$user->hasRole('super_admin')
            && $user->hasRole('owner_manager')
            && $employee?->status === 'active'
            && (string) $kpi->manager_id_snapshot === (string) $employee->id
            && (string) $kpi->employee?->branch_id === (string) $employee->branch_id;
    }

    public static function canReviewKpi(User $user, EmployeeKpi $kpi): bool
    {
        $employee = $user->employee;
        return !$user->hasRole('super_admin')
            && $user->hasRole('supervisor')
            && $employee?->status === 'active'
            && (string) $kpi->supervisor_id_snapshot === (string) $employee->id
            && (string) $kpi->employee?->branch_id === (string) $employee->branch_id;
    }

    public static function canEmployeeWriteKpi(User $user, EmployeeKpi $kpi): bool
    {
        $employee = $user->employee;

        return !$user->hasAnyRole(['super_admin', 'auditor', 'kpi_admin'])
            && $employee?->status === 'active'
            && (string) $kpi->employee_id === (string) $employee->id;
    }

    /**
     * Nilai KPI dapat ditulis oleh pemilik KPI, reviewer yang ditugaskan, atau
     * manager yang berwenang. Hak akses tetap dibatasi oleh service per tahap.
     */
    public static function canWriteKpi(User $user, EmployeeKpi $kpi): bool
    {
        return self::canEmployeeWriteKpi($user, $kpi)
            || self::canReviewKpi($user, $kpi)
            || self::canManageKpi($user, $kpi);
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
        if (!$employee || $employee->status !== 'active'
            || (string) $kpi->employee?->id === (string) $employee->id
            || (string) $kpi->employee?->branch_id !== (string) $employee->branch_id) {
            return false;
        }

        return ($user->hasRole('owner_manager')
                && (string) $kpi->manager_id_snapshot === (string) $employee->id)
            || ($user->hasRole('supervisor')
                && (string) $kpi->supervisor_id_snapshot === (string) $employee->id);
    }

    public static function canApproveCorrection(User $user, KpiCorrectionRequest $request): bool
    {
        if ($user->hasRole('super_admin')) {
            return false;
        }

        $employee = $user->employee;
        $kpi = $request->employeeKpi;

        if (!$employee || !$kpi || (string) $kpi->employee?->branch_id !== (string) $employee->branch_id) {
            return false;
        }

        return ($user->hasRole('owner_manager')
                && (string) $kpi->manager_id_snapshot === (string) $employee->id)
            || ($user->hasRole('supervisor')
                && (string) $kpi->supervisor_id_snapshot === (string) $employee->id);
    }
}
