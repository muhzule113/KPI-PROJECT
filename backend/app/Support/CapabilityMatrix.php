<?php

namespace App\Support;

use App\Models\User;

/** Kontrak akses bersama; ownership dan status transaksi tetap diperiksa service. */
final class CapabilityMatrix
{
    public const ADMIN_ROLES = ['super_admin', 'kpi_admin', 'auditor'];

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'super_admin' => 'Admin Sistem', 'kpi_admin' => 'Admin KPI', 'auditor' => 'Auditor',
            'owner_manager' => 'Manager / Owner', 'supervisor' => 'Supervisor', 'employee' => 'Karyawan Operasional',
            default => $role,
        };
    }

    private const ROLE_CAPABILITIES = [
        'super_admin' => ['accounts.manage', 'organization.manage', 'audit.view'],
        'kpi_admin' => ['kpi.configure', 'kpi.period.manage', 'kpi.monitor', 'kpi.sync', 'audit.sync.view', 'imports.configure', 'reports.view', 'reports.export'],
        'auditor' => ['reports.view', 'reports.export', 'kpi.monitor', 'audit.view'],
        'supervisor' => [
            'kpi.supervisor.daily', 'kpi.supervisor.review', 'attendance.team.manage',
            'coaching.manage', 'complaints.validate', 'feedback.view', 'feedback.followup.manage',
            'tickets.feedback-link', 'tickets.supervise', 'tickets.view', 'reports.view', 'reports.export',
        ],
        'owner_manager' => [
            'kpi.manager.daily', 'kpi.manager.approval', 'kpi.correction.manage', 'attendance.manage',
            'complaints.manage', 'feedback.view', 'feedback.followup.manage', 'tickets.feedback-link',
            'tickets.manage', 'tickets.view', 'reports.view', 'reports.export',
        ],
    ];

    private const POSITION_CAPABILITIES = [
        'POS-CS' => ['tickets.view', 'tickets.create', 'tickets.consent', 'tickets.deliver', 'tickets.feedback-link', 'feedback.view', 'feedback.followup.manage', 'complaints.create'],
        'POS-TEK' => ['tickets.view', 'tickets.claim', 'tickets.progress', 'tickets.complete', 'tickets.evidence', 'spareparts.request', 'spareparts.confirm'],
        'POS-ADM' => ['work-logs.manage'],
        'POS-KSR' => ['tickets.view', 'tickets.cost', 'tickets.payment', 'cashier.import'],
        'POS-GUD' => ['tickets.view', 'spareparts.manage', 'spareparts.fulfill', 'stock-opname.manage'],
    ];

    public static function roleConflict(array $roles): bool
    {
        $administrative = array_intersect(self::ADMIN_ROLES, $roles);

        return count($administrative) > 1 || ($administrative && count($roles) > 1)
            || (in_array('supervisor', $roles, true) && in_array('owner_manager', $roles, true));
    }

    public static function accessError(User $user, ?string $platform = null): ?string
    {
        if (! $user->is_active) {
            return 'Akun Anda tidak aktif. Hubungi Admin Sistem.';
        }

        $roles = $user->roles->pluck('name')->all();
        if (self::roleConflict($roles)) {
            return 'Kombinasi peran akun bertentangan. Hubungi Admin Sistem untuk memisahkan tugas akun.';
        }

        if (! $user->hasAnyRole(self::ADMIN_ROLES)) {
            $employee = $user->employee;
            if (! $employee || ! $employee->position || ! $employee->branch) {
                return 'Profil operasional belum lengkap. Admin Sistem perlu menghubungkan akun dengan karyawan, jabatan, dan cabang.';
            }
            if ($employee->status !== 'active' || ! $employee->position->is_active || ! $employee->branch->is_active) {
                return 'Profil karyawan, jabatan, atau cabang Anda tidak aktif. Hubungi Admin Sistem.';
            }
            if (! self::platformsForProfile($user)) {
                return 'Peran dan jabatan akun belum sesuai. Hubungi Admin Sistem.';
            }
        }

        if ($platform && ! in_array($platform, self::platformsForProfile($user), true)) {
            return $platform === 'mobile'
                ? 'Akun ini hanya dapat masuk melalui website.'
                : 'Akun ini hanya dapat masuk melalui aplikasi mobile.';
        }

        return null;
    }

    public static function allowedPlatforms(User $user): array
    {
        return self::accessError($user) === null ? self::platformsForProfile($user) : [];
    }

    private static function platformsForProfile(User $user): array
    {
        if ($user->hasAnyRole(self::ADMIN_ROLES)) {
            return ['web'];
        }

        $position = $user->employee?->position?->code;
        if ($user->hasRole('owner_manager')) {
            return in_array($position, ['POS-OWN', 'POS-EXEC'], true) ? ['mobile', 'web'] : [];
        }
        if ($user->hasRole('supervisor')) {
            return $position === 'POS-SPV' ? ['mobile', 'web'] : [];
        }
        if (! $user->hasRole('employee')) {
            return [];
        }

        return match ($position) {
            'POS-CS', 'POS-TEK', 'POS-GUD' => ['mobile'],
            'POS-KSR', 'POS-ADM' => ['mobile', 'web'],
            default => [],
        };
    }

    public static function for(User $user): array
    {
        if (self::accessError($user) !== null) {
            return [];
        }
        $capabilities = ['dashboard.view', 'notifications.view', 'profile.view'];
        foreach ($user->roles->pluck('name') as $role) {
            $capabilities = [...$capabilities, ...(self::ROLE_CAPABILITIES[$role] ?? [])];
        }
        if (! $user->hasAnyRole(self::ADMIN_ROLES)) {
            $position = $user->employee?->position?->code;
            $capabilities = [...$capabilities, ...(self::POSITION_CAPABILITIES[$position] ?? [])];
            if (! in_array($position, ['POS-OWN', 'POS-EXEC'], true)) {
                $capabilities = [...$capabilities, 'kpi.self.view', 'kpi.correction.request'];
            }
        }

        return array_values(array_unique($capabilities));
    }

    public static function has(User $user, string $capability): bool
    {
        return in_array($capability, self::for($user), true);
    }

    public static function canAccessResource(User $user, string $resource, string $ability = 'view'): bool
    {
        $read = $ability === 'view';
        $capabilities = match ($resource) {
            'users' => ['accounts.manage'],
            'employees', 'branches', 'positions' => ['organization.manage'],
            'kpi-definitions', 'kpi-rating-schemes', 'kpi-rating-bands', 'kpi-templates', 'kpi-template-versions', 'kpi-template-items', 'kpi-rubrics', 'kpi-rubric-criteria', 'kpi-assignments' => ['kpi.configure'],
            'import-mapping-templates', 'import-mapping-versions' => ['imports.configure'],
            'kpi-periods' => $read ? ['kpi.period.manage', 'reports.view'] : ['kpi.period.manage'],
            'employee-kpis' => $read ? ['kpi.monitor', 'reports.view'] : ['kpi.manager.approval'],
            'kpi-correction-requests' => $read ? ['kpi.monitor', 'kpi.correction.manage'] : ['kpi.correction.manage'],
            'supervisor-reviews' => ['kpi.supervisor.review'],
            'audit-events' => $read ? ['audit.view', 'audit.sync.view'] : [],
            'attendances' => ['attendance.manage'],
            'stock-opnames' => ['stock-opname.manage'],
            'admin-work-logs' => ['work-logs.manage'],
            'coaching-logs' => ['coaching.manage'],
            'complaints' => $read ? ['complaints.create', 'complaints.validate', 'complaints.manage']
                : ($ability === 'create' ? ['complaints.create', 'complaints.manage'] : ['complaints.validate', 'complaints.manage']),
            'customer-feedback' => $read ? ['feedback.view'] : [],
            'service-tickets' => $read ? ['tickets.view'] : ['tickets.create', 'tickets.manage'],
            'spareparts' => ['spareparts.manage'],
            'import-batches' => $read ? ['cashier.import', 'imports.configure'] : ['cashier.import'],
            default => [],
        };

        return (bool) array_intersect($capabilities, self::for($user));
    }

    public static function matches(User $user, array $roles, array $positions): bool
    {
        if (self::accessError($user) !== null) {
            return false;
        }

        return $user->hasAnyRole($roles) || (! $user->hasAnyRole(self::ADMIN_ROLES)
            && in_array($user->employee?->position?->code, $positions, true));
    }
}
