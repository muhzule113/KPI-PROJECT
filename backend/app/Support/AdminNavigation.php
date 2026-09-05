<?php

namespace App\Support;

use App\Models\User;

final class AdminNavigation
{
    public static function for(User $user): array
    {
        $groups = [[
            'label' => 'Menu utama',
            'items' => [[
                'label' => 'Dashboard',
                'href' => '/app',
                'icon' => 'dashboard',
            ]],
        ]];

        $performance = [];
        $positionCode = $user->employee?->position?->code;
        if ($user->employee && !in_array($positionCode, ['POS-OWN', 'POS-EXEC'], true)) {
            $performance[] = ['label' => 'KPI Harian Saya', 'href' => '/app/my-kpi/daily', 'icon' => 'calendar'];
        }
        if ($user->hasRole('owner_manager') && !$user->hasRole('super_admin')) {
            $performance[] = ['label' => 'Penilaian KPI', 'href' => '/app/employee-kpis', 'icon' => 'kpi'];
            $performance[] = ['label' => 'Penilaian Manager Harian', 'href' => '/app/manager-daily-assessments', 'icon' => 'assessment'];
            $performance[] = ['label' => 'Periode Penilaian', 'href' => '/app/kpi-periods', 'icon' => 'target'];
            $performance[] = ['label' => 'Koreksi KPI', 'href' => '/app/kpi-correction-requests', 'icon' => 'approval'];
        } elseif ($user->hasRole('super_admin')) {
            $performance[] = ['label' => 'Monitoring KPI', 'href' => '/app/employee-kpis', 'icon' => 'kpi'];
            $performance[] = ['label' => 'Periode Penilaian', 'href' => '/app/kpi-periods', 'icon' => 'target'];
        }
        if ($user->hasRole('supervisor') && !$user->hasRole('super_admin')) {
            $performance[] = ['label' => 'Review KPI Tim', 'href' => '/app/supervisor-reviews', 'icon' => 'assessment'];
            $performance[] = ['label' => 'Review KPI Harian', 'href' => '/app/supervisor-daily-assessments', 'icon' => 'calendar'];
        }
        if ($performance) {
            $groups[] = ['label' => 'Kinerja', 'items' => $performance];
        }

        if (MenuAccess::can($user, ['super_admin'], [])) {
            $groups[] = [
                'label' => 'Master KPI',
                'items' => [
                    ['label' => 'Katalog Indikator', 'href' => '/app/kpi-definitions', 'icon' => 'list'],
                    ['label' => 'Template Jabatan', 'href' => '/app/kpi-templates', 'icon' => 'template'],
                ],
            ];
            $groups[] = [
                'label' => 'Organisasi',
                'items' => [
                    ['label' => 'Pengguna', 'href' => '/app/users', 'icon' => 'users'],
                    ['label' => 'Karyawan', 'href' => '/app/employees', 'icon' => 'users'],
                    ['label' => 'Jabatan', 'href' => '/app/positions', 'icon' => 'briefcase'],
                    ['label' => 'Cabang Toko', 'href' => '/app/branches', 'icon' => 'store'],
                ],
            ];
        }

        $operations = [];
        if (MenuAccess::can($user, ['owner_manager'], []) || MenuAccess::can($user, [], ['POS-ADM'])) {
            $operations[] = ['label' => 'Absensi', 'href' => '/app/attendances', 'icon' => 'calendar'];
        }
        if (MenuAccess::can($user, [], ['POS-GUD'])) {
            $operations[] = ['label' => 'Stock Opname', 'href' => '/app/stock-opnames', 'icon' => 'clipboard'];
            $operations[] = ['label' => 'Produk & Stok', 'href' => '/app/spareparts', 'icon' => 'package'];
        }
        if (MenuAccess::can($user, [], ['POS-ADM'])) {
            $operations[] = ['label' => 'Work-Log Admin', 'href' => '/app/admin-work-logs', 'icon' => 'clipboard'];
        }
        if (MenuAccess::can($user, ['owner_manager', 'supervisor'], [])) {
            $operations[] = ['label' => 'Komplain & Retur', 'href' => '/app/complaints', 'icon' => 'complaint'];
        }
        if (MenuAccess::can($user, ['supervisor'], [])) {
            $operations[] = ['label' => 'Coaching', 'href' => '/app/coaching-logs', 'icon' => 'coaching'];
        }
        if (MenuAccess::can($user, ['owner_manager', 'super_admin'], []) || MenuAccess::can($user, [], ['POS-TEK', 'POS-CS', 'POS-KSR', 'POS-GUD'])) {
            $operations[] = ['label' => 'Tiket Servis', 'href' => '/app/service-tickets', 'icon' => 'wrench'];
        }
        if (MenuAccess::can($user, [], ['POS-CS'])) {
            $operations[] = ['label' => 'Feedback Pelanggan', 'href' => '/app/customer-feedback', 'icon' => 'feedback'];
        }
        if ($operations) {
            $groups[] = ['label' => 'Operasional', 'items' => $operations];
        }

        if (MenuAccess::can($user, [], ['POS-KSR'])) {
            $groups[] = ['label' => 'Import', 'items' => [
                ['label' => 'Laporan Kasir', 'href' => '/app/import-batches', 'icon' => 'upload'],
            ]];
        }

        if (MenuAccess::can($user, ['super_admin'], [])) {
            $groups[] = ['label' => 'Sistem', 'items' => [
                ['label' => 'Audit Log', 'href' => '/app/audit-events', 'icon' => 'audit'],
            ]];
        }

        return $groups;
    }
}
