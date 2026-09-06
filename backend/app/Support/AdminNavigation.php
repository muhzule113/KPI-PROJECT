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

        $groups = [['label' => 'Menu utama', 'items' => [['label' => 'Beranda', 'href' => '/app', 'icon' => 'dashboard']]]];
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
                ['manager-daily-assessments', 'Penilaian Supervisor', 'assessment', 'kpi.manager.daily'],
                ['employee-kpis', 'Rekap dan Laporan KPI', 'kpi'],
                ['kpi-correction-requests', 'Koreksi KPI', 'approval'],
                ['kpi-periods', 'Periode Penilaian', 'target'],
            ],
            'Administrasi KPI' => [
                ['kpi-definitions', 'Katalog Indikator', 'list'],
                ['kpi-rating-bands', 'Skala Predikat', 'target'],
                ['kpi-rating-schemes', 'Versi Scheme Rating', 'target'],
                ['kpi-templates', 'Template Jabatan', 'template'],
                ['kpi-template-versions', 'Versi Template', 'template'],
                ['kpi-template-items', 'Target dan Bobot', 'target'],
                ['kpi-rubrics', 'Rubrik Penilaian', 'assessment'],
                ['kpi-rubric-criteria', 'Kriteria Rubrik', 'list'],
                ['kpi-assignments', 'Penugasan Penilai', 'users'],
                ['import-mapping-templates', 'Mapping Import', 'upload'],
                ['import-mapping-versions', 'Versi Mapping', 'upload'],
            ],
            'Administrasi sistem' => [
                ['users', 'Pengguna dan Akses', 'users'],
                ['employees', 'Karyawan', 'users'],
                ['positions', 'Jabatan', 'briefcase'],
                ['branches', 'Cabang Toko', 'store'],
                ['audit-events', 'Audit dan Histori', 'audit'],
            ],
        ];

        foreach ($sections as $label => $items) {
            $visible = [];
            foreach ($items as $item) {
                [$resource, $title, $icon] = $item;
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
