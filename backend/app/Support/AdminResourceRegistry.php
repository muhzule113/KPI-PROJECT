<?php

namespace App\Support;

use App\Models\AdminWorkLog;
use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\CoachingLog;
use App\Models\Complaint;
use App\Models\CustomerFeedback;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\ImportBatch;
use App\Models\KpiCorrectionRequest;
use App\Models\KpiDefinition;
use App\Models\KpiPeriod;
use App\Models\KpiRatingBand;
use App\Models\KpiRatingScheme;
use App\Models\KpiTemplate;
use App\Models\Position;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\StockOpname;
use App\Models\User;
use App\Modules\Approval\ApprovalService;
use App\Modules\Assessment\AdminWorkLogKpiSyncService;
use App\Modules\Assessment\AttendanceKpiSyncService;
use App\Modules\Assessment\CoachingKpiSyncService;
use App\Modules\Assessment\ComplaintKpiSyncService;
use App\Modules\Assessment\DailyAssessmentService;
use App\Modules\Assessment\InventoryKpiSyncService;
use App\Modules\Assessment\StockOpnameService;
use App\Modules\Import\CashierImportService;
use App\Modules\Organization\EmployeePlacementService;
use App\Modules\Period\PeriodService;
use App\Modules\Service\ServiceTicketService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

