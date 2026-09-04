<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeePlacement;
use App\Models\KpiDefinition;
use App\Models\KpiPeriod;
use App\Models\KpiRatingBand;
use App\Models\KpiRatingScheme;
use App\Models\KpiRubric;
use App\Models\KpiRubricCriterion;
use App\Models\KpiTemplate;
use App\Models\KpiTemplateItem;
use App\Models\KpiTemplateVersion;
use App\Models\Position;
use App\Models\User;
use App\Modules\Period\PeriodService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Roles
        $roles = [
            'super_admin',
            'owner_manager',
            'supervisor',
            'kpi_admin',
            'employee',
            'auditor',
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'sanctum']);
        }

        // 2. Rating Scheme
        $scheme = KpiRatingScheme::firstOrCreate(
            ['name' => 'Skema Rating Standar 5-Tingkat'],
            ['description' => 'Skema evaluasi kinerja bulanan standar perusahaan', 'is_default' => true]
        );

        $bands = [
            ['code' => 'STAR', 'label' => '⭐ Istimewa', 'min_score' => 95.00, 'max_score' => 100.00, 'color' => '#10B981', 'badge_icon' => 'heroicon-o-sparkles', 'sort_order' => 1],
            ['code' => 'VERY_GOOD', 'label' => 'Sangat Baik', 'min_score' => 90.00, 'max_score' => 94.99, 'color' => '#3B82F6', 'badge_icon' => 'heroicon-o-check-badge', 'sort_order' => 2],
            ['code' => 'GOOD', 'label' => 'Baik', 'min_score' => 80.00, 'max_score' => 89.99, 'color' => '#84CC16', 'badge_icon' => 'heroicon-o-hand-thumb-up', 'sort_order' => 3],
            ['code' => 'FAIR', 'label' => 'Cukup', 'min_score' => 70.00, 'max_score' => 79.99, 'color' => '#F59E0B', 'badge_icon' => 'heroicon-o-exclamation-circle', 'sort_order' => 4],
            ['code' => 'POOR', 'label' => 'Perlu Perbaikan', 'min_score' => 0.00, 'max_score' => 69.99, 'color' => '#EF4444', 'badge_icon' => 'heroicon-o-exclamation-triangle', 'sort_order' => 5],
        ];

        foreach ($bands as $band) {
            KpiRatingBand::updateOrCreate(
                ['rating_scheme_id' => $scheme->id, 'code' => $band['code']],
                $band
            );
        }

        // 3. Branches
        $branchPusat = Branch::firstOrCreate(
            ['code' => 'CAB-01'],
            ['name' => 'Cabang Pusat - Jakarta', 'address' => 'Jl. Roxy Mas No. 12, Jakarta', 'phone' => '021-5551234', 'is_active' => true]
        );

        $branchSurabaya = Branch::firstOrCreate(
            ['code' => 'CAB-02'],
            ['name' => 'Cabang Surabaya - WTC', 'address' => 'Mall WTC Lt. 2, Surabaya', 'phone' => '031-7775678', 'is_active' => true]
        );

        // 4. Positions
        $posOwner = Position::firstOrCreate(['code' => 'POS-OWN'], ['name' => 'Owner / Manager', 'department' => 'Manajemen']);
        $posSpv = Position::firstOrCreate(['code' => 'POS-SPV'], ['name' => 'Supervisor', 'department' => 'Operasional']);
        $posTek = Position::firstOrCreate(['code' => 'POS-TEK'], ['name' => 'Teknisi', 'department' => 'Servis']);
        $posCs = Position::updateOrCreate(['code' => 'POS-CS'], ['name' => 'Pelayan', 'department' => 'Front Office']);
        $posAdm = Position::firstOrCreate(['code' => 'POS-ADM'], ['name' => 'Admin', 'department' => 'Administrasi']);
        $posKsr = Position::firstOrCreate(['code' => 'POS-KSR'], ['name' => 'Kasir', 'department' => 'Front Office']);
        $posGud = Position::firstOrCreate(['code' => 'POS-GUD'], ['name' => 'Gudang / Sparepart', 'department' => 'Logistik']);

        // 5. KPI Definitions Master (39 items)
        $definitionsData = [
            // Teknisi
            ['code' => 'TEK-01', 'name' => 'Jumlah Servis Selesai', 'metric_type' => 'count', 'unit' => 'unit', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'TEK-02', 'name' => 'Tingkat Keberhasilan Servis', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'TEK-03', 'name' => 'Tingkat Retur / Komplain Servis', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'lower', 'default_formula' => 'lower_is_better', 'source_type' => 'system'],
            ['code' => 'TEK-04', 'name' => 'Ketepatan Waktu Pengerjaan', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'TEK-05', 'name' => 'Kepatuhan SOP Servis', 'metric_type' => 'rubric', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'rubric', 'source_type' => 'supervisor'],
            ['code' => 'TEK-06', 'name' => 'Kerapian & Kebersihan Meja Kerja', 'metric_type' => 'rubric', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'rubric', 'source_type' => 'supervisor'],
            ['code' => 'TEK-07', 'name' => 'Kelengkapan Laporan Servis', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],

            // Pelayan (kode indikator tetap CS-* untuk kompatibilitas)
            ['code' => 'CS-01', 'name' => 'Kepuasan Pelanggan (CSAT)', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'CS-02', 'name' => 'Kecepatan Melayani Pelanggan', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'CS-03', 'name' => 'Akurasi Input Order / Tiket', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'CS-04', 'name' => 'Follow-up Status Pelanggan', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'CS-05', 'name' => 'Jumlah Komplain Pelanggan', 'metric_type' => 'count', 'unit' => 'komplain', 'direction' => 'lower', 'default_formula' => 'lower_is_better', 'source_type' => 'system'],
            ['code' => 'CS-06', 'name' => 'Kehadiran & Disiplin', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'supervisor'],

            // Admin
            ['code' => 'ADM-01', 'name' => 'Akurasi Input Data Administrasi', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'ADM-02', 'name' => 'Ketepatan Laporan Harian & Bulanan', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'ADM-03', 'name' => 'Kelengkapan Dokumen & Arsip', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'ADM-04', 'name' => 'Rekonsiliasi Data Transaksi', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'ADM-05', 'name' => 'Kehadiran & Disiplin', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'supervisor'],
            ['code' => 'ADM-06', 'name' => 'Kepatuhan SOP Administrasi', 'metric_type' => 'rubric', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'rubric', 'source_type' => 'supervisor'],

            // Kasir
            ['code' => 'KSR-01', 'name' => 'Akurasi Transaksi Kasir', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'KSR-02', 'name' => 'Selisih Kas Harian / Bulanan', 'metric_type' => 'currency', 'unit' => 'Rp', 'direction' => 'zero_tolerance', 'default_formula' => 'zero_tolerance', 'source_type' => 'system'],
            ['code' => 'KSR-03', 'name' => 'Ketepatan Waktu Upload Laporan Kas', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'KSR-04', 'name' => 'Kecepatan Transaksi Layanan', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'KSR-05', 'name' => 'Pelayanan & Keramahan Kasir', 'metric_type' => 'rubric', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'rubric', 'source_type' => 'supervisor'],
            ['code' => 'KSR-06', 'name' => 'Disiplin & Kehadiran', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'supervisor'],

            // Gudang
            ['code' => 'GUD-01', 'name' => 'Akurasi Stok Sparepart', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'GUD-02', 'name' => 'Selisih Stok Opname', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'lower', 'default_formula' => 'lower_is_better', 'source_type' => 'system'],
            ['code' => 'GUD-03', 'name' => 'Kecepatan Penyediaan Sparepart', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'GUD-04', 'name' => 'Kelengkapan Stok Sparepart Kritis', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'GUD-05', 'name' => 'Kepatuhan Jadwal Stock Opname', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'GUD-06', 'name' => 'Kerapian & Kebersihan Gudang', 'metric_type' => 'rubric', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'rubric', 'source_type' => 'supervisor'],
            ['code' => 'GUD-07', 'name' => 'Disiplin & Kehadiran', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'supervisor'],

            // Supervisor
            ['code' => 'SUP-01', 'name' => 'Pencapaian Target Tim Operasional', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'SUP-02', 'name' => 'Kualitas Kerja Tim & Penekanan Retur', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'SUP-03', 'name' => 'Kedisiplinan & Absensi Tim', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'SUP-04', 'name' => 'Penyelesaian Komplain & Eskalasi', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'SUP-05', 'name' => 'Coaching & Evaluasi Karyawan', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
            ['code' => 'SUP-06', 'name' => 'Kepatuhan SOP Tim Operasional', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'supervisor'],
            ['code' => 'SUP-07', 'name' => 'Ketepatan Laporan Evaluasi Bulanan', 'metric_type' => 'percentage', 'unit' => '%', 'direction' => 'higher', 'default_formula' => 'higher_is_better', 'source_type' => 'system'],
        ];

        $kpiDefs = [];
        foreach ($definitionsData as $d) {
            $kpiDefs[$d['code']] = KpiDefinition::firstOrCreate(['code' => $d['code']], $d);
        }

        // 6. Build KPI Templates v1 (100% weights)
        $this->seedTemplates($scheme, $kpiDefs, $posTek, $posCs, $posAdm, $posKsr, $posGud, $posSpv);

        // 7. Seed Demo Users & Employees
        $this->seedUsersAndEmployees($posOwner, $posSpv, $posTek, $posCs, $posAdm, $posKsr, $posGud, $branchPusat, $branchSurabaya);

        // 8. Seed Active KPI Period & Generate Snapshots
        $this->seedActivePeriod($branchPusat, $branchSurabaya);
    }

    protected function seedTemplates($scheme, $defs, $posTek, $posCs, $posAdm, $posKsr, $posGud, $posSpv): void
    {
        // Template Teknisi (Total 100%)
        $tplTek = KpiTemplate::firstOrCreate(['code' => 'TPL-TEK-01'], ['name' => 'Template KPI Teknisi v1', 'position_id' => $posTek->id, 'is_active' => true]);
        $verTek = KpiTemplateVersion::firstOrCreate(
            ['kpi_template_id' => $tplTek->id, 'version_number' => 1],
            ['status' => 'active', 'total_weight' => 100.00, 'rating_scheme_id' => $scheme->id, 'effective_from' => '2026-01-01', 'activated_at' => now()]
        );

        $tekItems = [
            ['code' => 'TEK-01', 'weight' => 25.00, 'target' => 80.00, 'unit' => 'unit', 'formula' => 'higher_is_better', 'evidence' => true, 'source' => 'system'],
            ['code' => 'TEK-02', 'weight' => 25.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'TEK-03', 'weight' => 15.00, 'target' => 3.00, 'unit' => '%', 'formula' => 'lower_is_better', 'target_json' => ['failure_limit' => 6.00], 'evidence' => false, 'source' => 'system'],
            ['code' => 'TEK-04', 'weight' => 15.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'TEK-05', 'weight' => 10.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'rubric', 'evidence' => false, 'source' => 'supervisor', 'rubric_criteria' => [
                'Diagnosis kerusakan sesuai prosedur standar',
                'Penggunaan alat servis & ESD protection sesuai SOP',
                'Pemeriksaan akhir fungsi komponen (QC internal)',
                'Dokumentasi tindakan & sparepart lengkap di tiket',
                'Penerapan standar keselamatan kerja (K3)',
            ]],
            ['code' => 'TEK-06', 'weight' => 5.00, 'target' => 90.00, 'unit' => '%', 'formula' => 'rubric', 'evidence' => false, 'source' => 'supervisor', 'rubric_criteria' => [
                'Meja kerja bersih sebelum & sesudah servis',
                'Penyimpanan alat & sparepart tertata rapi',
                'Pengelolaan sampah elektronik / part bekas sesuai SOP',
                'Perawatan & kalibrasi alat servis berkala',
            ]],
            ['code' => 'TEK-07', 'weight' => 5.00, 'target' => 100.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
        ];

        $this->attachTemplateItems($verTek, $tekItems, $defs);

        // Template Pelayan (kode template tetap TPL-CS-01 untuk kompatibilitas)
        $tplCs = KpiTemplate::firstOrCreate(['code' => 'TPL-CS-01'], ['name' => 'Template KPI Pelayan v1', 'position_id' => $posCs->id, 'is_active' => true]);
        $verCs = KpiTemplateVersion::firstOrCreate(
            ['kpi_template_id' => $tplCs->id, 'version_number' => 1],
            ['status' => 'active', 'total_weight' => 100.00, 'rating_scheme_id' => $scheme->id, 'effective_from' => '2026-01-01', 'activated_at' => now()]
        );
        $csItems = [
            ['code' => 'CS-01', 'weight' => 25.00, 'target' => 90.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => true, 'source' => 'system'],
            ['code' => 'CS-02', 'weight' => 20.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'CS-03', 'weight' => 20.00, 'target' => 98.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'CS-04', 'weight' => 15.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'CS-05', 'weight' => 10.00, 'target' => 3.00, 'unit' => 'komplain', 'formula' => 'lower_is_better', 'target_json' => ['failure_limit' => 8.00], 'evidence' => false, 'source' => 'system'],
            ['code' => 'CS-06', 'weight' => 10.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'supervisor'],
        ];
        $this->attachTemplateItems($verCs, $csItems, $defs);

        // Template Admin (Total 100%)
        $tplAdm = KpiTemplate::firstOrCreate(['code' => 'TPL-ADM-01'], ['name' => 'Template KPI Admin v1', 'position_id' => $posAdm->id, 'is_active' => true]);
        $verAdm = KpiTemplateVersion::firstOrCreate(
            ['kpi_template_id' => $tplAdm->id, 'version_number' => 1],
            ['status' => 'active', 'total_weight' => 100.00, 'rating_scheme_id' => $scheme->id, 'effective_from' => '2026-01-01', 'activated_at' => now()]
        );
        $admItems = [
            ['code' => 'ADM-01', 'weight' => 30.00, 'target' => 98.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'ADM-02', 'weight' => 25.00, 'target' => 100.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'ADM-03', 'weight' => 15.00, 'target' => 98.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => true, 'source' => 'system'],
            ['code' => 'ADM-04', 'weight' => 15.00, 'target' => 98.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => true, 'source' => 'system'],
            ['code' => 'ADM-05', 'weight' => 10.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'supervisor'],
            ['code' => 'ADM-06', 'weight' => 5.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'rubric', 'evidence' => false, 'source' => 'supervisor', 'rubric_criteria' => [
                'Kelengkapan pengarsipan invoice & surat jalan',
                'Kerapian dokumen fisik & digital',
                'Kesesuaian format pelaporan standar',
            ]],
        ];
        $this->attachTemplateItems($verAdm, $admItems, $defs);

        // Template Kasir (Total 100%)
        $tplKsr = KpiTemplate::firstOrCreate(['code' => 'TPL-KSR-01'], ['name' => 'Template KPI Kasir v1', 'position_id' => $posKsr->id, 'is_active' => true]);
        $verKsr = KpiTemplateVersion::firstOrCreate(
            ['kpi_template_id' => $tplKsr->id, 'version_number' => 1],
            ['status' => 'active', 'total_weight' => 100.00, 'rating_scheme_id' => $scheme->id, 'effective_from' => '2026-01-01', 'activated_at' => now()]
        );
        $ksrItems = [
            ['code' => 'KSR-01', 'weight' => 30.00, 'target' => 99.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'KSR-02', 'weight' => 25.00, 'target' => 0.00, 'unit' => 'Rp', 'formula' => 'zero_tolerance', 'target_json' => ['full_score_limit' => 50000.00, 'failure_limit' => 200000.00], 'evidence' => false, 'source' => 'system'],
            ['code' => 'KSR-03', 'weight' => 20.00, 'target' => 100.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'KSR-04', 'weight' => 10.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'KSR-05', 'weight' => 10.00, 'target' => 90.00, 'unit' => '%', 'formula' => 'rubric', 'evidence' => false, 'source' => 'supervisor', 'rubric_criteria' => [
                'Salam, senyum, sapa kepada pelanggan (3S)',
                'Ketelitian verifikasi uang tunai & QRIS/Debit',
                'Pemberian struk & ucapan terima kasih',
                'Kerapian area kasir & mesin EDC',
            ]],
            ['code' => 'KSR-06', 'weight' => 5.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'supervisor'],
        ];
        $this->attachTemplateItems($verKsr, $ksrItems, $defs);

        // Template Gudang (Total 100%)
        $tplGud = KpiTemplate::firstOrCreate(['code' => 'TPL-GUD-01'], ['name' => 'Template KPI Gudang v1', 'position_id' => $posGud->id, 'is_active' => true]);
        $verGud = KpiTemplateVersion::firstOrCreate(
            ['kpi_template_id' => $tplGud->id, 'version_number' => 1],
            ['status' => 'active', 'total_weight' => 100.00, 'rating_scheme_id' => $scheme->id, 'effective_from' => '2026-01-01', 'activated_at' => now()]
        );
        $gudItems = [
            ['code' => 'GUD-01', 'weight' => 30.00, 'target' => 98.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => true, 'source' => 'system'],
            ['code' => 'GUD-02', 'weight' => 20.00, 'target' => 2.00, 'unit' => '%', 'formula' => 'lower_is_better', 'target_json' => ['failure_limit' => 5.00], 'evidence' => false, 'source' => 'system'],
            ['code' => 'GUD-03', 'weight' => 15.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'GUD-04', 'weight' => 15.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'GUD-05', 'weight' => 10.00, 'target' => 100.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => true, 'source' => 'system'],
            ['code' => 'GUD-06', 'weight' => 5.00, 'target' => 90.00, 'unit' => '%', 'formula' => 'rubric', 'evidence' => false, 'source' => 'supervisor', 'rubric_criteria' => [
                'Penataan part sesuai rak & labeling jelas',
                'Kebersihan lantai & sirkulasi udara gudang',
                'Keamanan penyimpanan komponen bernilai tinggi',
            ]],
            ['code' => 'GUD-07', 'weight' => 5.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'supervisor'],
        ];
        $this->attachTemplateItems($verGud, $gudItems, $defs);

        // Template Supervisor (Total 100%)
        $tplSpv = KpiTemplate::firstOrCreate(['code' => 'TPL-SPV-01'], ['name' => 'Template KPI Supervisor v1', 'position_id' => $posSpv->id, 'is_active' => true]);
        $verSpv = KpiTemplateVersion::firstOrCreate(
            ['kpi_template_id' => $tplSpv->id, 'version_number' => 1],
            ['status' => 'active', 'total_weight' => 100.00, 'rating_scheme_id' => $scheme->id, 'effective_from' => '2026-01-01', 'activated_at' => now()]
        );
        $spvItems = [
            ['code' => 'SUP-01', 'weight' => 30.00, 'target' => 90.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'SUP-02', 'weight' => 20.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'SUP-03', 'weight' => 15.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'SUP-04', 'weight' => 10.00, 'target' => 90.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
            ['code' => 'SUP-05', 'weight' => 10.00, 'target' => 100.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => true, 'source' => 'system'],
            ['code' => 'SUP-06', 'weight' => 10.00, 'target' => 95.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'supervisor'],
            ['code' => 'SUP-07', 'weight' => 5.00, 'target' => 100.00, 'unit' => '%', 'formula' => 'higher_is_better', 'evidence' => false, 'source' => 'system'],
        ];
        $this->attachTemplateItems($verSpv, $spvItems, $defs);
    }

    protected function attachTemplateItems(KpiTemplateVersion $version, array $items, array $defs): void
    {
        $sort = 1;
        foreach ($items as $itemData) {
            $def = $defs[$itemData['code']] ?? null;
            if (!$def) continue;

            $source = in_array($itemData['source'], ['employee', 'cross_role', 'import'], true)
                ? 'system'
                : $itemData['source'];
            $sortOrder = $sort++;
            $tplItem = KpiTemplateItem::updateOrCreate(
                [
                    'template_version_id' => $version->id,
                    'kpi_definition_id' => $def->id,
                ],
                [
                    'weight' => $itemData['weight'],
                    'target_value' => $itemData['target'] ?? null,
                    'target_unit' => $itemData['unit'] ?? '%',
                    'target_json' => $itemData['target_json'] ?? null,
                    'formula_key' => $itemData['formula'],
                    'formula_params' => $itemData['formula_params'] ?? null,
                    'source_type' => $source,
                    'evidence_required' => $itemData['evidence'] ?? false,
                    'is_mandatory' => true,
                    'sort_order' => $sortOrder,
                ]
            );

            if (!empty($itemData['rubric_criteria'])) {
                $rubric = KpiRubric::firstOrCreate(
                    ['template_item_id' => $tplItem->id],
                    [
                        'name' => "Rubrik {$def->name}",
                        'description' => "Daftar kriteria evaluasi observasi Supervisor",
                    ]
                );

                $cSort = 1;
                foreach ($itemData['rubric_criteria'] as $crit) {
                    KpiRubricCriterion::firstOrCreate(
                        ['rubric_id' => $rubric->id, 'sort_order' => $cSort++],
                        [
                            'criterion_text' => $crit,
                            'points' => 1.00,
                            'is_mandatory' => true,
                        ]
                    );
                }
            }
        }
    }

    protected function seedUsersAndEmployees($posOwner, $posSpv, $posTek, $posCs, $posAdm, $posKsr, $posGud, $branchPusat, $branchSurabaya): void
    {
        // 1. Admin System
        $userAdmin = User::firstOrCreate(
            ['email' => 'admin@kpi.com'],
            ['name' => 'System Administrator', 'password' => Hash::make('password')]
        );
        $userAdmin->assignRole('super_admin', 'kpi_admin');

        // 2. Manager / Owner
        $userManager = User::firstOrCreate(
            ['email' => 'manager@toko.com'],
            ['name' => 'Hendra Wijaya (Manager / Owner)', 'password' => Hash::make('password')]
        );
        $userManager->assignRole('owner_manager');

        $empManager = Employee::firstOrCreate(
            ['employee_number' => 'EMP-001'],
            [
                'user_id' => $userManager->id,
                'name' => 'Hendra Wijaya',
                'email' => 'manager@toko.com',
                'phone' => '081234567890',
                'position_id' => $posOwner->id,
                'branch_id' => $branchPusat->id,
                'supervisor_id' => null,
                'joined_at' => '2023-01-01',
                'status' => 'active',
            ]
        );

        // 3. Supervisor
        $userSpv = User::firstOrCreate(
            ['email' => 'supervisor@toko.com'],
            ['name' => 'Agus Prasetyo (Supervisor)', 'password' => Hash::make('password')]
        );
        $userSpv->assignRole('supervisor');

        $empSpv = Employee::firstOrCreate(
            ['employee_number' => 'EMP-002'],
            [
                'user_id' => $userSpv->id,
                'name' => 'Agus Prasetyo',
                'email' => 'supervisor@toko.com',
                'phone' => '081234567891',
                'position_id' => $posSpv->id,
                'branch_id' => $branchPusat->id,
                'supervisor_id' => $empManager->id,
                'joined_at' => '2023-03-01',
                'status' => 'active',
            ]
        );

        // 4. Teknisi
        $userTek = User::firstOrCreate(
            ['email' => 'teknisi@toko.com'],
            ['name' => 'Budi Santoso (Teknisi)', 'password' => Hash::make('password')]
        );
        $userTek->assignRole('employee');

        Employee::firstOrCreate(
            ['employee_number' => 'EMP-003'],
            [
                'user_id' => $userTek->id,
                'name' => 'Budi Santoso',
                'email' => 'teknisi@toko.com',
                'phone' => '081234567892',
                'position_id' => $posTek->id,
                'branch_id' => $branchPusat->id,
                'supervisor_id' => $empSpv->id,
                'joined_at' => '2024-01-15',
                'status' => 'active',
            ]
        );

        // 5. Pelayan
        $userCs = User::firstOrCreate(
            ['email' => 'cs@toko.com'],
            ['name' => 'Siti Rahma (Pelayan)', 'password' => Hash::make('password')]
        );
        $userCs->assignRole('employee');

        Employee::firstOrCreate(
            ['employee_number' => 'EMP-004'],
            [
                'user_id' => $userCs->id,
                'name' => 'Siti Rahma',
                'email' => 'cs@toko.com',
                'phone' => '081234567893',
                'position_id' => $posCs->id,
                'branch_id' => $branchPusat->id,
                'supervisor_id' => $empSpv->id,
                'joined_at' => '2024-02-01',
                'status' => 'active',
            ]
        );

        // 6. Admin Staff
        $userAdm = User::firstOrCreate(
            ['email' => 'admin_staff@toko.com'],
            ['name' => 'Dewi Lestari (Admin)', 'password' => Hash::make('password')]
        );
        $userAdm->assignRole('employee');

        Employee::firstOrCreate(
            ['employee_number' => 'EMP-005'],
            [
                'user_id' => $userAdm->id,
                'name' => 'Dewi Lestari',
                'email' => 'admin_staff@toko.com',
                'phone' => '081234567894',
                'position_id' => $posAdm->id,
                'branch_id' => $branchPusat->id,
                'supervisor_id' => $empSpv->id,
                'joined_at' => '2024-02-15',
                'status' => 'active',
            ]
        );

        // 7. Kasir
        $userKsr = User::firstOrCreate(
            ['email' => 'kasir@toko.com'],
            ['name' => 'Rian Pratama (Kasir)', 'password' => Hash::make('password')]
        );
        $userKsr->assignRole('employee');

        Employee::firstOrCreate(
            ['employee_number' => 'EMP-006'],
            [
                'user_id' => $userKsr->id,
                'name' => 'Rian Pratama',
                'email' => 'kasir@toko.com',
                'phone' => '081234567895',
                'position_id' => $posKsr->id,
                'branch_id' => $branchPusat->id,
                'supervisor_id' => $empSpv->id,
                'joined_at' => '2024-03-01',
                'status' => 'active',
            ]
        );

        // 8. Gudang
        $userGud = User::firstOrCreate(
            ['email' => 'gudang@toko.com'],
            ['name' => 'Doni Kusuma (Gudang)', 'password' => Hash::make('password')]
        );
        $userGud->assignRole('employee');

        Employee::firstOrCreate(
            ['employee_number' => 'EMP-007'],
            [
                'user_id' => $userGud->id,
                'name' => 'Doni Kusuma',
                'email' => 'gudang@toko.com',
                'phone' => '081234567896',
                'position_id' => $posGud->id,
                'branch_id' => $branchPusat->id,
                'supervisor_id' => $empSpv->id,
                'joined_at' => '2024-03-15',
                'status' => 'active',
            ]
        );
    }

    protected function seedActivePeriod($branchPusat, $branchSurabaya): void
    {
        $year = 2026;
        $month = 8;

        $period = KpiPeriod::firstOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'name' => 'Periode Agustus 2026',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-31',
                'submission_deadline' => '2026-08-29 23:59:59',
                'review_deadline' => '2026-08-30 23:59:59',
                'approval_deadline' => '2026-08-31 23:59:59',
                'status' => 'DRAFT',
            ]
        );

        $period->branches()->syncWithoutDetaching([$branchPusat->id, $branchSurabaya->id]);

        // Open period and generate snapshots
        $periodService = app(PeriodService::class);
        $periodService->openPeriod($period);

        // 9. Seed Operational Service Management Data (Spareparts & Tickets)
        $this->seedOperationalServiceData($period, $branchPusat);
    }

    protected function seedOperationalServiceData($period, $branchPusat): void
    {
        $empTek = Employee::where('employee_number', 'EMP-003')->first();
        $empCs = Employee::where('employee_number', 'EMP-004')->first();
        $empGud = Employee::where('employee_number', 'EMP-007')->first();

        // 1. Spareparts
        $partsData = [
            ['branch_id' => $branchPusat->id, 'code' => 'PRT-LCD-IP13', 'name' => 'LCD Screen Assembly iPhone 13 OEM', 'category' => 'LCD', 'compatible_models' => 'iPhone 13, iPhone 13 Mini', 'stock_quantity' => 15, 'min_stock_alert' => 3, 'purchase_price' => 750000, 'selling_price' => 1250000, 'is_critical' => true],
            ['branch_id' => $branchPusat->id, 'code' => 'PRT-BAT-IP13P', 'name' => 'Baterai High Capacity iPhone 13 Pro 3095mAh', 'category' => 'Baterai', 'compatible_models' => 'iPhone 13 Pro', 'stock_quantity' => 20, 'min_stock_alert' => 5, 'purchase_price' => 220000, 'selling_price' => 450000, 'is_critical' => true],
            ['branch_id' => $branchPusat->id, 'code' => 'PRT-LCD-SMA54', 'name' => 'Super AMOLED LCD Samsung Galaxy A54 5G', 'category' => 'LCD', 'compatible_models' => 'Samsung Galaxy A54 5G', 'stock_quantity' => 8, 'min_stock_alert' => 2, 'purchase_price' => 550000, 'selling_price' => 850000, 'is_critical' => true],
            ['branch_id' => $branchPusat->id, 'code' => 'PRT-BAT-SMA54', 'name' => 'Baterai Original Samsung A54 5000mAh', 'category' => 'Baterai', 'compatible_models' => 'Samsung Galaxy A54', 'stock_quantity' => 12, 'min_stock_alert' => 3, 'purchase_price' => 180000, 'selling_price' => 320000, 'is_critical' => false],
            ['branch_id' => $branchPusat->id, 'code' => 'PRT-IC-PM8150', 'name' => 'IC Power PM8150 Xiaomi POCO F3 / BlackShark', 'category' => 'IC', 'compatible_models' => 'POCO F3, Mi 11X, K40', 'stock_quantity' => 5, 'min_stock_alert' => 2, 'purchase_price' => 150000, 'selling_price' => 350000, 'is_critical' => true],
            ['branch_id' => $branchPusat->id, 'code' => 'PRT-FLX-CHG12', 'name' => 'Fleksibel Port Charger iPhone 12 Lightning', 'category' => 'Fleksibel', 'compatible_models' => 'iPhone 12, iPhone 12 Pro', 'stock_quantity' => 10, 'min_stock_alert' => 3, 'purchase_price' => 90000, 'selling_price' => 220000, 'is_critical' => false],
            ['branch_id' => $branchPusat->id, 'code' => 'PRT-CAM-IP11', 'name' => 'Modul Dual Camera Belakang iPhone 11', 'category' => 'Kamera', 'compatible_models' => 'iPhone 11', 'stock_quantity' => 4, 'min_stock_alert' => 2, 'purchase_price' => 350000, 'selling_price' => 650000, 'is_critical' => true],
        ];

        $parts = [];
        foreach ($partsData as $pd) {
            $parts[$pd['code']] = \App\Models\Sparepart::firstOrCreate(['code' => $pd['code']], $pd);
        }

        // 1b. Produk non-sparepart (handset, tablet/iPad, aksesoris) — katalog produk toko
        $productsData = [
            ['branch_id' => $branchPusat->id, 'code' => 'HS-IP13-128', 'product_type' => 'handset', 'name' => 'Handset iPhone 13 128GB (Bekas)', 'category' => 'Handset', 'compatible_models' => 'iPhone 13', 'stock_quantity' => 3, 'min_stock_alert' => 1, 'purchase_price' => 6500000, 'selling_price' => 7500000, 'is_critical' => true],
            ['branch_id' => $branchPusat->id, 'code' => 'HS-SMA54-8', 'product_type' => 'handset', 'name' => 'Handset Samsung Galaxy A54 8/128 (Baru)', 'category' => 'Handset', 'compatible_models' => 'Samsung Galaxy A54', 'stock_quantity' => 5, 'min_stock_alert' => 2, 'purchase_price' => 3800000, 'selling_price' => 4500000, 'is_critical' => false],
            ['branch_id' => $branchPusat->id, 'code' => 'TB-IPAD9-64', 'product_type' => 'tablet', 'name' => 'iPad 9th Gen 64GB WiFi', 'category' => 'Tablet', 'compatible_models' => 'iPad 9', 'stock_quantity' => 2, 'min_stock_alert' => 1, 'purchase_price' => 4200000, 'selling_price' => 5200000, 'is_critical' => true],
            ['branch_id' => $branchPusat->id, 'code' => 'TB-SMTAB-A9', 'product_type' => 'tablet', 'name' => 'Samsung Galaxy Tab A9+ 4/64', 'category' => 'Tablet', 'compatible_models' => 'Tab A9+', 'stock_quantity' => 4, 'min_stock_alert' => 1, 'purchase_price' => 2300000, 'selling_price' => 2900000, 'is_critical' => false],
            ['branch_id' => $branchPusat->id, 'code' => 'ACC-TMPR-IP13', 'product_type' => 'aksesoris', 'name' => 'Tempered Glass iPhone 13', 'category' => 'Aksesoris', 'compatible_models' => 'iPhone 13', 'stock_quantity' => 50, 'min_stock_alert' => 10, 'purchase_price' => 15000, 'selling_price' => 35000, 'is_critical' => false],
            ['branch_id' => $branchPusat->id, 'code' => 'ACC-CASE-SMA54', 'product_type' => 'aksesoris', 'name' => 'Casing Silikon Samsung A54', 'category' => 'Aksesoris', 'compatible_models' => 'Samsung A54', 'stock_quantity' => 30, 'min_stock_alert' => 10, 'purchase_price' => 25000, 'selling_price' => 60000, 'is_critical' => false],
            ['branch_id' => $branchPusat->id, 'code' => 'ACC-CHG-20W', 'product_type' => 'aksesoris', 'name' => 'Charger Adaptor 20W USB-C', 'category' => 'Aksesoris', 'compatible_models' => 'Universal', 'stock_quantity' => 25, 'min_stock_alert' => 5, 'purchase_price' => 80000, 'selling_price' => 150000, 'is_critical' => false],
            ['branch_id' => $branchPusat->id, 'code' => 'ACC-KBL-USB', 'product_type' => 'aksesoris', 'name' => 'Kabel USB-C to Lightning 1m', 'category' => 'Aksesoris', 'compatible_models' => 'Universal', 'stock_quantity' => 40, 'min_stock_alert' => 10, 'purchase_price' => 30000, 'selling_price' => 75000, 'is_critical' => false],
        ];

        foreach ($productsData as $pd) {
            \App\Models\Sparepart::firstOrCreate(['code' => $pd['code']], $pd);
        }

        // 2. Demo Tickets (12 tickets)
        $ticketsData = [
            [
                'ticket_number' => 'SRV-202608-0001',
                'customer_name' => 'Ahmad Fauzi',
                'customer_phone' => '081298765432',
                'device_brand' => 'Apple',
                'device_model' => 'iPhone 13',
                'imei_or_serial' => '356789012345678',
                'initial_complaint' => 'Layar bergaris hijau dan touchscreen ghosting setelah jatuh.',
                'physical_condition' => 'Layar retak di sudut kanan atas, casing mulus.',
                'estimated_cost' => 1250000,
                'final_cost' => 1250000,
                'status' => 'delivered',
                'result_status' => 'success',
                'diagnosis_notes' => 'Digitizer dan panel OLED rusak fisik akibat benturan.',
                'action_notes' => 'Ganti modul LCD assembly OEM iPhone 13, pembersihan port dan pemindahan TrueTone IC.',
                'qc_checklist_json' => ['display' => true, 'touch' => true, 'camera' => true, 'mic' => true, 'speaker' => true, 'cellular' => true, 'charging' => true, 'face_id' => true],
                'is_warranty_return' => false,
                'started_at' => '2026-08-05 09:30:00',
                'completed_at' => '2026-08-05 11:15:00',
                'delivered_at' => '2026-08-05 16:00:00',
                'rating' => 5,
                'feedback_comment' => 'Pengerjaan cepat sekali, layar jernih seperti baru!',
            ],
            [
                'ticket_number' => 'SRV-202608-0002',
                'customer_name' => 'Rina Kartika',
                'customer_phone' => '081345678901',
                'device_brand' => 'Samsung',
                'device_model' => 'Galaxy A54 5G',
                'imei_or_serial' => '358901234567890',
                'initial_complaint' => 'Baterai cepat habis drop dari 50% langsung mati.',
                'physical_condition' => 'Backdoor agak renggang karena baterai kembung.',
                'estimated_cost' => 320000,
                'final_cost' => 320000,
                'status' => 'delivered',
                'result_status' => 'success',
                'diagnosis_notes' => 'Cell baterai degradasi parah, internal resistance tinggi.',
                'action_notes' => 'Ganti baterai original Samsung A54 5000mAh dan pasang lem sealant baru.',
                'qc_checklist_json' => ['display' => true, 'touch' => true, 'camera' => true, 'mic' => true, 'speaker' => true, 'cellular' => true, 'charging' => true, 'fingerprint' => true],
                'is_warranty_return' => false,
                'started_at' => '2026-08-06 10:00:00',
                'completed_at' => '2026-08-06 10:45:00',
                'delivered_at' => '2026-08-06 14:30:00',
                'rating' => 5,
                'feedback_comment' => 'Baterai awet normal kembali, mantap!',
            ],
            [
                'ticket_number' => 'SRV-202608-0003',
                'customer_name' => 'Bambang Sutejo',
                'customer_phone' => '081567890123',
                'device_brand' => 'Xiaomi',
                'device_model' => 'POCO F3',
                'imei_or_serial' => '861234567890123',
                'initial_complaint' => 'Mati total saat main game, tidak merespon charger.',
                'physical_condition' => 'Mulus tidak ada bekas jatuh.',
                'estimated_cost' => 450000,
                'final_cost' => 450000,
                'status' => 'delivered',
                'result_status' => 'success',
                'diagnosis_notes' => 'Short circuit pada jalur VDD_MAIN di IC Power PM8150.',
                'action_notes' => 'Reball dan pasang IC Power PM8150 baru, flashing firmware stabil.',
                'qc_checklist_json' => ['display' => true, 'touch' => true, 'camera' => true, 'mic' => true, 'speaker' => true, 'cellular' => true, 'charging' => true, 'fingerprint' => true],
                'is_warranty_return' => false,
                'started_at' => '2026-08-08 13:00:00',
                'completed_at' => '2026-08-08 16:30:00',
                'delivered_at' => '2026-08-09 11:00:00',
                'rating' => 5,
                'feedback_comment' => 'HP hidup kembali tanpa hilang data. Teknisi handal!',
            ],
            [
                'ticket_number' => 'SRV-202608-0004',
                'customer_name' => 'Maya Indah',
                'customer_phone' => '081789012345',
                'device_brand' => 'Apple',
                'device_model' => 'iPhone 12',
                'imei_or_serial' => '353456789012345',
                'initial_complaint' => 'Tidak bisa mengisi daya, kabel harus digoyang-goyang.',
                'physical_condition' => 'Port charger kotor dan pin kuningan aus.',
                'estimated_cost' => 220000,
                'final_cost' => 220000,
                'status' => 'delivered',
                'result_status' => 'success',
                'diagnosis_notes' => 'Pin konektor lightning patah 1 jalur.',
                'action_notes' => 'Ganti kabel fleksibel port charger iPhone 12 + mic bawah.',
                'qc_checklist_json' => ['display' => true, 'touch' => true, 'camera' => true, 'mic' => true, 'speaker' => true, 'cellular' => true, 'charging' => true, 'face_id' => true],
                'is_warranty_return' => false,
                'started_at' => '2026-08-10 11:00:00',
                'completed_at' => '2026-08-10 11:50:00',
                'delivered_at' => '2026-08-10 15:00:00',
                'rating' => 4,
                'feedback_comment' => 'Bisa ngecas normal lagi lancar.',
            ],
            [
                'ticket_number' => 'SRV-202608-0005',
                'customer_name' => 'Dedi Kurniawan',
                'customer_phone' => '081890123456',
                'device_brand' => 'Apple',
                'device_model' => 'iPhone 13 Pro',
                'imei_or_serial' => '359012345678901',
                'initial_complaint' => 'Layar mati gelap tapi suara nada dering masih masuk.',
                'physical_condition' => 'Kena air saat hujan.',
                'estimated_cost' => 1500000,
                'final_cost' => 1500000,
                'status' => 'completed',
                'result_status' => 'success',
                'diagnosis_notes' => 'Karat korosi di konektor display FPC jalur tegangan backlight.',
                'action_notes' => 'Ultrasonic cleaning motherboard, jumper 2 jalur backlight, pasang proteksi air.',
                'qc_checklist_json' => ['display' => true, 'touch' => true, 'camera' => true, 'mic' => true, 'speaker' => true, 'cellular' => true, 'charging' => true, 'face_id' => true],
                'is_warranty_return' => false,
                'started_at' => '2026-08-12 09:00:00',
                'completed_at' => '2026-08-12 12:00:00',
                'delivered_at' => null,
            ],
            [
                'ticket_number' => 'SRV-202608-0006',
                'customer_name' => 'Eko Prasetyo',
                'customer_phone' => '081901234567',
                'device_brand' => 'Samsung',
                'device_model' => 'Galaxy A54 5G',
                'imei_or_serial' => '351234567890124',
                'initial_complaint' => 'Kaca kamera pecah dan hasil foto buram berdebu.',
                'physical_condition' => 'Lensa luar pecah.',
                'estimated_cost' => 200000,
                'final_cost' => 200000,
                'status' => 'delivered',
                'result_status' => 'success',
                'diagnosis_notes' => 'Lensa luar pecah, modul sensor optik masih aman.',
                'action_notes' => 'Ganti kaca ring lensa kamera Samsung A54 dan vacuum debu optik.',
                'qc_checklist_json' => ['display' => true, 'touch' => true, 'camera' => true, 'mic' => true, 'speaker' => true, 'cellular' => true, 'charging' => true, 'fingerprint' => true],
                'is_warranty_return' => false,
                'started_at' => '2026-08-14 14:00:00',
                'completed_at' => '2026-08-14 14:35:00',
                'delivered_at' => '2026-08-14 17:00:00',
                'rating' => 5,
                'feedback_comment' => 'Hasil foto jernih lagi!',
            ],
            [
                'ticket_number' => 'SRV-202608-0007',
                'customer_name' => 'Hendra Gunawan',
                'customer_phone' => '081234098765',
                'device_brand' => 'Xiaomi',
                'device_model' => 'POCO F3',
                'imei_or_serial' => '869876543210987',
                'initial_complaint' => 'Setelah servis tombol power kemarin, kadang restart sendiri.',
                'physical_condition' => 'Klaim retur garansi servis.',
                'estimated_cost' => 0,
                'final_cost' => 0,
                'status' => 'delivered',
                'result_status' => 'warranty_return',
                'diagnosis_notes' => 'Fleksibel tombol power terjepit frame samping.',
                'action_notes' => 'Reposisi fleksibel tombol power dan pasang isolator kapton tape (Garansi Gratis).',
                'qc_checklist_json' => ['display' => true, 'touch' => true, 'camera' => true, 'mic' => true, 'speaker' => true, 'cellular' => true, 'charging' => true, 'fingerprint' => true],
                'is_warranty_return' => true,
                'started_at' => '2026-08-16 10:00:00',
                'completed_at' => '2026-08-16 10:30:00',
                'delivered_at' => '2026-08-16 11:00:00',
                'rating' => 4,
                'feedback_comment' => 'Garansi dilayani dengan cepat dan ramah.',
            ],
            [
                'ticket_number' => 'SRV-202608-0008',
                'customer_name' => 'Siti Nurhaliza',
                'customer_phone' => '081321098765',
                'device_brand' => 'Apple',
                'device_model' => 'iPhone 11',
                'imei_or_serial' => '354321098765432',
                'initial_complaint' => 'Kamera getar dan bersuara berdengung saat buka Instagram.',
                'physical_condition' => 'Mulus.',
                'estimated_cost' => 650000,
                'final_cost' => 650000,
                'status' => 'delivered',
                'result_status' => 'success',
                'diagnosis_notes' => 'OIS (Optical Image Stabilization) magnet kamera rontok akibat getaran motor.',
                'action_notes' => 'Ganti modul kamera belakang iPhone 11 original unit.',
                'qc_checklist_json' => ['display' => true, 'touch' => true, 'camera' => true, 'mic' => true, 'speaker' => true, 'cellular' => true, 'charging' => true, 'face_id' => true],
                'is_warranty_return' => false,
                'started_at' => '2026-08-18 13:30:00',
                'completed_at' => '2026-08-18 14:45:00',
                'delivered_at' => '2026-08-18 18:00:00',
                'rating' => 5,
                'feedback_comment' => 'Kamera tidak bergetar lagi, fokus mulus.',
            ],
            [
                'ticket_number' => 'SRV-202608-0009',
                'customer_name' => 'Ferry Irawan',
                'customer_phone' => '081543210987',
                'device_brand' => 'Samsung',
                'device_model' => 'Galaxy A54 5G',
                'imei_or_serial' => '357654321098765',
                'initial_complaint' => 'Layar blank hitam setelah terlindas kendaraan.',
                'physical_condition' => 'Papan PCB melengkung parah dan layer tengah putus.',
                'estimated_cost' => 1200000,
                'final_cost' => 0,
                'status' => 'completed',
                'result_status' => 'unrepairable',
                'diagnosis_notes' => 'Core CPU snap & RAM BGA retak internal, PCB patah mikroskopis.',
                'action_notes' => 'Unit dinyatakan tidak dapat diperbaiki (Unrepairable). Konfirmasi ke pelanggan.',
                'qc_checklist_json' => ['display' => false, 'touch' => false, 'camera' => false, 'mic' => false, 'speaker' => false, 'cellular' => false, 'charging' => false, 'fingerprint' => false],
                'is_warranty_return' => false,
                'started_at' => '2026-08-20 10:00:00',
                'completed_at' => '2026-08-20 11:30:00',
                'delivered_at' => null,
            ],
            [
                'ticket_number' => 'SRV-202608-0010',
                'customer_name' => 'Dewi Safitri',
                'customer_phone' => '081654321098',
                'device_brand' => 'Apple',
                'device_model' => 'iPhone 13',
                'imei_or_serial' => '358765432109876',
                'initial_complaint' => 'Baterai boros dan panas saat dipakai telepon.',
                'physical_condition' => 'Mulus.',
                'estimated_cost' => 450000,
                'final_cost' => 450000,
                'status' => 'in_progress',
                'result_status' => 'pending',
                'diagnosis_notes' => 'Kesehatan baterai 72%, perlu penggantian sel baru.',
                'action_notes' => 'Sedang dalam proses pembongkaran dan pemindahan BMS chip.',
                'qc_checklist_json' => null,
                'is_warranty_return' => false,
                'started_at' => '2026-08-22 09:00:00',
                'completed_at' => null,
                'delivered_at' => null,
            ],
        ];

        foreach ($ticketsData as $td) {
            $ticket = \App\Models\ServiceTicket::firstOrCreate(
                ['ticket_number' => $td['ticket_number']],
                [
                    'customer_name' => $td['customer_name'],
                    'customer_phone' => $td['customer_phone'],
                    'device_brand' => $td['device_brand'],
                    'device_model' => $td['device_model'],
                    'imei_or_serial' => $td['imei_or_serial'],
                    'initial_complaint' => $td['initial_complaint'],
                    'physical_condition' => $td['physical_condition'],
                    'estimated_cost' => $td['estimated_cost'],
                    'final_cost' => $td['final_cost'],
                    'estimated_completion_at' => now()->addDays(2),
                    'branch_id' => $branchPusat->id,
                    'period_id' => $period->id,
                    'intake_by_employee_id' => $empCs?->id,
                    'technician_employee_id' => $empTek?->id,
                    'status' => $td['status'],
                    'result_status' => $td['result_status'],
                    'diagnosis_notes' => $td['diagnosis_notes'],
                    'action_notes' => $td['action_notes'],
                    'qc_checklist_json' => $td['qc_checklist_json'],
                    'is_warranty_return' => $td['is_warranty_return'],
                    'started_at' => $td['started_at'],
                    'completed_at' => $td['completed_at'],
                    'delivered_at' => $td['delivered_at'],
                ]
            );

            if (!empty($td['rating'])) {
                \App\Models\CustomerFeedback::firstOrCreate(
                    ['service_ticket_id' => $ticket->id],
                    [
                        'cs_employee_id' => $empCs?->id,
                        'customer_name' => $td['customer_name'],
                        'rating' => $td['rating'],
                        'comments' => $td['feedback_comment'] ?? 'Pelayanan sangat baik.',
                        'follow_up_ontime' => true,
                        'feedback_channel' => 'in_store',
                    ]
                );
            }
        }

        // 3. Trigger Automatic Sync to KPI Engine!
        $syncService = app(\App\Modules\Assessment\OperationalKpiSyncService::class);
        $syncService->syncPeriodOperationalData($period);
    }
}
