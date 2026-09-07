<?php

namespace App\Support;

use App\Models\User;

final class AdminNavigation
{
    public static function for(User $user): array
    {
        if (! in_array('web', CapabilityMatrix::allowedPlatforms($user), true)) {
            return [];
        }

        $isManager = CapabilityMatrix::has($user, 'kpi.manager.approval');
        $hasTeamWorkspace = $isManager || CapabilityMatrix::has($user, 'kpi.supervisor.review');
        $mainItems = [['label' => 'Beranda', 'href' => '/app', 'icon' => 'dashboard']];
        if ($hasTeamWorkspace) {
            $mainItems[] = [
                'label' => 'Penilaian Tim',
                'href' => '/app/team-tasks',
                'icon' => 'assessment',
            ];
        }
        $groups = [['label' => 'Menu utama', 'items' => $mainItems]];
        $sections = [
            'Pekerjaan harian' => [
                ['service-tickets', 'Tiket Servis', 'wrench'],
                ['admin-work-logs', 'Work-Log Admin', 'clipboard'],
                ['attendances', 'Absensi', 'calendar'],
                ['complaints', 'Komplain & Retur', 'complaint'],
                ['coaching-logs', 'Coaching', 'coaching'],
                ['customer-feedback', 'Feedback Pelanggan', 'feedback'],
                ['import-batches', 'Laporan Kasir', 'upload'],
            ],
            'Penilaian dan laporan' => [
                ['my-kpi/daily', 'KPI Saya', 'calendar', 'kpi.self.view'],
                ['supervisor-attendance', 'Absensi Tim', 'calendar', 'attendance.team.manage'],
                ['supervisor-daily-assessments', 'Penilaian Harian Tim', 'assessment', 'kpi.supervisor.daily'],
                ['supervisor-reviews', 'Rekap Tim', 'assessment'],
                ['manager-daily-assessments', 'Tinjauan Opsional', 'assessment', 'kpi.manager.daily'],
                ['employee-kpis', 'Hasil KPI', 'kpi'],
                ['kpi-correction-requests', 'Koreksi KPI', 'approval'],
                ['kpi-periods', 'Periode Penilaian', 'target'],
            ],
            'Administrasi sistem' => [
                ['users', 'Pengguna dan Akses', 'users'],
                ['employees', 'Karyawan', 'users'],
                ['positions', 'Jabatan', 'briefcase'],
                ['branches', 'Cabang Toko', 'store'],
                ['audit-events', 'Riwayat', 'audit'],
            ],
        ];

        if (CapabilityMatrix::has($user, 'kpi.catalog.configure')
            || CapabilityMatrix::has($user, 'kpi.assignments.manage')
            || CapabilityMatrix::has($user, 'imports.configure')) {
            $groups[] = ['label' => 'Administrasi KPI', 'items' => [[
                'label' => 'Pusat Administrasi KPI', 'href' => '/app/kpi-administration', 'icon' => 'settings',
            ]]];
        }

        foreach ($sections as $label => $items) {
            $visible = [];
            foreach ($items as $item) {
                [$resource, $title, $icon] = $item;
                if ($hasTeamWorkspace && in_array($resource, [
                    'supervisor-attendance', 'supervisor-daily-assessments', 'supervisor-reviews',
                ], true)) {
                    continue;
                }
                $allowed = isset($item[3]) ? CapabilityMatrix::has($user, $item[3])
                    : CapabilityMatrix::canAccessResource($user, $resource);
                if ($allowed) {
                    $visible[] = ['label' => $title, 'href' => '/app/'.$resource, 'icon' => $icon];
                }
            }
            if ($visible) {
                $groups[] = ['label' => $label, 'items' => $visible];
            }
        }

        return $groups;
    }
}