final class AdminResourceRegistry
{
    public static function get(string $key): ?array
    {
        $config = KpiConfigurationResources::definitions()[$key] ?? self::definitions()[$key] ?? null;

        if ($key === 'service-tickets' && $config) {
            $config['scope'] = static fn ($query, User $user) => $query->whereIn('id', app(ServiceTicketService::class)->scopeTickets($user)->select('id'));
            $config['actions'] = [
                'workflow' => ['label' => 'Detail & tindakan', 'type' => 'link', 'record_url' => '/app/service-tickets/:record/workflow'],
            ];
        }

        if ($key === 'employee-kpis' && $config) {
            $config['scope'] = static fn ($query, User $user) => KpiVisibility::applyScope($query, $user);
            $config['actions']['assess']['visible'] = static fn (Model $record, $user): bool => KpiWorkflow::canManageKpi($user, $record)
                && ($record->status === 'pending_approval' || ($record->position_code_snapshot === 'POS-SPV' && ! in_array($record->status, ['approved', 'locked'], true)));
            $config['actions']['approve']['visible'] = static fn (Model $record, $user): bool => KpiWorkflow::canApproveKpi($user, $record)
                && (in_array($record->status, ['pending_approval', 'verified'], true) || ($record->position_code_snapshot === 'POS-SPV' && in_array($record->status, ['submitted', 'under_review', 'revision_required'], true)));
        }
        if ($key === 'supervisor-reviews' && $config) {
            $config['scope'] = static fn ($query, User $user) => KpiVisibility::applyScope($query, $user)
                ->where('supervisor_id_snapshot', $user->employee?->id)->whereIn('status', ['submitted', 'under_review']);
        }
        if ($key === 'kpi-correction-requests' && $config) {
            $config['scope'] = static fn ($query, User $user) => $query->whereHas('employeeKpi', fn ($kpis) => KpiVisibility::applyScope($kpis, $user));
        }
        if ($key === 'kpi-periods' && $config) {
            $config['can_edit'] = static fn ($user, ?Model $record = null): bool => ! $record || in_array($record->status, ['DRAFT', 'READY'], true);
            $config['scope'] = static function ($query, User $user): void {
                if (! CapabilityMatrix::has($user, 'kpi.monitor')) {
                    $query->whereHas('employeeKpis', fn ($kpis) => KpiVisibility::applyScope($kpis, $user));
                }
            };
            $config['actions']['sync_daily'] = [
                'label' => 'Siapkan ulang fakta KPI',
                'permission' => ['roles' => ['kpi_admin'], 'positions' => []],
                'visible' => static fn (Model $record): bool => in_array($record->status, ['OPEN', 'SUBMISSION_CLOSED', 'IN_REVIEW', 'WAITING_APPROVAL'], true),
                'prompt' => ['name' => 'through_date', 'label' => 'Siapkan sampai tanggal (YYYY-MM-DD)'],
                'handler' => static function (Request $request, Model $record): string {
                    $data = $request->validate(['through_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$record->start_date->toDateString(), 'before_or_equal:today']]);
                    try {
                        $count = app(DailyAssessmentService::class)->preparePeriod($record, $data['through_date']);
                        AuditEvent::log('daily_kpi_sync_completed', 'KpiPeriod', (string) $record->id, after: ['through_date' => $data['through_date'], 'prepared_kpis' => $count]);

                        return "Fakta dan rekap {$count} KPI disiapkan. Hasil tersedia di audit sinkronisasi.";
                    } catch (\Throwable $exception) {
                        AuditEvent::log('daily_kpi_sync_failed', 'KpiPeriod', (string) $record->id, after: ['through_date' => $data['through_date'], 'error' => $exception->getMessage()]);
                        throw $exception;
                    }
                },
            ];
        }
        if (in_array($key, ['employee-kpis', 'supervisor-reviews'], true) && $config) {
            $config['with'] = ['employee', 'period', 'positionSnapshot', 'branchSnapshot'];
            foreach ($config['columns'] as &$column) {
                if ($column['key'] === 'employee.position.name') {
                    $column['key'] = 'positionSnapshot.name';
                }
            }
            unset($column);
        }
        if ($key === 'audit-events' && $config) {
            $config['columns'][] = ['key' => 'after_json.error', 'label' => 'Kegagalan Sinkronisasi'];
            $config['columns'][] = ['key' => 'after_json.through_date', 'label' => 'Fakta Sampai Tanggal'];
            $config['scope'] = static function ($query, User $user): void {
                if ($user->hasRole('super_admin')) {
                    $query->whereIn('subject_type', ['User', 'Employee', 'Branch', 'Position', 'Role', 'SystemConfiguration']);
                } elseif ($user->hasRole('kpi_admin')) {
                    $query->whereIn('action', ['daily_kpi_sync_completed', 'daily_kpi_sync_failed', 'template_activated', 'template_copied']);
                }
            };
        }

        if ($config && in_array($key, ['admin-work-logs', 'coaching-logs', 'complaints', 'attendances', 'stock-opnames', 'spareparts', 'import-batches'], true)) {
            $config = self::operationalAccess($key, $config);
        }

        return $config ? ['key' => $key, ...$config] : null;
    }

    public static function definitions(): array
    {
        return [
            'users' => [
                'model' => User::class,
                'label' => 'Pengguna',
                'plural_label' => 'Pengguna Sistem',
                'description' => 'Hubungkan akun dengan karyawan. Teknisi, Pelayan, dan Gudang memakai mobile; Kasir, Admin Operasional, Supervisor, serta Manager memakai mobile dan web; Admin KPI, Admin Sistem, dan Auditor memakai web.',
                'permission' => ['roles' => ['super_admin'], 'positions' => []],
                'search' => ['name', 'email'],
                'with' => ['roles', 'employee.position', 'employee.branch'],
                'order_by' => 'name',
                'columns' => [
                    ['key' => 'name', 'label' => 'Nama', 'emphasis' => true],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'roles_label', 'label' => 'Peran', 'placeholder' => 'Belum ada peran'],
                    ['key' => 'employee.name', 'label' => 'Karyawan', 'placeholder' => 'Akun administratif'],
                    ['key' => 'employee.position.name', 'label' => 'Jabatan'],
                    ['key' => 'allowed_platforms_label', 'label' => 'Platform'],
                    ['key' => 'is_active', 'label' => 'Akun Aktif', 'type' => 'boolean'],
                    ['key' => 'created_at', 'label' => 'Dibuat', 'type' => 'date'],
                ],
                'fields' => [
                    ['name' => 'name', 'label' => 'Nama Lengkap', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email Login', 'type' => 'email', 'required' => true],
                    ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => false, 'sensitive' => true, 'help' => 'Wajib diisi saat membuat akun. Kosongkan saat edit jika tidak ingin mengganti password.'],
                    ['name' => 'role_ids', 'label' => 'Peran Akses', 'type' => 'checkbox-list', 'required' => true],
                    ['name' => 'employee_id', 'label' => 'Profil Karyawan', 'type' => 'select', 'help' => 'Wajib terhubung sebelum akun operasional dapat login. Akun administrasi tidak mendapat tugas operasional dari jabatan.'],
                    ['name' => 'is_active', 'label' => 'Akun Aktif', 'type' => 'checkbox', 'default' => true],
                ],
                'rules' => static fn (?User $record): array => [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($record?->getKey())],
                    'password' => [$record ? 'nullable' : 'required', 'string', 'min:8'],
                    'is_active' => ['required', 'boolean'],
                    'employee_id' => ['nullable', 'string', Rule::exists('employees', 'id')->where(fn ($query) => $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $record?->id)))],
                    'role_ids' => ['required', 'array', 'min:1', static function (string $attribute, mixed $value, \Closure $fail): void {
                        if (CapabilityMatrix::roleConflict(Role::whereIn('id', $value)->pluck('name')->all())) {
                            $fail('Admin Sistem, Admin KPI, dan Auditor harus memakai akun terpisah dari peran operasional. Supervisor dan Manager juga harus dipisahkan.');
                        }
                    }],
                    'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('guard_name', 'web'))],
                ],
                'options' => [
                    'role_ids' => static fn (?Model $record = null): array => self::roleOptions(),
                    'employee_id' => static fn (?Model $record = null): array => Employee::query()
                        ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $record?->id))
                        ->orderBy('name')->get()->map(fn (Employee $employee): array => ['value' => $employee->id, 'label' => $employee->employee_number.' — '.$employee->name])->all(),
                ],
                'relationships' => ['role_ids' => 'roles'],
                'after_create' => static fn (User $record, Request $request) => self::linkAccountEmployee($record, $request),
                'after_update' => static fn (User $record, Request $request) => self::linkAccountEmployee($record, $request),
                'prepare' => static function (array $data, Request $request, ?Model $record): array {
                    if ($record && blank($data['password'] ?? null)) {
                        $data['password'] = $record->getRawOriginal('password');
                    }

                    return $data;
                },
            ],
            'employees' => [
                'model' => Employee::class,
                'label' => 'Karyawan',
                'plural_label' => 'Karyawan',
                'description' => 'Kelola identitas, jabatan, cabang, dan atasan karyawan.',
                'permission' => ['roles' => ['super_admin'], 'positions' => []],
                'search' => ['employee_number', 'name', 'email'],
                'with' => ['position', 'branch', 'supervisor', 'user'],
                'columns' => [
                    ['key' => 'employee_number', 'label' => 'NIK'],
                    ['key' => 'name', 'label' => 'Nama Karyawan', 'emphasis' => true],
                    ['key' => 'user.email', 'label' => 'Akun Login', 'placeholder' => 'Belum terhubung'],
                    ['key' => 'position.name', 'label' => 'Jabatan', 'type' => 'badge'],
                    ['key' => 'branch.name', 'label' => 'Cabang'],
                    ['key' => 'supervisor.name', 'label' => 'Supervisor', 'placeholder' => 'Tidak ada atasan'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ],
                'fields' => [
                    ['name' => 'employee_number', 'label' => 'Nomor Induk Karyawan (NIK)', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: EMP-001'],
                    ['name' => 'user_id', 'label' => 'Akun Login', 'type' => 'select'],
                    ['name' => 'name', 'label' => 'Nama Lengkap', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                    ['name' => 'phone', 'label' => 'Nomor Telepon', 'type' => 'text'],
                    ['name' => 'position_id', 'label' => 'Jabatan', 'type' => 'select', 'required' => true],
                    ['name' => 'branch_id', 'label' => 'Cabang Penempatan', 'type' => 'select', 'required' => true],
                    ['name' => 'supervisor_id', 'label' => 'Atasan Langsung', 'type' => 'select'],
                    ['name' => 'placement_effective_from', 'label' => 'Perubahan Penempatan Berlaku Mulai', 'type' => 'date', 'help' => 'Wajib saat jabatan, cabang, atau atasan diubah.'],
                    ['name' => 'joined_at', 'label' => 'Tanggal Bergabung', 'type' => 'date', 'required' => true],
                    [
                        'name' => 'status',
                        'label' => 'Status Karyawan',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'active',
                        'options' => [
                            ['value' => 'active', 'label' => 'Aktif'],
                            ['value' => 'inactive', 'label' => 'Tidak Aktif'],
                            ['value' => 'resigned', 'label' => 'Resign'],
                            ['value' => 'leave', 'label' => 'Cuti'],
                        ],
                    ],
                ],
                'rules' => static fn (?Employee $record): array => [
                    'employee_number' => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_number')->ignore($record?->getKey())],
                    'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('employees', 'user_id')->ignore($record?->getKey())],
                    'name' => ['required', 'string', 'max:150'],
                    'email' => ['required', 'email', 'max:150', Rule::unique('employees', 'email')->ignore($record?->getKey())],
                    'phone' => ['nullable', 'string', 'max:30'],
                    'position_id' => ['required', 'integer', 'exists:positions,id'],
                    'branch_id' => ['required', 'integer', 'exists:branches,id'],
                    'supervisor_id' => ['nullable', 'string', 'exists:employees,id'],
                    'placement_effective_from' => [Rule::requiredIf(fn (): bool => $record !== null && (
                        (string) request('position_id') !== (string) $record->position_id
                        || (string) request('branch_id') !== (string) $record->branch_id
                        || (string) request('supervisor_id') !== (string) $record->supervisor_id
                    )), 'nullable', 'date'],
                    'joined_at' => ['required', 'date'],
                    'status' => ['required', Rule::in(['active', 'inactive', 'resigned', 'leave'])],
                ],
                'options' => [
                    'user_id' => static fn (?Model $record = null): array => User::query()
                        ->where(fn ($query) => $query->whereDoesntHave('employee')->orWhere('id', $record?->user_id))
                        ->orderBy('name')->get()->map(fn (User $user): array => ['value' => (string) $user->id, 'label' => $user->name.' — '.$user->email])->all(),
                    'position_id' => static fn (?Model $record = null): array => self::positionOptions(),
                    'branch_id' => static fn (?Model $record = null): array => self::branchOptions(),
                    'supervisor_id' => static fn (?Model $record = null): array => self::employeeOptions($record),
                ],
                'after_create' => static fn (Employee $record) => app(EmployeePlacementService::class)->place(
                    $record, $record->position_id, $record->branch_id, $record->supervisor_id, $record->joined_at->toDateString(), 'Placement awal'
                ),
                'after_update' => static function (Employee $record, Request $request): void {
                    if ($record->wasChanged(['position_id', 'branch_id', 'supervisor_id'])) {
                        app(EmployeePlacementService::class)->place($record, $record->position_id, $record->branch_id,
                            $record->supervisor_id, $request->string('placement_effective_from')->toString(), 'Perubahan melalui administrasi karyawan');
                    }
                },
            ],
            'positions' => [
                'model' => Position::class,
                'label' => 'Jabatan',
                'plural_label' => 'Jabatan / Posisi',
                'description' => 'Kelola struktur jabatan dan departemen organisasi.',
                'permission' => ['roles' => ['super_admin'], 'positions' => []],
                'search' => ['code', 'name', 'department'],
                'with_count' => ['employees'],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode'],
                    ['key' => 'name', 'label' => 'Nama Jabatan', 'emphasis' => true],
                    ['key' => 'department', 'label' => 'Departemen', 'type' => 'badge'],
                    ['key' => 'employees_count', 'label' => 'Jumlah Karyawan', 'align' => 'center'],
                    ['key' => 'is_active', 'label' => 'Status', 'type' => 'boolean'],
                ],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode Jabatan', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: POS-ADM'],
                    ['name' => 'name', 'label' => 'Nama Jabatan', 'type' => 'text', 'required' => true],
                    ['name' => 'department', 'label' => 'Departemen', 'type' => 'text', 'required' => true, 'default' => 'Operasional'],
                    ['name' => 'description', 'label' => 'Deskripsi Tugas', 'type' => 'textarea'],
                    ['name' => 'is_active', 'label' => 'Jabatan Aktif', 'type' => 'checkbox', 'default' => true],
                ],
                'rules' => static fn (?Position $record): array => [
                    'code' => ['required', 'string', 'max:50', Rule::unique('positions', 'code')->ignore($record?->getKey())],
                    'name' => ['required', 'string', 'max:100'],
                    'department' => ['required', 'string', 'max:100'],
                    'description' => ['nullable', 'string'],
                    'is_active' => ['required', 'boolean'],
                ],
            ],
            'branches' => [
                'model' => Branch::class,
                'label' => 'Cabang',
                'plural_label' => 'Cabang Toko',
                'description' => 'Kelola cabang toko, alamat, dan status operasionalnya.',
                'permission' => ['roles' => ['super_admin'], 'positions' => []],
                'search' => ['code', 'name', 'address'],
                'with_count' => ['employees'],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode'],
                    ['key' => 'name', 'label' => 'Nama Cabang', 'emphasis' => true],
                    ['key' => 'address', 'label' => 'Alamat', 'placeholder' => 'Belum diisi'],
                    ['key' => 'employees_count', 'label' => 'Jumlah Karyawan', 'align' => 'center'],
                    ['key' => 'is_active', 'label' => 'Status', 'type' => 'boolean'],
                ],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode Cabang', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: CB-PUSAT'],
                    ['name' => 'name', 'label' => 'Nama Cabang', 'type' => 'text', 'required' => true],
                    ['name' => 'address', 'label' => 'Alamat', 'type' => 'text'],
                    ['name' => 'phone', 'label' => 'Telepon', 'type' => 'text'],
                    ['name' => 'is_active', 'label' => 'Cabang Aktif', 'type' => 'checkbox', 'default' => true],
                ],
                'rules' => static fn (?Branch $record): array => [
                    'code' => ['required', 'string', 'max:50', Rule::unique('branches', 'code')->ignore($record?->getKey())],
                    'name' => ['required', 'string', 'max:100'],
                    'address' => ['nullable', 'string', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:30'],
                    'is_active' => ['required', 'boolean'],
                ],
            ],
            'attendances' => [
                'model' => Attendance::class,
                'label' => 'Absensi',
                'plural_label' => 'Absensi & Kehadiran',
                'description' => 'Catatan absensi per karyawan per hari kerja pada periode OPEN. Supervisor mencatat timnya melalui checklist; baris belum dicatat tidak otomatis menjadi Alpha sebelum cut-off.',
                'permission' => ['roles' => ['owner_manager'], 'positions' => []],
                'can_create' => static fn (): bool => KpiPeriod::active() !== null,
                'can_edit' => static fn (): bool => KpiPeriod::active() !== null,
                'search' => ['employee.name', 'status', 'note'],
                'with' => ['employee', 'branch'],
                'scope' => static function ($query, $user): void {
                    $period = KpiPeriod::active();
                    if (! $period) {
                        $query->whereIn('id', []);

                        return;
                    }

                    $query->whereBetween('attendance_date', [
                        $period->start_date->toDateString(),
                        $period->end_date->toDateString(),
                    ]);
                },
                'columns' => [
                    ['key' => 'employee.name', 'label' => 'Karyawan', 'emphasis' => true],
                    ['key' => 'attendance_date', 'label' => 'Tanggal', 'type' => 'date'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'labels' => [
                        Attendance::STATUS_PRESENT => 'Hadir',
                        Attendance::STATUS_LATE => 'Terlambat',
                        Attendance::STATUS_PERMISSION => 'Izin',
                        Attendance::STATUS_SICK_LEAVE => 'Sakit',
                        Attendance::STATUS_ABSENT => 'Alpha',
                    ]],
                    ['key' => 'check_in_time', 'label' => 'Jam Masuk', 'type' => 'time', 'placeholder' => '—'],
                    ['key' => 'check_out_time', 'label' => 'Jam Keluar', 'type' => 'time', 'placeholder' => '—'],
                ],
                'fields' => [
                    ['name' => 'employee_id', 'label' => 'Karyawan', 'type' => 'select', 'required' => true, 'help' => 'Hanya karyawan aktif pada cabang yang ikut periode OPEN.'],
                    ['name' => 'attendance_date', 'label' => 'Tanggal', 'type' => 'date', 'required' => true, 'default' => KpiPeriod::active()?->start_date?->toDateString(), 'help' => 'Harus berada di antara tanggal mulai dan selesai periode OPEN. Akhir pekan tidak masuk perhitungan KPI.'],
                    ['name' => 'status', 'label' => 'Status Kehadiran', 'type' => 'select', 'required' => true, 'help' => 'Hadir/Terlambat = masuk kerja; Izin/Sakit = beralasan dan dikeluarkan dari pembagi; Alpha = tidak hadir.', 'options' => [
                        ['value' => Attendance::STATUS_PRESENT, 'label' => 'Hadir'],
                        ['value' => Attendance::STATUS_LATE, 'label' => 'Terlambat'],
                        ['value' => Attendance::STATUS_PERMISSION, 'label' => 'Izin'],
                        ['value' => Attendance::STATUS_SICK_LEAVE, 'label' => 'Sakit'],
                        ['value' => Attendance::STATUS_ABSENT, 'label' => 'Alpha'],
                    ]],
                    ['name' => 'check_in_time', 'label' => 'Jam Masuk', 'type' => 'time', 'help' => 'Wajib diisi untuk status Hadir atau Terlambat.'],
                    ['name' => 'check_out_time', 'label' => 'Jam Keluar', 'type' => 'time'],
                    ['name' => 'note', 'label' => 'Catatan', 'type' => 'textarea', 'help' => 'Wajib diisi untuk status Izin, Sakit, atau Alpha.'],
                ],
                'rules' => static fn (?Attendance $record): array => [
                    'employee_id' => [
                        'required',
                        'string',
                        Rule::exists('employees', 'id')->where(fn ($query) => $query->where('status', 'active')),
                        Rule::unique('attendances', 'employee_id')
                            ->where(fn ($query) => $query->where('attendance_date', request('attendance_date')))
                            ->ignore($record?->getKey()),
                        static function (string $attribute, mixed $value, \Closure $fail): void {
                            $period = KpiPeriod::active();
                            if (! $period) {
                                $fail('Absensi hanya dapat dicatat saat ada periode KPI OPEN.');

                                return;
                            }

                            $isEligible = Employee::query()
                                ->whereKey($value)
                                ->where('status', 'active')
                                ->whereIn('branch_id', $period->branches()->pluck('branches.id'))
                                ->exists();
                            if (! $isEligible) {
                                $fail('Karyawan harus aktif dan berada pada cabang peserta periode OPEN.');
                            }
                        },
                    ],
                    'attendance_date' => [
                        'required',
                        'date_format:Y-m-d',
                        static function (string $attribute, mixed $value, \Closure $fail): void {
                            $period = KpiPeriod::active();
                            if (! $period) {
                                $fail('Absensi hanya dapat dicatat saat ada periode KPI OPEN.');

                                return;
                            }

                            try {
                                $date = Carbon::createFromFormat('!Y-m-d', (string) $value);
                            } catch (\Throwable) {
                                return;
                            }

                            if ($date->lt($period->start_date) || $date->gt($period->end_date)) {
                                $fail("Tanggal absensi harus berada dalam periode {$period->name} ({$period->start_date->format('d M Y')}–{$period->end_date->format('d M Y')}).");
                            } elseif ($date->isFuture()) {
                                $fail('Absensi tidak dapat dicatat untuk tanggal yang belum terjadi.');
                            }
                        },
                    ],
                    'status' => ['required', Rule::in(Attendance::STATUSES)],
                    'check_in_time' => [Rule::requiredIf(fn (): bool => in_array(request('status'), Attendance::WORKED_STATUSES, true)), 'nullable', 'date_format:H:i'],
                    'check_out_time' => ['nullable', 'date_format:H:i'],
                    'note' => [Rule::requiredIf(fn (): bool => in_array(request('status'), [...Attendance::EXCUSED_STATUSES, Attendance::STATUS_ABSENT], true)), 'nullable', 'string', 'max:255'],
                ],
                'options' => [
                    'employee_id' => static fn (?Model $record = null, $user = null): array => self::attendanceEmployeeOptions($user),
                ],
                'prepare' => static function (array $data, Request $request, ?Model $record): array {
                    if (! empty($data['employee_id'])) {
                        $data['branch_id'] = Employee::find($data['employee_id'])?->branch_id;
                    }
                    if (in_array($data['status'] ?? null, [...Attendance::EXCUSED_STATUSES, Attendance::STATUS_ABSENT], true)) {
                        $data['check_in_time'] = null;
                        $data['check_out_time'] = null;
                    }
                    $data['recorded_by'] ??= $request->user()?->getKey();

                    return $data;
                },
                'persist' => ['branch_id', 'recorded_by'],
                'after_create' => static function (Model $record): void {
                    if ($period = KpiPeriod::active()) {
                        app(AttendanceKpiSyncService::class)->syncPeriodAttendanceData($period);
                    }
                },
                'after_update' => static function (Model $record): void {
                    if ($period = KpiPeriod::active()) {
                        app(AttendanceKpiSyncService::class)->syncPeriodAttendanceData($period);
                    }
                },
                'after_delete' => static function (Model $record): void {
                    if ($period = KpiPeriod::active()) {
                        app(AttendanceKpiSyncService::class)->syncPeriodAttendanceData($period);
                    }
                },
                'actions' => [
                    'fill_today' => [
                        'scope' => 'header',
                        'label' => 'Tandai hadir hari ini',
                        'variant' => 'outline',
                        'confirm' => 'Tandai semua karyawan aktif pada cabang periode ini hadir hari ini?',
                        'handler' => static function (Request $request, ?Model $record): string {
                            $period = KpiPeriod::active();
                            abort_if(! $period, 422, 'Absensi hanya dapat dicatat saat ada periode KPI OPEN.');

                            $today = now();
                            abort_unless($today->betweenIncluded($period->start_date, $period->end_date), 422, "Hari ini berada di luar periode {$period->name}.");
                            abort_if($today->isWeekend(), 422, 'Hari ini bukan hari kerja. Absensi hanya dicatat Senin sampai Jumat.');

                            $date = $today->toDateString();
                            $employees = self::attendanceEmployeeQuery($period, $request->user())->get();
                            if ($employees->isEmpty()) {
                                return 'Tidak ada karyawan aktif pada cabang peserta periode ini.';
                            }

                            $created = 0;
                            $employees->each(function (Employee $employee) use ($date, &$created, $request): void {
                                if (! Attendance::query()->where('employee_id', $employee->id)->where('attendance_date', $date)->exists()) {
                                    Attendance::create([
                                        'employee_id' => $employee->id,
                                        'branch_id' => $employee->branch_id,
                                        'attendance_date' => $date,
                                        'status' => Attendance::STATUS_PRESENT,
                                        'check_in_time' => now()->format('H:i:s'),
                                        'recorded_by' => $request->user()?->getKey(),
                                    ]);
                                    $created++;
                                }
                            });
                            $syncMessage = app(AttendanceKpiSyncService::class)->syncPeriodAttendanceData($period)['message'];

                            return "{$created} karyawan ditandai hadir. {$syncMessage}";
                        },
                    ],
                    'sync_kpi' => [
                        'scope' => 'header',
                        'label' => 'Sinkronkan ke KPI',
                        'variant' => 'secondary',
                        'confirm' => 'Sinkronkan data absensi ke KPI sekarang?',
                        'handler' => static function (Request $request, ?Model $record): string {
                            $period = KpiPeriod::active();
                            abort_if(! $period, 422, 'Tidak ada periode KPI yang sedang OPEN.');

                            return app(AttendanceKpiSyncService::class)->syncPeriodAttendanceData($period)['message'];
                        },
                    ],
                ],
            ],
            'stock-opnames' => [
                'model' => StockOpname::class,
                'label' => 'Stock Opname',
                'plural_label' => 'Stock Opname Gudang',
                'description' => 'Buat sesi opname, pantau proses hitung, dan sesuaikan stok melalui ledger.',
                'permission' => ['roles' => [], 'positions' => ['POS-GUD']],
                'search' => ['code', 'period.name', 'status'],
                'with' => ['period'],
                'scope' => static function ($query, $user): void {
                    $period = KpiPeriod::active();
                    $period ? $query->where('period_id', $period->getKey()) : $query->whereIn('id', []);
                },
                'with_count' => ['items'],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode', 'emphasis' => true],
                    ['key' => 'period.name', 'label' => 'Periode'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'labels' => [
                        StockOpname::STATUS_DRAFT => 'Draft',
                        StockOpname::STATUS_IN_PROGRESS => 'Sedang Berjalan',
                        StockOpname::STATUS_COMPLETED => 'Selesai',
                    ]],
                    ['key' => 'items_count', 'label' => 'Item'],
                    ['key' => 'deadline', 'label' => 'Deadline', 'type' => 'date'],
                    ['key' => 'completed_at', 'label' => 'Selesai', 'type' => 'datetime', 'placeholder' => '—'],
                ],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode Opname', 'type' => 'text', 'placeholder' => 'Otomatis jika dikosongkan'],
                    ['name' => 'period_id', 'label' => 'Periode KPI', 'type' => 'select', 'required' => true],
                    ['name' => 'deadline', 'label' => 'Deadline Penyelesaian', 'type' => 'date', 'default' => now()->addDays(3)->toDateString()],
                ],
                'rules' => static fn (?StockOpname $record): array => [
                    'code' => ['nullable', 'string', 'max:50', Rule::unique('stock_opnames', 'code')->ignore($record?->getKey())],
                    'period_id' => ['required', 'integer', 'exists:kpi_periods,id'],
                    'deadline' => ['nullable', 'date'],
                ],
                'options' => [
                    'period_id' => static fn (?Model $record = null): array => self::periodOptions(),
                ],
                'prepare' => static function (array $data, Request $request, ?Model $record): array {
                    $data['created_by'] ??= $request->user()?->getKey();
                    $data['status'] ??= StockOpname::STATUS_DRAFT;

                    return $data;
                },
                'persist' => ['created_by', 'status'],
                'after_create' => static function (Model $record, Request $request): void {
                    $opname = $record;
                    if (empty($opname->code)) {
                        $count = StockOpname::query()->whereYear('created_at', date('Y'))->count();
                        $opname->update(['code' => 'OPN-'.date('Ym').'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT)]);
                    }
                    app(StockOpnameService::class)->snapshotItems($opname);
                },
                'before_delete' => static function (Model $record, Request $request): void {
                    app(StockOpnameService::class)->assertDeletable($record);
                },
                'can_delete' => static fn ($user, ?Model $record = null): bool => $record === null || $record->status !== StockOpname::STATUS_COMPLETED,
                'actions' => [
                    'sync_kpi' => [
                        'scope' => 'header',
                        'label' => 'Sinkronkan KPI Gudang',
                        'variant' => 'secondary',
                        'confirm' => 'Sinkronkan hasil stok opname ke KPI sekarang?',
                        'handler' => static function (Request $request, ?Model $record): string {
                            $period = KpiPeriod::active();
                            abort_if(! $period, 422, 'Tidak ada periode KPI yang sedang OPEN.');

                            return app(InventoryKpiSyncService::class)->syncPeriodInventoryData($period)['message'];
                        },
                    ],
                    'complete' => [
                        'scope' => 'row',
                        'label' => 'Selesaikan opname',
                        'variant' => 'default',
                        'confirm' => 'Selesaikan sesi opname dan sesuaikan stok?',
                        'visible' => static fn (Model $record, $user): bool => $record->status !== StockOpname::STATUS_COMPLETED,
                        'handler' => static function (Request $request, Model $record): string {
                            $result = app(StockOpnameService::class)->complete($record, $request->user()?->getKey());
                            $period = $record->fresh('period')?->period;
                            if ($period && $record->fresh()?->status === StockOpname::STATUS_COMPLETED) {
                                $result['message'] .= ' '.app(InventoryKpiSyncService::class)->syncPeriodInventoryData($period)['message'];
                            }

                            return $result['message'];
                        },
                    ],
                ],
            ],
            'admin-work-logs' => [
                'model' => AdminWorkLog::class,
                'label' => 'Work-Log Admin',
                'plural_label' => 'Work-Log Admin',
                'description' => 'Catat akurasi input, kelengkapan dokumen, dan rekonsiliasi administrasi.',
                'permission' => ['roles' => [], 'positions' => ['POS-ADM']],
                'search' => ['employee.name', 'notes'],
                'with' => ['employee', 'period'],
                'scope' => static function ($query, $user): void {
                    $period = KpiPeriod::active();
                    $period ? $query->where('period_id', $period->getKey()) : $query->whereIn('id', []);
                },
                'columns' => [
                    ['key' => 'employee.name', 'label' => 'Admin', 'emphasis' => true],
                    ['key' => 'work_date', 'label' => 'Tanggal', 'type' => 'date'],
                    ['key' => 'records_input', 'label' => 'Record'],
                    ['key' => 'records_corrected', 'label' => 'Koreksi'],
                    ['key' => 'documents_complete', 'label' => 'Dok Lengkap'],
                    ['key' => 'reconciliations_success', 'label' => 'Rekonsiliasi OK'],
                    ['key' => 'period.name', 'label' => 'Periode'],
                ],
                'fields' => [
                    ['name' => 'employee_id', 'label' => 'Admin', 'type' => 'select', 'required' => true],
                    ['name' => 'work_date', 'label' => 'Tanggal Kerja', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d')],
                    ['name' => 'records_input', 'label' => 'Jumlah Record Diinput', 'type' => 'number', 'required' => true],
                    ['name' => 'records_corrected', 'label' => 'Jumlah Kena Koreksi / Error', 'type' => 'number', 'required' => true],
                    ['name' => 'documents_eligible', 'label' => 'Total Dokumen Eligible', 'type' => 'number', 'required' => true],
                    ['name' => 'documents_complete', 'label' => 'Dokumen Lengkap', 'type' => 'number', 'required' => true],
                    ['name' => 'reconciliations_total', 'label' => 'Total Rekonsiliasi', 'type' => 'number', 'required' => true],
                    ['name' => 'reconciliations_success', 'label' => 'Rekonsiliasi Sukses / Sesuai', 'type' => 'number', 'required' => true],
                    ['name' => 'notes', 'label' => 'Catatan', 'type' => 'textarea'],
                ],
                'rules' => static fn (?AdminWorkLog $record): array => [
                    'employee_id' => ['required', 'string', 'exists:employees,id'],
                    'work_date' => ['required', 'date'],
                    'records_input' => ['required', 'integer', 'min:0'],
                    'records_corrected' => ['required', 'integer', 'min:0', 'lte:records_input'],
                    'documents_eligible' => ['required', 'integer', 'min:0'],
                    'documents_complete' => ['required', 'integer', 'min:0', 'lte:documents_eligible'],
                    'reconciliations_total' => ['required', 'integer', 'min:0'],
                    'reconciliations_success' => ['required', 'integer', 'min:0', 'lte:reconciliations_total'],
                    'notes' => ['nullable', 'string', 'max:500'],
                ],
                'options' => [
                    'employee_id' => static fn (?Model $record = null): array => self::employeeOptions($record),
                ],
                'prepare' => static function (array $data, Request $request, ?Model $record): array {
                    $period = KpiPeriod::active();
                    abort_unless($period, 422, 'Tidak ada periode KPI yang sedang OPEN.');
                    $data['period_id'] ??= $period->getKey();
                    $data['recorded_by'] ??= $request->user()?->getKey();

                    return $data;
                },
                'persist' => ['period_id', 'recorded_by'],
                'actions' => [
                    'sync_kpi' => [
                        'scope' => 'header',
                        'label' => 'Sinkronkan ke KPI',
                        'variant' => 'secondary',
                        'confirm' => 'Sinkronkan work-log admin ke KPI sekarang?',
                        'handler' => static function (Request $request, ?Model $record): string {
                            $period = KpiPeriod::active();
                            abort_if(! $period, 422, 'Tidak ada periode KPI yang sedang OPEN.');

                            return app(AdminWorkLogKpiSyncService::class)->syncPeriodWorkLogData($period)['message'];
                        },
                    ],
                ],
            ],
            'complaints' => [
                'model' => Complaint::class,
                'label' => 'Komplain',
                'plural_label' => 'Komplain & Retur',
                'description' => 'Pantau komplain pelanggan, SLA, dan tindak lanjut penyelesaiannya.',
                'permission' => ['roles' => ['owner_manager', 'supervisor'], 'positions' => []],
                'search' => ['code', 'employee.name', 'channel', 'description', 'status'],
                'with' => ['employee', 'serviceTicket'],
                'scope' => static function ($query, $user): void {
                    $period = KpiPeriod::active();
                    if (! $period) {
                        $query->whereIn('id', []);

                        return;
                    }

                    $query->whereBetween('complaint_date', [
                        $period->start_date->toDateString(),
                        $period->end_date->toDateString(),
                    ]);
                },
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode', 'emphasis' => true],
                    ['key' => 'complaint_date', 'label' => 'Tanggal', 'type' => 'date'],
                    ['key' => 'employee.name', 'label' => 'Subjek', 'placeholder' => '—'],
                    ['key' => 'channel', 'label' => 'Kanal', 'type' => 'badge', 'labels' => [
                        'in_store' => 'Di Toko', 'phone' => 'Telepon', 'whatsapp' => 'WhatsApp', 'google_review' => 'Google Review', 'other' => 'Lainnya',
                    ]],
                    ['key' => 'severity', 'label' => 'Severity', 'type' => 'badge', 'labels' => ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi']],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'labels' => [
                        Complaint::STATUS_OPEN => 'Terbuka',
                        Complaint::STATUS_IN_PROGRESS => 'Ditindaklanjuti',
                        Complaint::STATUS_RESOLVED => 'Terselesaikan',
                        Complaint::STATUS_CLOSED => 'Ditutup',
                    ]],
                    ['key' => 'resolved_at', 'label' => 'Selesai', 'type' => 'datetime', 'placeholder' => '—'],
                ],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode Komplain', 'type' => 'text', 'placeholder' => 'Otomatis jika dikosongkan'],
                    ['name' => 'complaint_date', 'label' => 'Tanggal Komplain', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d')],
                    ['name' => 'employee_id', 'label' => 'Karyawan Terkait', 'type' => 'select'],
                    ['name' => 'service_ticket_id', 'label' => 'Tiket Servis Terkait', 'type' => 'select'],
                    ['name' => 'channel', 'label' => 'Kanal', 'type' => 'select', 'required' => true, 'options' => [
                        ['value' => 'in_store', 'label' => 'Di Toko'], ['value' => 'phone', 'label' => 'Telepon'], ['value' => 'whatsapp', 'label' => 'WhatsApp'], ['value' => 'google_review', 'label' => 'Google Review'], ['value' => 'other', 'label' => 'Lainnya'],
                    ]],
                    ['name' => 'category', 'label' => 'Kategori', 'type' => 'select', 'options' => [
                        ['value' => 'service', 'label' => 'Servis'], ['value' => 'product', 'label' => 'Produk / Sparepart'], ['value' => 'cashier', 'label' => 'Kasir'], ['value' => 'general', 'label' => 'Umum'],
                    ]],
                    ['name' => 'severity', 'label' => 'Severity', 'type' => 'select', 'required' => true, 'default' => 'medium', 'options' => [
                        ['value' => 'low', 'label' => 'Rendah'], ['value' => 'medium', 'label' => 'Sedang'], ['value' => 'high', 'label' => 'Tinggi'],
                    ]],
                    ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => Complaint::STATUS_OPEN, 'options' => [
                        ['value' => Complaint::STATUS_OPEN, 'label' => 'Terbuka'], ['value' => Complaint::STATUS_IN_PROGRESS, 'label' => 'Ditindaklanjuti'], ['value' => Complaint::STATUS_RESOLVED, 'label' => 'Terselesaikan'], ['value' => Complaint::STATUS_CLOSED, 'label' => 'Ditutup'],
                    ]],
                    ['name' => 'description', 'label' => 'Deskripsi Komplain', 'type' => 'textarea', 'required' => true],
                    ['name' => 'sla_deadline', 'label' => 'Batas SLA', 'type' => 'datetime-local', 'default' => now()->addDays(3)->format('Y-m-d\\TH:i')],
                    ['name' => 'resolved_at', 'label' => 'Waktu Terselesaikan', 'type' => 'datetime-local'],
                    ['name' => 'resolution_notes', 'label' => 'Catatan Penyelesaian', 'type' => 'textarea'],
                ],
                'rules' => static fn (?Complaint $record): array => [
                    'code' => ['nullable', 'string', 'max:50', Rule::unique('complaints', 'code')->ignore($record?->getKey())],
                    'complaint_date' => ['required', 'date'],
                    'employee_id' => ['nullable', 'string', 'exists:employees,id'],
                    'service_ticket_id' => ['nullable', 'string', 'exists:service_tickets,id'],
                    'channel' => ['required', Rule::in(['in_store', 'phone', 'whatsapp', 'google_review', 'other'])],
                    'category' => ['nullable', Rule::in(['service', 'product', 'cashier', 'general'])],
                    'severity' => ['required', Rule::in(['low', 'medium', 'high'])],
                    'status' => ['required', Rule::in([Complaint::STATUS_OPEN, Complaint::STATUS_IN_PROGRESS, Complaint::STATUS_RESOLVED, Complaint::STATUS_CLOSED])],
                    'description' => ['required', 'string'],
                    'sla_deadline' => ['nullable', 'date'],
                    'resolved_at' => ['nullable', 'date'],
                    'resolution_notes' => ['nullable', 'string'],
                ],
                'options' => [
                    'employee_id' => static fn (?Model $record = null): array => self::employeeOptions($record),
                    'service_ticket_id' => static fn (?Model $record = null): array => self::serviceTicketOptions(),
                ],
                'prepare' => static function (array $data, Request $request, ?Model $record): array {
                    if (empty($data['code'])) {
                        $count = Complaint::query()->whereYear('created_at', date('Y'))->count() + 1;
                        $data['code'] = 'CMP-'.date('Ym').'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
                    }
                    $data['recorded_by'] ??= $request->user()?->getKey();

                    return $data;
                },
                'persist' => ['recorded_by'],
                'actions' => [
                    'resolve' => [
                        'scope' => 'row',
                        'label' => 'Tandai selesai',
                        'variant' => 'default',
                        'confirm' => 'Tandai komplain ini sebagai terselesaikan?',
                        'visible' => static fn (Model $record, $user): bool => ! in_array($record->status, [Complaint::STATUS_RESOLVED, Complaint::STATUS_CLOSED], true),
                        'handler' => static function (Request $request, Model $record): string {
                            $record->update(['status' => Complaint::STATUS_RESOLVED, 'resolved_at' => now()]);

                            return 'Komplain ditandai terselesaikan.';
                        },
                    ],
                    'sync_kpi' => [
                        'scope' => 'header',
                        'label' => 'Sinkronkan ke KPI',
                        'variant' => 'secondary',
                        'confirm' => 'Sinkronkan data komplain ke KPI sekarang?',
                        'handler' => static function (Request $request, ?Model $record): string {
                            $period = KpiPeriod::active();
                            abort_if(! $period, 422, 'Tidak ada periode KPI yang sedang OPEN.');

                            return app(ComplaintKpiSyncService::class)->syncPeriodComplaintData($period)['message'];
                        },
                    ],
                ],
            ],
            'coaching-logs' => [
                'model' => CoachingLog::class,
                'label' => 'Coaching Log',
                'plural_label' => 'Coaching & Evaluasi',
                'description' => 'Catat sesi coaching supervisor dan tindak lanjut perkembangan karyawan.',
                'permission' => ['roles' => ['supervisor'], 'positions' => []],
                'search' => ['supervisor.name', 'employee.name', 'topic', 'notes'],
                'with' => ['supervisor', 'employee', 'period'],
                'scope' => static function ($query, $user): void {
                    $period = KpiPeriod::active();
                    $period ? $query->where('period_id', $period->getKey()) : $query->whereIn('id', []);
                },
                'columns' => [
                    ['key' => 'coaching_date', 'label' => 'Tanggal', 'type' => 'date'],
                    ['key' => 'supervisor.name', 'label' => 'Supervisor'],
                    ['key' => 'employee.name', 'label' => 'Karyawan', 'emphasis' => true],
                    ['key' => 'topic', 'label' => 'Topik'],
                    ['key' => 'target_met', 'label' => 'Target', 'type' => 'boolean'],
                    ['key' => 'follow_up_date', 'label' => 'Follow-up', 'type' => 'date', 'placeholder' => '—'],
                ],
                'fields' => [
                    ['name' => 'supervisor_id', 'label' => 'Supervisor', 'type' => 'select', 'required' => true],
                    ['name' => 'employee_id', 'label' => 'Karyawan yang Dicoach', 'type' => 'select', 'required' => true],
                    ['name' => 'coaching_date', 'label' => 'Tanggal Coaching', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d')],
                    ['name' => 'topic', 'label' => 'Topik / Materi', 'type' => 'text', 'required' => true],
                    ['name' => 'notes', 'label' => 'Catatan & Hasil', 'type' => 'textarea'],
                    ['name' => 'target_met', 'label' => 'Target Coaching Tercapai', 'type' => 'checkbox', 'default' => false],
                    ['name' => 'follow_up_date', 'label' => 'Tanggal Follow-up', 'type' => 'date'],
                ],
                'rules' => static fn (?CoachingLog $record): array => [
                    'supervisor_id' => ['required', 'string', 'exists:employees,id'],
                    'employee_id' => ['required', 'string', 'exists:employees,id'],
                    'coaching_date' => ['required', 'date'],
                    'topic' => ['required', 'string', 'max:150'],
                    'notes' => ['nullable', 'string'],
                    'target_met' => ['required', 'boolean'],
                    'follow_up_date' => ['nullable', 'date'],
                ],
                'options' => [
                    'supervisor_id' => static fn (?Model $record = null): array => self::employeeOptions($record),
                    'employee_id' => static fn (?Model $record = null): array => self::employeeOptions($record),
                ],
                'prepare' => static function (array $data, Request $request, ?Model $record): array {
                    $period = KpiPeriod::active();
                    abort_unless($period, 422, 'Tidak ada periode KPI yang sedang OPEN.');
                    $data['period_id'] ??= $period->getKey();
                    $data['recorded_by'] ??= $request->user()?->getKey();

                    return $data;
                },
                'persist' => ['period_id', 'recorded_by'],
                'actions' => [
                    'sync_kpi' => [
                        'scope' => 'header',
                        'label' => 'Sinkronkan ke KPI',
                        'variant' => 'secondary',
                        'confirm' => 'Sinkronkan data coaching ke KPI sekarang?',
                        'handler' => static function (Request $request, ?Model $record): string {
                            $period = KpiPeriod::active();
                            abort_if(! $period, 422, 'Tidak ada periode KPI yang sedang OPEN.');

                            return app(CoachingKpiSyncService::class)->syncPeriodCoachingData($period)['message'];
                        },
                    ],
                ],
            ],
            'customer-feedback' => [
                'model' => CustomerFeedback::class,
                'label' => 'Feedback Pelanggan',
                'plural_label' => 'Kepuasan Pelanggan',
                'description' => 'Lihat feedback pelanggan yang masuk melalui link resmi setelah serah terima.',
                'permission' => ['roles' => ['owner_manager', 'supervisor'], 'positions' => ['POS-CS']],
                'search' => ['ticket.ticket_number', 'customer_name', 'comments'],
                'with' => ['ticket', 'csEmployee'],
                'columns' => [
                    ['key' => 'ticket.ticket_number', 'label' => 'No. Tiket', 'emphasis' => true],
                    ['key' => 'customer_name', 'label' => 'Pelanggan'],
                    ['key' => 'rating', 'label' => 'Rating', 'type' => 'badge', 'prefix' => '★ ', 'suffix' => ' / 5'],
                    ['key' => 'csEmployee.name', 'label' => 'Petugas Pelayan', 'type' => 'badge'],
                    ['key' => 'comments', 'label' => 'Ulasan', 'placeholder' => '—'],
                    ['key' => 'created_at', 'label' => 'Tanggal', 'type' => 'datetime'],
                ],
                'fields' => [
                    ['name' => 'service_ticket_id', 'label' => 'Tiket Servis Terkait', 'type' => 'select', 'required' => true],
                    ['name' => 'cs_employee_id', 'label' => 'Petugas Pelayan', 'type' => 'select', 'required' => true],
                    ['name' => 'customer_name', 'label' => 'Nama Pelanggan', 'type' => 'text', 'required' => true],
                    ['name' => 'rating', 'label' => 'Rating Bintang', 'type' => 'select', 'required' => true, 'default' => '5', 'options' => [
                        ['value' => '5', 'label' => '★★★★★ 5 — Sangat Puas'], ['value' => '4', 'label' => '★★★★☆ 4 — Puas'], ['value' => '3', 'label' => '★★★☆☆ 3 — Cukup'], ['value' => '2', 'label' => '★★☆☆☆ 2 — Kurang Puas'], ['value' => '1', 'label' => '★☆☆☆☆ 1 — Kecewa'],
                    ]],
                    ['name' => 'comments', 'label' => 'Ulasan / Komentar Pelanggan', 'type' => 'textarea'],
                ],
                'rules' => static fn (?CustomerFeedback $record): array => [
                    'service_ticket_id' => ['required', 'string', 'exists:service_tickets,id'],
                    'cs_employee_id' => ['required', 'string', 'exists:employees,id'],
                    'customer_name' => ['required', 'string', 'max:150'],
                    'rating' => ['required', 'integer', 'between:1,5'],
                    'comments' => ['nullable', 'string'],
                ],
                'options' => [
                    'service_ticket_id' => static fn (?Model $record = null): array => self::serviceTicketOptions(),
                    'cs_employee_id' => static fn (?Model $record = null): array => self::employeeOptions($record, null, 'POS-CS'),
                ],
                'can_delete' => false,
                'can_create' => false,
                'can_edit' => false,
            ],
            'service-tickets' => [
                'model' => ServiceTicket::class,
                'label' => 'Tiket Servis',
                'plural_label' => 'Tiket Servis HP',
                'description' => 'Buat dan kelola alur penerimaan, pengerjaan, QC, serta penyerahan perangkat pelanggan.',
                'permission' => ['roles' => ['owner_manager', 'supervisor'], 'positions' => ['POS-CS', 'POS-KSR', 'POS-GUD']],
                'search' => ['ticket_number', 'customer_name', 'customer_phone', 'device_brand', 'device_model', 'status'],
                'with' => ['intakeEmployee', 'cashierEmployee', 'technicianEmployee', 'branch', 'period'],
                'columns' => [
                    ['key' => 'ticket_number', 'label' => 'No. Tiket', 'emphasis' => true],
                    ['key' => 'customer_name', 'label' => 'Pelanggan'],
                    ['key' => 'device_brand', 'label' => 'Perangkat'],
                    ['key' => 'intakeEmployee.name', 'label' => 'Pelayan', 'type' => 'badge', 'placeholder' => 'Belum dicatat'],
                    ['key' => 'technicianEmployee.name', 'label' => 'Teknisi', 'type' => 'badge', 'placeholder' => 'Belum ditugaskan'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'labels' => [
                        'intake' => 'Diterima Pelayan', 'diagnosing' => 'Diagnosa', 'waiting_sparepart' => 'Tunggu Part', 'in_progress' => 'Dikerjakan', 'qc_ready' => 'Siap QC', 'completed' => 'Selesai', 'delivered' => 'Diserahkan', 'cancelled' => 'Dibatalkan',
                    ]],
                    ['key' => 'result_status', 'label' => 'Hasil', 'type' => 'badge', 'labels' => [
                        'pending' => 'Menunggu Hasil', 'success' => 'Berhasil', 'unrepairable' => 'Tidak Dapat Diperbaiki', 'customer_declined' => 'Customer Menolak Servis',
                    ]],
                    ['key' => 'created_at', 'label' => 'Tgl Masuk', 'type' => 'datetime'],
                ],
                'fields' => [],
                'rules' => static fn (?ServiceTicket $record): array => [],
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
            ],
            'spareparts' => [
                'model' => Sparepart::class,
                'label' => 'Produk',
                'plural_label' => 'Katalog Produk & Stok',
                'description' => 'Kelola katalog produk, batas stok minimum, dan restok melalui ledger mutasi.',
                'permission' => ['roles' => [], 'positions' => ['POS-GUD']],
                'search' => ['code', 'name', 'category', 'compatible_models'],
                'with' => ['branch'],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode', 'emphasis' => true],
                    ['key' => 'name', 'label' => 'Nama Produk'],
                    ['key' => 'product_type', 'label' => 'Jenis', 'type' => 'badge', 'labels' => Sparepart::TYPES],
                    ['key' => 'category', 'label' => 'Kategori', 'type' => 'badge'],
                    ['key' => 'stock_quantity', 'label' => 'Stok'],
                    ['key' => 'is_critical', 'label' => 'Kritis', 'type' => 'boolean'],
                    ['key' => 'selling_price', 'label' => 'Harga Jual', 'type' => 'money'],
                ],
                'fields' => [
                    ['name' => 'branch_id', 'label' => 'Cabang', 'type' => 'select'],
                    ['name' => 'code', 'label' => 'Kode Produk', 'type' => 'text', 'required' => true],
                    ['name' => 'product_type', 'label' => 'Jenis Produk', 'type' => 'select', 'required' => true, 'default' => Sparepart::TYPE_SPAREPART, 'options' => array_map(static fn (string $label, string $value): array => ['value' => $value, 'label' => $label], Sparepart::TYPES, array_keys(Sparepart::TYPES))],
                    ['name' => 'name', 'label' => 'Nama Produk', 'type' => 'text', 'required' => true],
                    ['name' => 'category', 'label' => 'Kategori', 'type' => 'text', 'required' => true],
                    ['name' => 'compatible_models', 'label' => 'Model Kompatibel', 'type' => 'text'],
                    ['name' => 'stock_quantity', 'label' => 'Stok Saat Ini', 'type' => 'number', 'readOnly' => true],
                    ['name' => 'min_stock_alert', 'label' => 'Batas Minimum Stok', 'type' => 'number', 'required' => true, 'default' => 5],
                    ['name' => 'purchase_price', 'label' => 'Harga Beli / Modal (Rp)', 'type' => 'number', 'default' => 0],
                    ['name' => 'selling_price', 'label' => 'Harga Jual (Rp)', 'type' => 'number', 'default' => 0],
                    ['name' => 'is_critical', 'label' => 'Produk Kritis', 'type' => 'checkbox', 'default' => false],
                ],
                'rules' => static fn (?Sparepart $record): array => [
                    'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                    'code' => ['required', 'string', 'max:50', Rule::unique('spareparts', 'code')->ignore($record?->getKey())],
                    'product_type' => ['required', Rule::in(array_keys(Sparepart::TYPES))],
                    'name' => ['required', 'string', 'max:150'],
                    'category' => ['required', 'string', 'max:100'],
                    'compatible_models' => ['nullable', 'string'],
                    'min_stock_alert' => ['required', 'integer', 'min:0'],
                    'purchase_price' => ['nullable', 'numeric', 'min:0'],
                    'selling_price' => ['nullable', 'numeric', 'min:0'],
                    'is_critical' => ['required', 'boolean'],
                ],
                'options' => [
                    'branch_id' => static fn (?Model $record = null): array => self::branchOptions(),
                ],
                'scope' => static function ($query, $user): void {
                    if ($user && ! $user->hasAnyRole(['owner_manager', 'super_admin'])) {
                        $query->where(fn ($scope) => $scope->where('branch_id', $user->employee?->branch_id)->orWhereNull('branch_id'));
                    }
                },
                'can_edit' => static function ($user, ?Model $record = null): bool {
                    if (! $record || ! $user || $user->hasAnyRole(['owner_manager', 'super_admin'])) {
                        return true;
                    }

                    return $record->branch_id === null || (string) $record->branch_id === (string) $user->employee?->branch_id;
                },
                'can_delete' => false,
                'actions' => [
                    'restock' => [
                        'scope' => 'row',
                        'label' => 'Restok',
                        'variant' => 'default',
                        'prompt' => ['name' => 'quantity', 'label' => 'Jumlah stok yang masuk'],
                        'handler' => static function (Request $request, Model $record): string {
                            $data = $request->validate(['quantity' => ['required', 'integer', 'min:1']]);
                            $updated = app(StockOpnameService::class)->restock($record, $data['quantity'], null, $request->user()?->getKey());

                            return "Stok {$updated->name} bertambah {$data['quantity']} (total {$updated->stock_quantity}).";
                        },
                    ],
                ],
            ],
            'kpi-definitions' => [
                'model' => KpiDefinition::class,
                'label' => 'Definisi Indikator',
                'plural_label' => 'Katalog Indikator KPI',
                'description' => 'Kelola indikator, arah target, formula, dan sumber data KPI.',
                'permission' => ['roles' => ['super_admin'], 'positions' => []],
                'search' => ['code', 'name', 'metric_type', 'source_type'],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode', 'emphasis' => true],
                    ['key' => 'name', 'label' => 'Nama Indikator'],
                    ['key' => 'direction', 'label' => 'Arah Target', 'type' => 'badge', 'labels' => ['higher' => 'Higher is Better', 'lower' => 'Lower is Better', 'zero_tolerance' => 'Zero Tolerance']],
                    ['key' => 'source_type', 'label' => 'Sumber Data', 'type' => 'badge', 'labels' => ['employee' => 'Input Karyawan', 'supervisor' => 'Observasi Supervisor', 'cross_role' => 'Cross-Role', 'import' => 'Import Kasir', 'system' => 'Sistem Otomatis']],
                    ['key' => 'unit', 'label' => 'Satuan'],
                    ['key' => 'is_active', 'label' => 'Status', 'type' => 'boolean'],
                ],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode Indikator', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: TEK-01'],
                    ['name' => 'name', 'label' => 'Nama Indikator', 'type' => 'text', 'required' => true],
                    ['name' => 'metric_type', 'label' => 'Tipe Metrik', 'type' => 'select', 'required' => true, 'default' => 'percentage', 'options' => [
                        ['value' => 'percentage', 'label' => 'Persentase (%)'], ['value' => 'count', 'label' => 'Jumlah / Unit'], ['value' => 'currency', 'label' => 'Mata Uang (Rp)'], ['value' => 'rubric', 'label' => 'Rubrik / Checklist'], ['value' => 'time', 'label' => 'Waktu / Durasi'],
                    ]],
                    ['name' => 'unit', 'label' => 'Satuan', 'type' => 'text', 'required' => true, 'default' => '%'],
                    ['name' => 'direction', 'label' => 'Arah Target', 'type' => 'select', 'required' => true, 'default' => 'higher', 'options' => [
                        ['value' => 'higher', 'label' => 'Higher is Better'], ['value' => 'lower', 'label' => 'Lower is Better'], ['value' => 'zero_tolerance', 'label' => 'Zero Tolerance'],
                    ]],
                    ['name' => 'default_formula', 'label' => 'Formula Kalkulasi', 'type' => 'select', 'required' => true, 'default' => 'higher_is_better', 'options' => [
                        ['value' => 'higher_is_better', 'label' => 'Higher is Better'], ['value' => 'lower_is_better', 'label' => 'Lower is Better'], ['value' => 'zero_tolerance', 'label' => 'Zero Tolerance'], ['value' => 'rubric', 'label' => 'Rubrik'],
                    ]],
                    ['name' => 'source_type', 'label' => 'Sumber Data', 'type' => 'select', 'required' => true, 'default' => 'system', 'options' => [
                        ['value' => 'system', 'label' => 'Sistem Otomatis'], ['value' => 'supervisor', 'label' => 'Observasi Supervisor'], ['value' => 'import', 'label' => 'Import sistem'],
                    ]],
                    ['name' => 'description', 'label' => 'Deskripsi & Petunjuk', 'type' => 'textarea'],
                    ['name' => 'is_active', 'label' => 'Indikator Aktif', 'type' => 'checkbox', 'default' => true],
                ],
                'rules' => static fn (?KpiDefinition $record): array => [
                    'code' => ['required', 'string', 'max:50', Rule::unique('kpi_definitions', 'code')->ignore($record?->getKey())],
                    'name' => ['required', 'string', 'max:150'],
                    'metric_type' => ['required', Rule::in(['percentage', 'count', 'currency', 'rubric', 'time'])],
                    'unit' => ['required', 'string', 'max:30'],
                    'direction' => ['required', Rule::in(['higher', 'lower', 'zero_tolerance'])],
                    'default_formula' => ['required', Rule::in(['higher_is_better', 'lower_is_better', 'zero_tolerance', 'rubric'])],
                    'source_type' => ['required', Rule::in(['supervisor', 'import', 'system'])],
                    'description' => ['nullable', 'string'],
                    'is_active' => ['required', 'boolean'],
                ],
            ],
            'kpi-rating-bands' => [
                'model' => KpiRatingBand::class,
                'label' => 'Skala Predikat',
                'plural_label' => 'Konversi Predikat KPI',
                'description' => 'Atur nilai persen yang dipakai saat Supervisor atau Manager memilih predikat penilaian.',
                'permission' => ['roles' => ['super_admin'], 'positions' => []],
                'search' => ['code', 'label', 'scheme.name'],
                'with' => ['scheme'],
                'order_by' => 'sort_order',
                'columns' => [
                    ['key' => 'scheme.name', 'label' => 'Skema'],
                    ['key' => 'label', 'label' => 'Predikat', 'emphasis' => true],
                    ['key' => 'manual_score', 'label' => 'Nilai Input (%)', 'suffix' => '%', 'placeholder' => 'Tidak dipakai'],
                    ['key' => 'min_score', 'label' => 'Batas Rating', 'suffix' => '%'],
                    ['key' => 'max_score', 'label' => 's/d', 'suffix' => '%'],
                ],
                'fields' => [
                    ['name' => 'rating_scheme_id', 'label' => 'Skema Rating', 'type' => 'select', 'required' => true],
                    ['name' => 'code', 'label' => 'Kode', 'type' => 'text', 'required' => true],
                    ['name' => 'label', 'label' => 'Label Predikat', 'type' => 'text', 'required' => true],
                    ['name' => 'manual_score', 'label' => 'Nilai Saat Dipilih (%)', 'type' => 'number', 'help' => 'Kosongkan jika predikat tidak dipakai pada review manual.'],
                    ['name' => 'min_score', 'label' => 'Nilai Minimum Rating (%)', 'type' => 'number', 'required' => true],
                    ['name' => 'max_score', 'label' => 'Nilai Maksimum Rating (%)', 'type' => 'number', 'required' => true],
                    ['name' => 'color', 'label' => 'Warna Badge', 'type' => 'text', 'required' => true, 'default' => '#10B981'],
                    ['name' => 'badge_icon', 'label' => 'Ikon Badge', 'type' => 'text'],
                    ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'required' => true, 'default' => 1],
                ],
                'rules' => static fn (?KpiRatingBand $record): array => [
                    'rating_scheme_id' => ['required', 'integer', Rule::exists('kpi_rating_schemes', 'id')->where('is_active', false)],
                    'code' => ['required', 'string', 'max:50'],
                    'label' => ['required', 'string', 'max:100'],
                    'manual_score' => ['nullable', 'numeric', 'between:0,100'],
                    'min_score' => ['required', 'numeric', 'between:0,100'],
                    'max_score' => ['required', 'numeric', 'between:0,100', 'gte:min_score'],
                    'color' => ['required', 'string', 'max:30'],
                    'badge_icon' => ['nullable', 'string', 'max:50'],
                    'sort_order' => ['required', 'integer', 'min:1'],
                ],
                'options' => [
                    'rating_scheme_id' => static fn (?Model $record = null): array => KpiRatingScheme::query()->where('is_active', false)
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn (KpiRatingScheme $scheme): array => ['value' => $scheme->id, 'label' => $scheme->name])
                        ->values()
                        ->all(),
                ],
                'can_edit' => static fn ($user, ?Model $record = null): bool => ! $record?->scheme?->is_active,
                'can_delete' => false,
            ],
            'kpi-templates' => [
                'model' => KpiTemplate::class,
                'label' => 'Template KPI',
                'plural_label' => 'Template KPI Jabatan',
                'description' => 'Atur template KPI berdasarkan jabatan dan status aktifnya.',
                'permission' => ['roles' => ['super_admin'], 'positions' => []],
                'search' => ['code', 'name', 'position.name'],
                'with' => ['position', 'activeVersion'],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode', 'emphasis' => true],
                    ['key' => 'name', 'label' => 'Nama Template'],
                    ['key' => 'position.name', 'label' => 'Jabatan', 'type' => 'badge'],
                    ['key' => 'activeVersion.version_number', 'label' => 'Versi Aktif', 'prefix' => 'v', 'placeholder' => 'Belum ada'],
                    ['key' => 'activeVersion.total_weight', 'label' => 'Total Bobot', 'suffix' => '%'],
                    ['key' => 'is_active', 'label' => 'Status', 'type' => 'boolean'],
                ],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode Template', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: TPL-TEK-01'],
                    ['name' => 'name', 'label' => 'Nama Template', 'type' => 'text', 'required' => true],
                    ['name' => 'position_id', 'label' => 'Jabatan Sasaran', 'type' => 'select', 'required' => true],
                    ['name' => 'is_active', 'label' => 'Template Aktif', 'type' => 'checkbox', 'default' => true],
                ],
                'rules' => static fn (?KpiTemplate $record): array => [
                    'code' => ['required', 'string', 'max:50', Rule::unique('kpi_templates', 'code')->ignore($record?->getKey())],
                    'name' => ['required', 'string', 'max:150'],
                    'position_id' => ['required', 'integer', 'exists:positions,id'],
                    'is_active' => ['required', 'boolean'],
                ],
                'options' => [
                    'position_id' => static fn (?Model $record = null): array => self::positionOptions(),
                ],
            ],
            'kpi-periods' => [
                'model' => KpiPeriod::class,
                'label' => 'Periode KPI',
                'plural_label' => 'Periode Penilaian',
                'description' => 'Buat periode penilaian, atur deadline, dan kelola cakupan cabang.',
                'permission' => ['roles' => ['owner_manager', 'super_admin'], 'positions' => []],
                'search' => ['name', 'status'],
                'with' => ['branches'],
                'columns' => [
                    ['key' => 'name', 'label' => 'Nama Periode', 'emphasis' => true],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'labels' => [
                        'DRAFT' => 'Draft', 'READY' => 'Siap Dibuka', 'OPEN' => 'Terbuka', 'IN_REVIEW' => 'Dalam Review', 'WAITING_APPROVAL' => 'Menunggu Approval', 'SUBMISSION_CLOSED' => 'Input Ditutup', 'PUBLISHED' => 'Dipublish', 'LOCKED' => 'Terkunci',
                    ]],
                    ['key' => 'total_eligible_employees', 'label' => 'Karyawan Eligible'],
                    ['key' => 'submission_deadline', 'label' => 'Deadline Input', 'type' => 'datetime'],
                    ['key' => 'approval_deadline', 'label' => 'Deadline Final', 'type' => 'datetime'],
                ],
                'fields' => [
                    ['name' => 'name', 'label' => 'Nama Periode', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: Periode Agustus 2026'],
                    ['name' => 'year', 'label' => 'Tahun', 'type' => 'number', 'required' => true, 'default' => (int) date('Y')],
                    ['name' => 'month', 'label' => 'Bulan', 'type' => 'select', 'required' => true, 'default' => (string) date('n'), 'options' => self::monthOptions()],
                    ['name' => 'start_date', 'label' => 'Tanggal Mulai', 'type' => 'date', 'required' => true, 'default' => now()->startOfMonth()->toDateString()],
                    ['name' => 'end_date', 'label' => 'Tanggal Selesai', 'type' => 'date', 'required' => true, 'default' => now()->endOfMonth()->toDateString()],
                    ['name' => 'submission_deadline', 'label' => 'Batas Input Karyawan', 'type' => 'datetime-local', 'required' => true],
                    ['name' => 'review_deadline', 'label' => 'Batas Review Supervisor', 'type' => 'datetime-local', 'required' => true],
                    ['name' => 'approval_deadline', 'label' => 'Batas Approval Manager', 'type' => 'datetime-local', 'required' => true],
                    ['name' => 'branches', 'label' => 'Cabang Berpartisipasi', 'type' => 'checkbox-list', 'required' => true],
                ],
                'rules' => static fn (?KpiPeriod $record): array => [
                    'name' => ['required', 'string', 'max:100'],
                    'year' => ['required', 'integer', 'between:2000,2100'],
                    'month' => ['required', 'integer', 'between:1,12'],
                    'start_date' => ['required', 'date'],
                    'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                    'submission_deadline' => ['required', 'date'],
                    'review_deadline' => ['required', 'date', 'after:submission_deadline'],
                    'approval_deadline' => ['required', 'date', 'after:review_deadline'],
                    'branches' => ['required', 'array', 'min:1'],
                    'branches.*' => ['integer', 'exists:branches,id'],
                ],
                'can_delete' => false,
                'options' => [
                    'branches' => static fn (?Model $record = null): array => self::branchOptions(),
                ],
                'relationships' => ['branches' => 'branches'],
                'prepare' => static function (array $data, Request $request, ?Model $record): array {
                    $data['created_by'] ??= $request->user()?->getKey();

                    return $data;
                },
                'persist' => ['created_by'],
                'actions' => [
                    'validate' => [
                        'scope' => 'row', 'label' => 'Cek kesiapan', 'variant' => 'outline',
                        'handler' => static function (Request $request, Model $record): array {
                            $readiness = app(PeriodService::class)->validateReadiness($record);

                            return $readiness['is_ready']
                                ? ['message' => "Periode siap dibuka. {$readiness['eligible_count']} karyawan eligible."]
                                : ['error' => implode(' ', $readiness['issues'])];
                        },
                    ],
                    'ready' => [
                        'scope' => 'row', 'label' => 'Jadikan READY', 'variant' => 'outline', 'confirm' => 'Validasi dan buat snapshot periode?',
                        'visible' => static fn (Model $record): bool => $record->status === 'DRAFT',
                        'handler' => static function (Request $request, Model $record): string {
                            app(PeriodService::class)->markReady($record);

                            return 'Periode READY dan snapshot historis telah dibuat.';
                        },
                    ],
                    'open' => [
                        'scope' => 'row', 'label' => 'Buka periode', 'variant' => 'default', 'confirm' => 'Buka periode dan buat snapshot KPI?',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'READY',
                        'handler' => static function (Request $request, Model $record): string {
                            app(PeriodService::class)->openPeriod($record);

                            return 'Periode berhasil dibuka.';
                        },
                    ],
                    'close_submission' => [
                        'scope' => 'row', 'label' => 'Tutup input', 'variant' => 'outline', 'confirm' => 'Tutup pengisian periode ini?',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'OPEN',
                        'handler' => static function (Request $request, Model $record): string {
                            app(PeriodService::class)->closeSubmission($record);

                            return 'Pengisian periode ditutup.';
                        },
                    ],
                    'start_review' => [
                        'scope' => 'row', 'label' => 'Mulai review', 'variant' => 'outline', 'confirm' => 'Mulai review Supervisor untuk periode ini?',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'SUBMISSION_CLOSED',
                        'handler' => static function (Request $request, Model $record): string {
                            app(PeriodService::class)->startReview($record);

                            return 'Periode masuk tahap review.';
                        },
                    ],
                    'start_approval' => [
                        'scope' => 'row', 'label' => 'Mulai approval', 'variant' => 'outline', 'confirm' => 'Teruskan periode ke approval Manager?',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'IN_REVIEW',
                        'handler' => static function (Request $request, Model $record): string {
                            app(PeriodService::class)->startApproval($record);

                            return 'Periode menunggu approval Manager.';
                        },
                    ],
                    'publish' => [
                        'scope' => 'row', 'label' => 'Publish hasil', 'variant' => 'outline', 'confirm' => 'Publish hasil periode ini?',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'WAITING_APPROVAL',
                        'handler' => static function (Request $request, Model $record): string {
                            app(PeriodService::class)->publishPeriod($record);

                            return 'Hasil periode telah dipublish.';
                        },
                    ],
                    'lock' => [
                        'scope' => 'row', 'label' => 'Kunci final', 'variant' => 'secondary', 'confirm' => 'Kunci periode ini sebagai final?',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'PUBLISHED',
                        'handler' => static function (Request $request, Model $record): string {
                            app(PeriodService::class)->lockPeriod($record);

                            return 'Periode berhasil dikunci.';
                        },
                    ],
                ],
            ],
            'employee-kpis' => [
                'model' => EmployeeKpi::class,
                'label' => 'Penilaian Karyawan',
                'plural_label' => 'Penilaian & Review KPI',
                'description' => 'Pantau progres, skor, predikat, dan alur approval KPI karyawan.',
                'permission' => ['roles' => ['owner_manager', 'super_admin'], 'positions' => []],
                'search' => ['employee.name', 'period.name', 'status', 'rating_label'],
                'with' => ['employee.position', 'period'],
                'columns' => [
                    ['key' => 'employee.name', 'label' => 'Nama Karyawan', 'emphasis' => true],
                    ['key' => 'employee.position.name', 'label' => 'Jabatan', 'type' => 'badge'],
                    ['key' => 'period.name', 'label' => 'Periode'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'labels' => [
                        'draft' => 'Draft', 'submitted' => 'Menunggu Review', 'under_review' => 'Sedang Direview', 'revision_required' => 'Perlu Revisi', 'verified' => 'Terverifikasi', 'pending_approval' => 'Menunggu Approval', 'approved' => 'Disetujui', 'locked' => 'Terkunci (Final)',
                    ]],
                    ['key' => 'progress_percentage', 'label' => 'Progress', 'suffix' => '%'],
                    ['key' => 'final_score', 'label' => 'Skor Akhir', 'type' => 'decimal'],
                    ['key' => 'rating_label', 'label' => 'Predikat', 'type' => 'badge', 'placeholder' => 'Belum ada'],
                    ['key' => 'revision_number', 'label' => 'Revisi Ke-'],
                ],
                'fields' => [],
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
                'rules' => static fn (?EmployeeKpi $record): array => [],
                'actions' => [
                    'assess' => [
                        'scope' => 'row', 'label' => 'Buka penilaian Manager', 'variant' => 'default', 'type' => 'link',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'pending_approval' && KpiWorkflow::canManageKpi($user, $record),
                        'record_url' => '/app/employee-kpis/:record/assessment',
                    ],
                    'approve' => [
                        'scope' => 'row', 'label' => 'Approve final', 'variant' => 'default', 'confirm' => 'Setujui dan kunci KPI ini?',
                        'visible' => static fn (Model $record, $user): bool => in_array($record->status, ['pending_approval', 'verified'], true) && KpiWorkflow::canApproveKpi($user, $record),
                        'handler' => static fn (Request $request, Model $record): string => app(ApprovalService::class)->approve($record, null, $request->user()?->getKey())['message'],
                    ],
                    'return_to_supervisor' => [
                        'scope' => 'row', 'label' => 'Kembalikan', 'variant' => 'destructive',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'pending_approval' && KpiWorkflow::canApproveKpi($user, $record),
                        'prompt' => ['name' => 'reason', 'label' => 'Alasan pengembalian ke Supervisor'],
                        'handler' => static function (Request $request, Model $record): string {
                            $data = $request->validate(['reason' => ['required', 'string', 'min:3']]);

                            return app(ApprovalService::class)->return($record, $data['reason'], $request->user()?->getKey())['message'];
                        },
                    ],
                    'request_correction' => [
                        'scope' => 'row', 'label' => 'Ajukan koreksi', 'variant' => 'outline', 'type' => 'link',
                        'visible' => static fn (Model $record, $user): bool => in_array($record->status, ['approved', 'locked'], true) && KpiWorkflow::canRequestCorrection($user, $record),
                        'record_url' => '/app/employee-kpis/:record/correction',
                    ],
                ],
            ],
            'kpi-correction-requests' => [
                'model' => KpiCorrectionRequest::class,
                'label' => 'Permintaan Koreksi',
                'plural_label' => 'Koreksi KPI (Locked)',
                'description' => 'Tinjau permintaan koreksi KPI terkunci dengan dual authorization.',
                'permission' => ['roles' => ['owner_manager', 'super_admin'], 'positions' => []],
                'search' => ['employeeKpi.employee.name', 'employeeKpi.period.name', 'reason', 'status'],
                'with' => ['employeeKpi.employee', 'employeeKpi.period', 'requester'],
                'columns' => [
                    ['key' => 'employeeKpi.employee.name', 'label' => 'Karyawan', 'emphasis' => true],
                    ['key' => 'employeeKpi.period.name', 'label' => 'Periode'],
                    ['key' => 'reason', 'label' => 'Alasan'],
                    ['key' => 'requester.name', 'label' => 'Diajukan Oleh'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'labels' => ['pending' => 'Menunggu Persetujuan', 'applied' => 'Telah Diterapkan', 'rejected' => 'Ditolak']],
                    ['key' => 'applied_at', 'label' => 'Diterapkan', 'type' => 'datetime', 'placeholder' => '—'],
                ],
                'fields' => [],
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
                'rules' => static fn (?KpiCorrectionRequest $record): array => [],
                'actions' => [
                    'approve' => [
                        'scope' => 'row', 'label' => 'Setujui & terapkan', 'variant' => 'default', 'confirm' => 'Terapkan koreksi dan hitung ulang KPI?',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'pending' && KpiWorkflow::canApproveCorrection($user, $record),
                        'handler' => static function (Request $request, Model $record): string {
                            app(ApprovalService::class)->approveCorrection($record, $request->user()?->getKey());

                            return 'Koreksi diterapkan dan KPI diperbarui.';
                        },
                    ],
                    'reject' => [
                        'scope' => 'row', 'label' => 'Tolak', 'variant' => 'destructive',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'pending' && KpiWorkflow::canApproveCorrection($user, $record),
                        'prompt' => ['name' => 'reason', 'label' => 'Alasan penolakan (opsional)'],
                        'handler' => static function (Request $request, Model $record): string {
                            $reason = $request->input('reason');
                            app(ApprovalService::class)->rejectCorrection($record, $request->user()?->getKey(), $reason);

                            return 'Permintaan koreksi ditolak.';
                        },
                    ],
                ],
            ],
            'import-batches' => [
                'model' => ImportBatch::class,
                'label' => 'Batch Import Kasir',
                'plural_label' => 'Import Laporan Kasir',
                'description' => 'Pantau hasil staging laporan kasir sebelum transaksi diterapkan ke KPI.',
                'permission' => ['roles' => [], 'positions' => ['POS-KSR']],
                'search' => ['file_name', 'period.name', 'status'],
                'with' => ['period'],
                'scope' => static function ($query, $user): void {
                    $period = KpiPeriod::active();
                    $period ? $query->where('period_id', $period->getKey()) : $query->whereIn('id', []);
                },
                'columns' => [
                    ['key' => 'file_name', 'label' => 'Nama File', 'emphasis' => true],
                    ['key' => 'period.name', 'label' => 'Periode'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'labels' => ['uploaded' => 'Terunggah', 'parsing' => 'Menganalisis', 'ready_for_preview' => 'Siap Preview', 'confirmed' => 'Dikonfirmasi', 'failed' => 'Gagal']],
                    ['key' => 'total_rows', 'label' => 'Total Baris'],
                    ['key' => 'valid_rows', 'label' => 'Valid'],
                    ['key' => 'duplicate_rows', 'label' => 'Duplikat'],
                    ['key' => 'error_rows', 'label' => 'Error'],
                    ['key' => 'created_at', 'label' => 'Diunggah Pada', 'type' => 'datetime'],
                ],
                'fields' => [],
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
                'rules' => static fn (?ImportBatch $record): array => [],
                'actions' => [
                    'upload_report' => [
                        'scope' => 'header',
                        'type' => 'link',
                        'label' => 'Upload laporan kasir',
                        'variant' => 'default',
                        'url' => '/app/import-batches/upload',
                    ],
                    'confirm' => [
                        'scope' => 'row', 'label' => 'Konfirmasi & terapkan', 'variant' => 'default', 'confirm' => 'Terapkan seluruh transaksi dan perbarui KPI Kasir?',
                        'visible' => static fn (Model $record, $user): bool => $record->status === 'ready_for_preview',
                        'handler' => static function (Request $request, Model $record): string {
                            return app(CashierImportService::class)->confirmAndCommit($record)['message'];
                        },
                    ],
                ],
            ],
            'supervisor-reviews' => [
                'model' => EmployeeKpi::class,
                'label' => 'Review KPI',
                'plural_label' => 'Review KPI Tim',
                'description' => 'Verifikasi indikator KPI tim dan teruskan hasilnya ke antrean approval manager.',
                'permission' => ['roles' => ['supervisor', 'super_admin'], 'positions' => []],
                'search' => ['employee.name', 'period.name', 'status'],
                'with' => ['employee.position', 'period'],
                'scope' => static function ($query, $user): void {
                    if (! $user || $user->hasRole('super_admin') || ! $user->hasRole('supervisor')) {
                        $query->whereIn('id', []);

                        return;
                    }

                    $query->whereIn('status', ['submitted', 'under_review'])
                        ->where('supervisor_id_snapshot', $user?->employee?->id);
                },
                'columns' => [
                    ['key' => 'employee.name', 'label' => 'Karyawan', 'emphasis' => true],
                    ['key' => 'employee.position.name', 'label' => 'Jabatan'],
                    ['key' => 'period.name', 'label' => 'Periode'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'labels' => ['submitted' => 'Menunggu Review', 'under_review' => 'Sedang Direview']],
                    ['key' => 'progress_percentage', 'label' => 'Progress', 'suffix' => '%'],
                ],
                'fields' => [],
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
                'rules' => static fn (?EmployeeKpi $record): array => [],
                'actions' => [
                    'review' => [
                        'scope' => 'row', 'label' => 'Buka review', 'variant' => 'default', 'type' => 'link',
                        'visible' => static fn (Model $record, $user): bool => KpiWorkflow::canReviewKpi($user, $record),
                        'record_url' => '/app/supervisor-reviews/:record/review',
                    ],
                ],
            ],
            'audit-events' => [
                'model' => AuditEvent::class,
                'label' => 'Log Audit',
                'plural_label' => 'Audit Log & Histori',
                'description' => 'Telusuri jejak perubahan dan aktivitas penting dalam sistem KPI.',
                'permission' => ['roles' => ['super_admin'], 'positions' => []],
                'search' => ['action', 'subject_type', 'subject_id', 'reason', 'actor.name'],
                'with' => ['actor'],
                'order_by' => 'occurred_at',
                'columns' => [
                    ['key' => 'occurred_at', 'label' => 'Waktu Kejadian', 'type' => 'datetime'],
                    ['key' => 'actor.name', 'label' => 'Pelaku', 'placeholder' => 'System'],
                    ['key' => 'action', 'label' => 'Aksi', 'type' => 'badge'],
                    ['key' => 'subject_type', 'label' => 'Tipe Objek', 'type' => 'badge'],
                    ['key' => 'subject_id', 'label' => 'ID Objek'],
                    ['key' => 'reason', 'label' => 'Alasan / Catatan', 'placeholder' => '—'],
                    ['key' => 'ip_address', 'label' => 'IP Address'],
                ],
                'fields' => [],
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
                'rules' => static fn (?AuditEvent $record): array => [],
            ],
        ];
    }

    private static function operationalAccess(string $key, array $config): array
    {
        $config['scope'] = static function ($query, User $user) use ($key): void {
            $employee = $user->employee;
            match ($key) {
                'admin-work-logs' => $query->where('employee_id', $employee?->id),
                'coaching-logs' => $query->where('supervisor_id', $employee?->id),
                'attendances' => $query->where('branch_id', $employee?->branch_id)
                    ->whereIn('employee_id', KpiVisibility::applyScope(EmployeeKpi::query(), $user)->select('employee_id')),
                'spareparts' => $query->where('branch_id', $employee?->branch_id),
                'stock-opnames' => $query->where('created_by', $user->id)->where('branch_id', $employee?->branch_id),
                'import-batches' => CapabilityMatrix::has($user, 'imports.configure') ? $query : $query->where('uploader_id', $user->id),
                'complaints' => $query->where(function ($scope) use ($user): void {
                    $scope->where('recorded_by', $user->id);
                    if ($user->hasAnyRole(['supervisor', 'owner_manager'])) {
                        $scope->orWhereIn('employee_id', KpiVisibility::applyScope(EmployeeKpi::query(), $user)->select('employee_id'));
                    }
                }),
            };
        };

        if ($key === 'import-batches') {
            $config['actions']['upload_report']['permission'] = ['roles' => [], 'positions' => ['POS-KSR']];

            return $config;
        }

        $prepare = $config['prepare'] ?? null;
        $config['prepare'] = static function (array $data, Request $request, ?Model $record) use ($key, $prepare): array {
            $user = $request->user();
            $employee = $user->employee;
            $period = KpiPeriod::active();
            abort_unless($period, 422, 'Tidak ada periode KPI yang sedang OPEN.');
            if ($record?->period_id) {
                abort_unless((int) $record->period_id === (int) $period->id, 409, 'Data periode yang sudah ditutup hanya dapat dibaca.');
            }
            $data['recorded_by'] = $record?->recorded_by ?? $user->id;
            if (in_array($key, ['admin-work-logs', 'coaching-logs', 'stock-opnames'], true)) {
                $data['period_id'] = $record?->period_id ?? $period->id;
            }
            if ($key === 'admin-work-logs') {
                $data['employee_id'] = $employee->id;
            }
            if ($key === 'coaching-logs') {
                $data['supervisor_id'] = $employee->id;
            }
            if (in_array($key, ['coaching-logs', 'attendances'], true) && ! empty($data['employee_id'])) {
                abort_unless(KpiVisibility::applyScope(EmployeeKpi::query(), $user)->where('period_id', $period->id)->where('employee_id', $data['employee_id'])->exists(), 403, 'Karyawan berada di luar penugasan Anda.');
            }
            if ($key === 'spareparts') {
                $data['branch_id'] = $employee->branch_id;
            }
            if ($key === 'stock-opnames') {
                $data['created_by'] = $record?->created_by ?? $user->id;
                $data['branch_id'] = $record?->branch_id ?? $employee->branch_id;
                $data['status'] = $record?->status ?? StockOpname::STATUS_DRAFT;
            }
            if ($key === 'complaints') {
                if (! empty($data['employee_id'])) {
                    abort_unless(Employee::whereKey($data['employee_id'])->where('branch_id', $employee->branch_id)->exists(), 403, 'Karyawan berada di luar cabang Anda.');
                }
                if (! empty($data['service_ticket_id'])) {
                    abort_unless(app(ServiceTicketService::class)->scopeTickets($user)->whereKey($data['service_ticket_id'])->exists(), 403, 'Tiket berada di luar penugasan Anda.');
                }
                if ($employee->position?->code === 'POS-CS') {
                    $data['status'] = Complaint::STATUS_OPEN;
                    $data['resolved_at'] = null;
                    $data['resolution_notes'] = null;
                }
            }
            $dateField = match ($key) {
                'admin-work-logs' => 'work_date', 'coaching-logs' => 'coaching_date', 'complaints' => 'complaint_date', 'attendances' => 'attendance_date', default => null
            };
            if ($dateField && isset($data[$dateField])) {
                $request->validate([$dateField => ['date', 'after_or_equal:'.$period->start_date->toDateString(), 'before_or_equal:'.$period->end_date->toDateString()]]);
            }

            return $prepare ? $prepare($data, $request, $record) : $data;
        };
        if (in_array($key, ['spareparts', 'stock-opnames'], true)) {
            $config['persist'] = [...($config['persist'] ?? []), 'branch_id'];
        }
        if ($key === 'stock-opnames') {
            $config['can_edit'] = static fn ($user, ?Model $record = null): bool => ! $record
                || ($record->status !== StockOpname::STATUS_COMPLETED && $record->period?->isOpen());
        }
        foreach (['employee_id', 'supervisor_id'] as $field) {
            if (! isset($config['options'][$field])) {
                continue;
            }
            $config['options'][$field] = static function (?Model $record, User $user) use ($key, $field): array {
                $employees = Employee::where('branch_id', $user->employee?->branch_id)->where('status', 'active');
                if ($key === 'admin-work-logs' || ($key === 'coaching-logs' && $field === 'supervisor_id')) {
                    $employees->whereKey($user->employee?->id);
                } elseif (in_array($key, ['coaching-logs', 'attendances'], true)) {
                    $employees->whereIn('id', KpiVisibility::applyScope(EmployeeKpi::query(), $user)->where('period_id', KpiPeriod::active()?->id)->select('employee_id'));
                }

                return $employees->orderBy('name')->get()->map(fn ($employee) => ['value' => $employee->id, 'label' => $employee->name])->all();
            };
        }
        if ($key === 'complaints') {
            $config['options']['service_ticket_id'] = static fn (?Model $record, User $user): array => app(ServiceTicketService::class)->scopeTickets($user)->latest()->limit(200)->get()->map(fn ($ticket) => ['value' => (string) $ticket->id, 'label' => $ticket->ticket_number])->all();
        }

        return $config;
    }

    private static function linkAccountEmployee(User $record, Request $request): void
    {
        $employeeId = $request->input('employee_id');
        $employee = $employeeId ? Employee::query()->lockForUpdate()->findOrFail($employeeId) : null;
        abort_if($employee && $employee->user_id && (int) $employee->user_id !== (int) $record->id, 422, 'Karyawan sudah terhubung dengan akun lain.');
        Employee::where('user_id', $record->id)->update(['user_id' => null]);
        $employee?->update(['user_id' => $record->id]);
        $record->unsetRelation('employee');
    }

    private static function positionOptions(): array
    {
        return Position::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Position $position): array => [
                'value' => (string) $position->getKey(),
                'label' => $position->name,
            ])
            ->all();
    }

    private static function branchOptions(): array
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $branch): array => [
                'value' => (string) $branch->getKey(),
                'label' => $branch->name,
            ])
            ->all();
    }

    private static function employeeOptions(?Model $record = null, $user = null, ?string $positionCode = null): array
    {
        return Employee::query()
            ->when($record instanceof Employee, fn ($query) => $query->where('id', '!=', $record->getKey()))
            ->where('status', 'active')
            ->when($positionCode, fn ($query) => $query->whereHas('position', fn ($position) => $position->where('code', $positionCode)))
            ->when($user && ! $user->hasAnyRole(['owner_manager', 'super_admin']), fn ($query) => $query->where('branch_id', $user->employee?->branch_id))
            ->orderBy('name')
            ->get(['id', 'name', 'employee_number'])
            ->map(fn (Employee $employee): array => [
                'value' => (string) $employee->getKey(),
                'label' => $employee->employee_number.' · '.$employee->name,
            ])
            ->all();
    }

    private static function attendanceEmployeeOptions($user = null): array
    {
        $period = KpiPeriod::active();
        if (! $period) {
            return [];
        }

        return self::attendanceEmployeeQuery($period, $user)
            ->orderBy('name')
            ->get(['id', 'name', 'employee_number'])
            ->map(fn (Employee $employee): array => [
                'value' => (string) $employee->getKey(),
                'label' => $employee->employee_number.' · '.$employee->name,
            ])
            ->all();
    }

    private static function attendanceEmployeeQuery(KpiPeriod $period, $user)
    {
        return Employee::query()
            ->where('status', 'active')
            ->whereIn('branch_id', $period->branches()->pluck('branches.id'))
            ->when(
                $user && ! $user->hasAnyRole(['owner_manager', 'super_admin']),
                fn ($query) => $query->where('branch_id', $user->employee?->branch_id),
            );
    }

    private static function periodOptions(): array
    {
        $period = KpiPeriod::active();

        return $period ? [[
            'value' => (string) $period->getKey(),
            'label' => "{$period->name} ({$period->status})",
        ]] : [];
    }

    private static function serviceTicketOptions(): array
    {
        $period = KpiPeriod::active();

        return $period ? ServiceTicket::query()
            ->where('period_id', $period->getKey())
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'ticket_number', 'customer_name'])
            ->map(fn (ServiceTicket $ticket): array => [
                'value' => (string) $ticket->getKey(),
                'label' => "{$ticket->ticket_number} · {$ticket->customer_name}",
            ])
            ->all() : [];
    }

    private static function roleOptions(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Role $role): array => [
                'value' => (string) $role->getKey(),
                'label' => CapabilityMatrix::roleLabel($role->name),
            ])
            ->all();
    }

    private static function monthOptions(): array
    {
        return collect(['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'])
            ->map(fn (string $label, int $index): array => ['value' => (string) ($index + 1), 'label' => $label])
            ->values()
            ->all();
    }
}
