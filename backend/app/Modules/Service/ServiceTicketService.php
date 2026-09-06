<?php

namespace App\Modules\Service;

use App\Jobs\ScanQuarantinedFile;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\FeedbackFollowUp;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\SparepartRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\Assessment\OperationalKpiSyncService;
use App\Support\CapabilityMatrix;
use App\Support\ServiceTicketNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ServiceTicketService
{
    public function __construct(
        protected OperationalKpiSyncService $syncService
    ) {}

    public function index(User $actorUser, array $input): array
    {
        $this->requireCapability($actorUser, ['tickets.view']);
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        $employee = $user->employee;

        $query = $this->scopeTickets($user)
            ->with(['technicianEmployee', 'intakeEmployee', 'cashierEmployee', 'branch', 'feedback'])
            ->orderByDesc('id');

        if (filled(data_get($input, 'status'))) {
            $query->where('status', data_get($input, 'status'));
        }

        $tickets = $query->limit(50)->get();

        return [
            'success' => true,
            'data' => $tickets->map(fn ($t) => $this->formatTicket($t, $actorUser)),
        ];
    }

    public function pelayanEmployees(User $actorUser, array $input): array
    {
        $this->requireCapability($actorUser, ['tickets.create']);
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $this->isServiceNoteAuthorized($user)) {
            throw new HttpException(403, 'Hanya Pelayan, Kasir, atau manajemen yang dapat memilih Pelayan.');
        }

        $employees = Employee::with('position')
            ->where('status', 'active')
            ->when(
                $user->employee?->branch_id,
                fn ($query) => $query->where('branch_id', $user->employee->branch_id)
            )
            ->whereHas('position', fn ($query) => $query->where('code', 'POS-CS'))
            ->orderBy('name')
            ->get();

        return [
            'success' => true,
            'data' => $employees->map(fn ($employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_number' => $employee->employee_number,
                'position' => 'Pelayan',
            ]),
        ];
    }

    public function technicianEmployees(User $actorUser, array $input): array
    {
        $this->requireCapability($actorUser, ['tickets.supervise', 'tickets.manage']);
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! CapabilityMatrix::has($user, 'tickets.supervise') && ! CapabilityMatrix::has($user, 'tickets.manage')) {
            throw new HttpException(403, 'Hanya Supervisor atau Manager yang dapat memilih Teknisi.');
        }

        $employees = Employee::with('position')
            ->where('status', 'active')
            ->when(
                $user->employee?->branch_id,
                fn ($query) => $query->where('branch_id', $user->employee->branch_id)
            )
            ->whereHas('position', fn ($query) => $query->where('code', 'POS-TEK'))
            ->when(CapabilityMatrix::has($user, 'tickets.supervise'), fn ($query) => $query->where('supervisor_id', $user->employee->id))
            ->orderBy('name')
            ->get();

        return [
            'success' => true,
            'data' => $employees->map(fn ($employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_number' => $employee->employee_number,
                'position' => 'Teknisi',
            ]),
        ];
    }

    public function store(User $actorUser, array $input): array
    {
        $this->requireCapability($actorUser, ['tickets.create']);
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        $employee = $user->employee;

        // Pelayan membuat Tiket Servis resmi; manajemen hanya menjadi jalur administrasi.
        if (! $this->isServiceNoteAuthorized($user)) {
            throw new HttpException(403, 'Hanya Pelayan atau manajemen yang dapat membuat Tiket Servis resmi.');
        }

        $pelayanRequired = $employee?->position?->code === 'POS-CS' ? 'nullable' : 'required';
        Validator::make($input, [
            'customer_name' => 'required|string|max:100',
            'customer_phone' => 'required|string|max:30',
            'customer_address' => 'nullable|string',
            'device_brand' => 'required|string|max:50',
            'device_model' => 'required|string|max:100',
            'imei_or_serial' => 'nullable|string|max:100',
            'passcode_or_pattern' => 'nullable|string|max:50',
            'physical_condition' => 'nullable|string',
            'initial_complaint' => 'required|string',
            'customer_needs' => 'nullable|string',
            'service_category' => 'nullable|string|max:50',
            'service_complexity' => 'nullable|in:light,medium,heavy',
            'estimated_cost' => 'nullable|numeric|min:0',
            'estimated_completion_at' => 'nullable|date',
            'technician_employee_id' => 'prohibited',
            'assignment_reason' => 'nullable|string|max:1000',
            'pelayan_employee_id' => [$pelayanRequired, 'string', 'exists:employees,id'],
        ])->validate();

        if ($employee?->position?->code === 'POS-CS'
            && Arr::has($input, 'estimated_cost')
            && (float) data_get($input, 'estimated_cost') > 0) {
            throw new HttpException(422, 'Estimasi biaya hanya dapat diisi oleh Kasir atau manajemen.');
        }

        $pelayanEmployeeId = $employee?->position?->code === 'POS-CS'
            ? $employee->id
            : data_get($input, 'pelayan_employee_id');
        $pelayanQuery = Employee::whereKey($pelayanEmployeeId)
            ->where('status', 'active')
            ->whereHas('position', fn ($query) => $query->where('code', 'POS-CS'));
        if ($employee?->branch_id) {
            $pelayanQuery->where('branch_id', $employee->branch_id);
        }
        $pelayan = $pelayanQuery->first();

        if (! $pelayan) {
            throw new HttpException(422, 'Karyawan yang dipilih bukan Pelayan aktif.');
        }

        $branchId = $pelayan->branch_id ?? $employee?->branch_id;
        if (! $branchId) {
            throw new HttpException(422, 'Cabang tiket tidak dapat ditentukan dari Pelayan yang dipilih.');
        }

        $serviceCategory = data_get($input, 'service_category', 'general');
        $serviceComplexity = data_get($input, 'service_complexity', 'light');
        if (! array_key_exists($serviceComplexity, ServiceTicket::SLA_WORKDAYS)) {
            throw new HttpException(422, 'Kompleksitas servis tidak valid.');
        }
        $slaBaselineDueAt = now()->addWeekdays(ServiceTicket::SLA_WORKDAYS[$serviceComplexity]);

        if (filled(data_get($input, 'technician_employee_id'))
            && trim((string) data_get($input, 'assignment_reason')) === '') {
            throw new HttpException(422, 'Assignment Teknisi saat pembuatan tiket wajib memiliki alasan.');
        }

        if (filled(data_get($input, 'technician_employee_id')) && ! Employee::whereKey(data_get($input, 'technician_employee_id'))
            ->where('status', 'active')
            ->where('branch_id', $branchId)
            ->whereHas('position', fn ($query) => $query->where('code', 'POS-TEK'))
            ->exists()) {
            throw new HttpException(422, 'Teknisi yang dipilih harus aktif dan satu cabang dengan Pelayan penerima.');
        }

        $activePeriod = KpiPeriod::active();

        $ticket = DB::transaction(function () use ($input, $employee, $pelayan, $branchId, $activePeriod, $user, $serviceCategory, $serviceComplexity, $slaBaselineDueAt) {
            $ticketNumber = ServiceTicketNumber::next();

            $ticket = ServiceTicket::create([
                'ticket_number' => $ticketNumber,
                'customer_name' => data_get($input, 'customer_name'),
                'customer_phone' => data_get($input, 'customer_phone'),
                'customer_address' => data_get($input, 'customer_address'),
                'device_brand' => data_get($input, 'device_brand'),
                'device_model' => data_get($input, 'device_model'),
                'imei_or_serial' => data_get($input, 'imei_or_serial'),
                'passcode_or_pattern' => data_get($input, 'passcode_or_pattern'),
                'physical_condition' => data_get($input, 'physical_condition'),
                'initial_complaint' => data_get($input, 'initial_complaint'),
                'customer_needs' => data_get($input, 'customer_needs'),
                'estimated_cost' => $employee?->position?->code === 'POS-CS'
                    ? 0
                    : (data_get($input, 'estimated_cost') ?? 0),
                'estimated_completion_at' => $slaBaselineDueAt,
                'service_category' => $serviceCategory,
                'service_complexity' => $serviceComplexity,
                'sla_version' => 'v1',
                'sla_baseline_due_at' => $slaBaselineDueAt,
                'sla_due_at' => $slaBaselineDueAt,
                'sla_snapshot_json' => [
                    'version' => 'v1',
                    'category' => $serviceCategory,
                    'complexity' => $serviceComplexity,
                    'workdays' => ServiceTicket::SLA_WORKDAYS[$serviceComplexity],
                    'captured_at' => now()->toIso8601String(),
                ],
                'branch_id' => $branchId,
                'period_id' => $activePeriod?->id,
                'intake_by_employee_id' => $pelayan->id,
                'cashier_employee_id' => $employee?->position?->code === 'POS-KSR' ? $employee->id : null,
                'technician_employee_id' => data_get($input, 'technician_employee_id'),
                'status' => 'intake',
                'row_version' => 1,
                'result_status' => 'pending',
            ]);

            $this->auditTicketMutation('created', $ticket, null, actorId: $user->getKey(), reason: data_get($input, 'assignment_reason'));

            if ($activePeriod) {
                $this->syncService->syncPeriodOperationalData($activePeriod);
            }

            return $ticket;
        });

        return [
            'success' => true,
            'message' => "Tiket Servis {$ticket->ticket_number} berhasil dibuat.",
            'data' => $this->formatTicket($ticket->fresh(['technicianEmployee', 'intakeEmployee', 'cashierEmployee']), $actorUser),
        ];
    }

    public function recordEstimatedCost(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.cost']);
        Validator::make($input, [
            'estimated_cost' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:2000',
            'row_version' => 'required|integer|min:1',
        ])->validate();
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $this->isCashierOrManagement($user)) {
            throw new HttpException(403, 'Hanya Kasir atau manajemen yang dapat mencatat estimasi biaya.');
        }

        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTicketAccess($ticket, $actorUser)) {
                throw new HttpException(403, 'Tiket berada di luar cakupan Anda.');
            }
            if (! in_array($ticket->status, ServiceTicket::ESTIMATED_COST_EDITABLE_STATUSES, true)) {
                throw new HttpException(422, 'Estimasi biaya hanya dapat dicatat sebelum tiket selesai teknis.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain.');
            }

            abort_if($ticket->cashier_employee_id && (string) $ticket->cashier_employee_id !== (string) $user->employee->id, 403, 'Biaya dan pembayaran tiket ini ditangani Kasir lain.');
            $oldCost = (float) $ticket->estimated_cost;
            $newCost = (float) data_get($input, 'estimated_cost');
            if (abs($oldCost - $newCost) > 0.000001 && trim((string) data_get($input, 'note')) === '') {
                throw new HttpException(422, 'Perubahan estimasi biaya wajib memiliki catatan.');
            }

            $before = $this->ticketAuditSnapshot($ticket);
            $ticket->estimated_cost = $newCost;
            if (abs($oldCost - $newCost) > 0.000001) {
                $ticket->customer_consent_status = 'pending';
                $ticket->customer_consent_at = null;
            }
            if ($user->employee?->position?->code === 'POS-KSR') {
                $ticket->cashier_employee_id = $user->employee->id;
            }
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('estimated_cost_recorded', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'note'));

            return ['ticket' => $ticket];
        });

        return [
            'success' => true,
            'message' => 'Estimasi biaya berhasil dicatat.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee', 'cashierEmployee']), $actorUser),
        ];
    }

    public function recordConsent(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.consent', 'tickets.manage']);
        Validator::make($input, [
            'consent_status' => 'required|in:approved,declined',
            'consent_notes' => 'nullable|string|max:2000',
            'row_version' => 'required|integer|min:1',
        ])->validate();

        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $this->isServiceNoteAuthorized($user)) {
            throw new HttpException(403, 'Hanya Pelayan atau manajemen yang dapat mencatat persetujuan customer.');
        }

        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTicketAccess($ticket, $actorUser)) {
                throw new HttpException(403, 'Tiket berada di luar cakupan Anda.');
            }
            if ($ticket->isFinal() || in_array($ticket->status, [ServiceTicket::STATUS_COMPLETED, ServiceTicket::STATUS_DELIVERED], true)) {
                throw new HttpException(422, 'Persetujuan tidak dapat diubah setelah tiket selesai.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum mencatat persetujuan.');
            }
            if ($ticket->customer_consent_status === 'approved'
                && data_get($input, 'consent_status') !== 'approved'
                && ! $user->hasAnyRole(['owner_manager'])) {
                throw new HttpException(403, 'Persetujuan customer yang sudah disahkan tidak dapat ditarik oleh Pelayan.');
            }

            abort_unless(CapabilityMatrix::has($user, 'tickets.manage') || (string) $ticket->intake_by_employee_id === (string) $user->employee->id, 403, 'Tiket ini ditangani Pelayan lain.');
            $before = $this->ticketAuditSnapshot($ticket);
            $ticket->customer_consent_status = data_get($input, 'consent_status');
            $ticket->customer_consent_at = now();
            $ticket->customer_consent_by_employee_id = $user->employee?->id;
            $ticket->customer_consent_notes = data_get($input, 'consent_notes');
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('consent_recorded', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'consent_notes'));

            return ['ticket' => $ticket];
        });

        return [
            'success' => true,
            'message' => 'Persetujuan customer berhasil dicatat.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee']), $actorUser),
        ];
    }

    public function recordFinalCost(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.cost']);
        Validator::make($input, [
            'final_cost' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:2000',
            'row_version' => 'required|integer|min:1',
        ])->validate();
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $this->isCashierOrManagement($user)) {
            throw new HttpException(403, 'Hanya Kasir atau manajemen yang dapat mencatat biaya final.');
        }

        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTicketAccess($ticket, $actorUser)) {
                throw new HttpException(403, 'Tiket berada di luar cakupan Anda.');
            }
            if (! in_array($ticket->status, [ServiceTicket::STATUS_COMPLETED], true)) {
                throw new HttpException(422, 'Biaya final dicatat setelah teknisi menyelesaikan tiket.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain.');
            }
            abort_if($ticket->cashier_employee_id && (string) $ticket->cashier_employee_id !== (string) $user->employee->id, 403, 'Biaya dan pembayaran tiket ini ditangani Kasir lain.');
            $oldCost = (float) $ticket->final_cost;
            $newCost = (float) data_get($input, 'final_cost');
            if (abs($oldCost - $newCost) > 0.000001 && trim((string) data_get($input, 'note')) === '') {
                throw new HttpException(422, 'Perubahan biaya final wajib memiliki catatan.');
            }

            $before = $this->ticketAuditSnapshot($ticket);
            if ($newCost < (float) $ticket->paid_amount) {
                throw new HttpException(422, 'Biaya final tidak boleh lebih kecil dari pembayaran yang sudah tercatat.');
            }
            $ticket->final_cost = $newCost;
            $ticket->payment_exception_type = null;
            $ticket->payment_exception_approved_by_user_id = null;
            $ticket->payment_exception_approved_at = null;
            if ($user->employee?->position?->code === 'POS-KSR') {
                $ticket->cashier_employee_id = $user->employee->id;
            }
            if ($newCost <= 0) {
                $ticket->payment_status = 'not_required';
            } elseif ((float) $ticket->paid_amount >= $newCost) {
                $ticket->payment_status = 'paid';
            } else {
                $ticket->payment_status = (float) $ticket->paid_amount > 0 ? 'partial' : 'unpaid';
            }
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('final_cost_recorded', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'note'));

            return ['ticket' => $ticket];
        });

        return [
            'success' => true,
            'message' => 'Biaya final berhasil dicatat.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee', 'cashierEmployee']), $actorUser),
        ];
    }

    public function recordPayment(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.payment']);
        Validator::make($input, [
            'paid_amount' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:1000',
            'row_version' => 'required|integer|min:1',
        ])->validate();
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $this->isCashierOrManagement($user)) {
            throw new HttpException(403, 'Hanya Kasir atau manajemen yang dapat mencatat pembayaran aktual.');
        }

        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTicketAccess($ticket, $actorUser)) {
                throw new HttpException(403, 'Tiket berada di luar cakupan Anda.');
            }
            if (! in_array($ticket->status, [ServiceTicket::STATUS_COMPLETED], true)) {
                throw new HttpException(422, 'Pembayaran dicatat setelah tiket selesai teknis.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain.');
            }

            abort_if($ticket->cashier_employee_id && (string) $ticket->cashier_employee_id !== (string) $user->employee->id, 403, 'Biaya dan pembayaran tiket ini ditangani Kasir lain.');
            $amount = (float) data_get($input, 'paid_amount');
            $finalCost = (float) $ticket->final_cost;
            if ($amount > $finalCost) {
                throw new HttpException(422, 'Pembayaran aktual tidak boleh melebihi biaya final tiket.');
            }
            $before = $this->ticketAuditSnapshot($ticket);
            $ticket->paid_amount = $amount;
            if ($user->employee?->position?->code === 'POS-KSR') {
                $ticket->cashier_employee_id = $user->employee->id;
            }
            $ticket->payment_status = $finalCost <= 0
                ? 'not_required'
                : ($amount >= $finalCost ? 'paid' : ($amount > 0 ? 'partial' : 'unpaid'));
            $ticket->payment_recorded_at = now();
            $ticket->payment_recorded_by_employee_id = $user->employee?->id;
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('payment_recorded', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'note'));

            return ['ticket' => $ticket];
        });

        return [
            'success' => true,
            'message' => 'Pembayaran aktual berhasil dicatat.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee', 'cashierEmployee']), $actorUser),
        ];
    }

    public function approvePaymentException(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.manage']);
        Validator::make($input, [
            'exception_type' => 'required|in:installment,receivable,waiver',
            'reason' => 'required|string|min:3|max:2000',
            'row_version' => 'required|integer|min:1',
        ])->validate();
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $user->hasAnyRole(['owner_manager'])) {
            throw new HttpException(403, 'Hanya Manager yang dapat mengesahkan pengecualian pembayaran.');
        }

        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTicketAccess($ticket, $actorUser)) {
                throw new HttpException(403, 'Tiket berada di luar cakupan Anda.');
            }
            if ($ticket->status !== ServiceTicket::STATUS_COMPLETED) {
                throw new HttpException(422, 'Pengecualian pembayaran hanya berlaku setelah tiket selesai teknis.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain.');
            }

            $before = $this->ticketAuditSnapshot($ticket);
            $ticket->payment_exception_type = data_get($input, 'exception_type');
            $ticket->payment_exception_reason = data_get($input, 'reason');
            $ticket->payment_exception_approved_by_user_id = $user->getKey();
            $ticket->payment_exception_approved_at = now();
            $ticket->payment_status = 'exception_approved';
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('payment_exception_approved', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'reason'));

            return ['ticket' => $ticket];
        });

        return [
            'success' => true,
            'message' => 'Pengecualian pembayaran berhasil disahkan Manager.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee', 'cashierEmployee']), $actorUser),
        ];
    }

    public function cancel(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.create', 'tickets.manage']);
        Validator::make($input, [
            'reason' => 'required|string|min:3|max:2000',
            'row_version' => 'required|integer|min:1',
        ])->validate();
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $this->isServiceNoteAuthorized($user)) {
            throw new HttpException(403, 'Hanya Pelayan atau manajemen yang dapat membatalkan tiket sebelum pekerjaan dimulai.');
        }

        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTicketAccess($ticket, $actorUser)) {
                throw new HttpException(403, 'Tiket berada di luar cakupan Anda.');
            }
            if ($ticket->status !== ServiceTicket::STATUS_INTAKE) {
                throw new HttpException(422, 'Pembatalan hanya boleh dilakukan sebelum diagnosa dimulai.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain.');
            }

            abort_unless(CapabilityMatrix::has($user, 'tickets.manage') || (string) $ticket->intake_by_employee_id === (string) $user->employee->id, 403, 'Tiket ini ditangani Pelayan lain.');
            $before = $this->ticketAuditSnapshot($ticket);
            $ticket->status = ServiceTicket::STATUS_CANCELLED;
            $ticket->cancellation_reason = data_get($input, 'reason');
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('cancelled', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'reason'));

            return ['ticket' => $ticket];
        });

        return [
            'success' => true,
            'message' => 'Tiket berhasil dibatalkan sebelum pekerjaan dimulai.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee']), $actorUser),
        ];
    }

    public function show(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.view']);
        $ticket = ServiceTicket::with([
            'technicianEmployee',
            'intakeEmployee',
            'cashierEmployee',
            'branch',
            'sparepartRequests.sparepart',
            'feedback',
        ])->where('id', $id)->first();

        if (! $ticket) {
            throw new HttpException(404, 'Tiket tidak ditemukan.');
        }

        // Teknisi hanya boleh lihat tiket miliknya
        if (! $this->authorizeTicketAccess($ticket, $actorUser)) {
            throw new HttpException(403, 'Anda tidak memiliki akses ke tiket ini.');
        }

        $data = $this->formatTicket($ticket, $actorUser, true);
        if (! $this->canViewSensitiveTicketFields($actorUser, $ticket)) {
            unset($data['imei_or_serial'], $data['passcode_or_pattern']);
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    public function assignTechnician(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.claim', 'tickets.supervise', 'tickets.manage']);
        Validator::make($input, [
            'technician_employee_id' => 'nullable|string',
            'row_version' => 'required|integer|min:1',
            'assignment_reason' => 'nullable|string|max:1000',
        ])->validate();
        $actor = $actorUser->loadMissing(['employee.position']);
        $employee = $actor->employee;

        $result = DB::transaction(function () use ($actorUser, $input, $id, $actor, $employee) {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTicketAccess($ticket, $actorUser)) {
                throw new HttpException(403, 'Tiket berada di luar cakupan cabang Anda.');
            }
            if ($ticket->isFinal() || $ticket->status === ServiceTicket::STATUS_COMPLETED) {
                throw new HttpException(422, 'Tiket sudah selesai, tidak bisa diubah teknisi.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum menugaskan teknisi.');
            }

            $isTechnician = $employee?->position?->code === 'POS-TEK';
            $technicianId = data_get($input, 'technician_employee_id') ?? $employee?->id;
            if ($isTechnician && (string) $technicianId !== (string) $employee->id) {
                throw new HttpException(403, 'Teknisi hanya bisa mengambil tiket untuk dirinya sendiri.');
            }
            if ($isTechnician && $ticket->technician_employee_id !== null) {
                throw new HttpException(403, 'Perubahan assignment hanya dapat dilakukan Supervisor atau Manager.');
            }
            if ($isTechnician && $ticket->status !== ServiceTicket::STATUS_INTAKE) {
                throw new HttpException(422, 'Teknisi hanya dapat claim tiket sebelum diagnosa dimulai.');
            }
            if (! $isTechnician && ! CapabilityMatrix::has($actor, 'tickets.supervise') && ! CapabilityMatrix::has($actor, 'tickets.manage')) {
                throw new HttpException(403, 'Hanya Supervisor atau Manager yang dapat mengubah penugasan teknisi.');
            }
            if (! $technicianId) {
                throw new HttpException(422, 'Teknisi tidak ditemukan pada akun Anda.');
            }

            $before = $this->ticketAuditSnapshot($ticket);

            $technician = Employee::whereKey($technicianId)
                ->where('status', 'active')
                ->where('branch_id', $ticket->branch_id)
                ->whereHas('position', fn ($query) => $query->where('code', 'POS-TEK'))
                ->first();
            if ($technician && CapabilityMatrix::has($actor, 'tickets.supervise') && (string) $technician->supervisor_id !== (string) $employee->id) {
                abort(403, 'Teknisi berada di luar tim Anda.');
            }
            if (! $technician) {
                throw new HttpException(422, 'Karyawan terpilih bukan Teknisi.');
            }
            if (! $isTechnician
                && trim((string) data_get($input, 'assignment_reason')) === '') {
                throw new HttpException(422, 'Alasan perubahan assignment wajib diisi.');
            }

            $ticket->technician_employee_id = $technician->id;
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('assigned', $ticket, $before, actorId: $actor->getKey(), reason: data_get($input, 'assignment_reason'));

            return ['ticket' => $ticket, 'technician' => $technician];
        });

        return [
            'success' => true,
            'message' => "Tiket {$result['ticket']->ticket_number} ditugaskan ke {$result['technician']->name}.",
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee']), $actorUser),
        ];
    }

    public function updateProgress(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.progress']);
        Validator::make($input, [
            'diagnosis_notes' => 'nullable|string',
            'action_notes' => 'nullable|string',
            'status' => 'nullable|string|in:diagnosing,waiting_sparepart,in_progress,qc_ready',
            'row_version' => 'required|integer|min:1',
        ])->validate();

        $result = DB::transaction(function () use ($actorUser, $input, $id) {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTechnicalWrite($ticket, $actorUser)) {
                throw new HttpException(403, 'Hanya Teknisi yang ditugaskan yang dapat mengubah progress tiket ini.');
            }
            if ($ticket->is_warranty_return && $ticket->warranty_review_status !== 'approved') {
                throw new HttpException(422, 'Retur garansi harus divalidasi Supervisor sebelum dikerjakan.');
            }
            $before = $this->ticketAuditSnapshot($ticket);
            if ($ticket->isFinal() || $ticket->status === ServiceTicket::STATUS_COMPLETED) {
                throw new HttpException(422, 'Tiket sudah selesai dan tidak dapat diubah.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum menyimpan progress.');
            }

            $nextStatus = data_get($input, 'status');

            if (! $nextStatus && ! Arr::hasAny($input, ['diagnosis_notes', 'action_notes'])) {
                throw new HttpException(422, 'Tidak ada perubahan progress yang dikirim.');
            }
            if ($nextStatus) {
                try {
                    $ticket->assertTransition($nextStatus);
                } catch (\RuntimeException $exception) {
                    throw new HttpException(422, $exception->getMessage());
                }
                if (in_array($nextStatus, [ServiceTicket::STATUS_IN_PROGRESS, ServiceTicket::STATUS_QC_READY], true) && $ticket->customer_consent_status !== 'approved') {
                    throw new HttpException(422, 'Persetujuan pelanggan wajib dicatat sebelum pengerjaan.');
                }
                if ($nextStatus === ServiceTicket::STATUS_WAITING_SPAREPART
                    && ! $ticket->sparepartRequests()->where('status', 'pending')->exists()) {
                    throw new HttpException(422, 'Status Menunggu Sparepart hanya boleh dipakai setelah permintaan sparepart resmi dibuat.');
                }
                if ($nextStatus === ServiceTicket::STATUS_IN_PROGRESS
                    && ($ticket->hasPendingSparepartRequests() || $ticket->hasUnconfirmedSparepartRequests())) {
                    throw new HttpException(422, 'Tiket belum dapat dilanjutkan. Semua sparepart harus dipenuhi dan dikonfirmasi teknisi.');
                }
                $ticket->status = $nextStatus;
            }
            $ticket->diagnosis_notes = data_get($input, 'diagnosis_notes', $ticket->diagnosis_notes);
            $ticket->action_notes = data_get($input, 'action_notes', $ticket->action_notes);
            if (! $ticket->started_at && in_array($ticket->status, ['in_progress', 'diagnosing'], true)) {
                $ticket->started_at = now();
            }
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $ticket->refresh();
            $this->auditTicketMutation('progress_updated', $ticket, $before, actorId: $actorUser?->getKey());

            return ['ticket' => $ticket];
        });

        return [
            'success' => true,
            'message' => 'Status pengerjaan tiket berhasil diperbarui.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee']), $actorUser),
        ];
    }

    public function complete(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.complete']);
        Validator::make($input, [
            'result_status' => 'required|in:success,unrepairable,customer_declined',
            'diagnosis_notes' => 'required|string',
            'action_notes' => 'required|string',
            'qc_checklist' => 'required|array',
            'qc_checklist.*' => 'required|boolean',
            'unrepairable_reason' => 'nullable|string|max:2000',
            'customer_declined_reason' => 'nullable|string|max:2000',
            'technical_evidence' => 'nullable|array',
            'technical_evidence.*.type' => 'required_with:technical_evidence|string|max:30',
            'technical_evidence.*.reference' => 'nullable|string|max:500',
            'technical_evidence.*.file' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,pdf',
            'final_cost' => 'prohibited',
            'row_version' => 'required|integer|min:1',
        ])->validate();

        foreach ((array) data_get($input, 'technical_evidence', []) as $index => $evidence) {
            $file = data_get($input, "technical_evidence.{$index}.file");
            if (trim((string) ($evidence['reference'] ?? '')) === ''
                && ! ($file instanceof UploadedFile)) {
                throw new HttpException(422, 'Setiap evidence harus memiliki referensi atau file.');
            }
        }

        $result = DB::transaction(function () use ($actorUser, $input, $id) {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTechnicalWrite($ticket, $actorUser)) {
                throw new HttpException(403, 'Hanya Teknisi yang ditugaskan yang dapat menyelesaikan tiket ini.');
            }
            abort_if($ticket->is_warranty_return && $ticket->warranty_review_status !== 'approved', 422, 'Retur garansi harus divalidasi Supervisor sebelum diselesaikan.');
            abort_if($ticket->status === ServiceTicket::STATUS_COMPLETED || $ticket->isFinal(), 422, 'Tiket sudah selesai.');
            $before = $this->ticketAuditSnapshot($ticket);
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum menyelesaikan tiket.');
            }
            $requiredQcKeys = ['display', 'touch', 'camera', 'mic', 'speaker', 'cellular', 'charging', 'biometric'];
            $qcChecklist = array_map(
                static fn (mixed $value): mixed => match ($value) {
                    true, 1, '1' => true,
                    false, 0, '0' => false,
                    default => $value,
                },
                data_get($input, 'qc_checklist'),
            );
            if (array_key_exists('face_id', $qcChecklist) && ! array_key_exists('biometric', $qcChecklist)) {
                $qcChecklist['biometric'] = $qcChecklist['face_id'];
            }
            if (array_diff($requiredQcKeys, array_keys($qcChecklist))) {
                throw new HttpException(422, 'Checklist QC wajib memuat seluruh komponen pengujian.');
            }
            if (collect($requiredQcKeys)->contains(fn (string $key): bool => ! is_bool($qcChecklist[$key]))) {
                throw new HttpException(422, 'Setiap komponen checklist QC wajib bernilai boolean.');
            }
            try {
                if (data_get($input, 'result_status') === ServiceTicket::RESULT_SUCCESS
                    && $ticket->status !== ServiceTicket::STATUS_QC_READY) {
                    throw new HttpException(422, 'Tiket harus berstatus Siap QC sebelum diselesaikan.');
                }
                $ticket->assertTransition('completed');

            } catch (\RuntimeException $exception) {
                throw new HttpException(422, $exception->getMessage());
            }

            if ($ticket->hasPendingSparepartRequests()) {
                throw new HttpException(422, 'Tiket masih memiliki permintaan sparepart yang belum diputuskan Gudang.');
            }
            if ($ticket->hasUnconfirmedSparepartRequests()) {
                throw new HttpException(422, 'Sparepart yang dipenuhi Gudang harus dikonfirmasi teknisi sebelum tiket selesai.');
            }
            if (data_get($input, 'result_status') === ServiceTicket::RESULT_SUCCESS
                && collect($requiredQcKeys)->contains(fn (string $key): bool => $qcChecklist[$key] !== true)) {
                throw new HttpException(422, 'Servis sukses hanya dapat diselesaikan jika seluruh checklist QC lulus.');
            }
            if (data_get($input, 'result_status') === ServiceTicket::RESULT_UNREPAIRABLE
                && trim((string) data_get($input, 'unrepairable_reason')) === '') {
                throw new HttpException(422, 'Alasan tidak dapat diperbaiki wajib diisi.');
            }
            if (data_get($input, 'result_status') === ServiceTicket::RESULT_UNREPAIRABLE
                && empty(data_get($input, 'technical_evidence', []))) {
                throw new HttpException(422, 'Bukti teknis wajib dilampirkan untuk hasil tidak dapat diperbaiki.');
            }
            if (data_get($input, 'result_status') === ServiceTicket::RESULT_SUCCESS
                && empty(data_get($input, 'technical_evidence', []))) {
                throw new HttpException(422, 'Evidence hasil servis wajib dilampirkan sebelum tiket sukses ditutup.');
            }
            if (data_get($input, 'result_status') === ServiceTicket::RESULT_CUSTOMER_DECLINED
                && trim((string) data_get($input, 'customer_declined_reason')) === '') {
                throw new HttpException(422, 'Alasan customer menolak tindakan servis wajib dicatat.');
            }
            if (data_get($input, 'result_status') === ServiceTicket::RESULT_SUCCESS
                && $ticket->customer_consent_status !== 'approved') {
                throw new HttpException(422, 'Persetujuan customer wajib tercatat sebelum tindakan servis berbayar diselesaikan.');
            }

            $ticket->result_status = data_get($input, 'result_status');
            $ticket->is_warranty_return = (bool) $ticket->is_warranty_return;
            $ticket->diagnosis_notes = data_get($input, 'diagnosis_notes');
            $ticket->action_notes = data_get($input, 'action_notes');
            $ticket->qc_checklist_json = $qcChecklist;
            $ticket->unrepairable_reason = data_get($input, 'unrepairable_reason');
            $ticket->customer_declined_reason = data_get($input, 'customer_declined_reason');
            if (filled(data_get($input, 'technical_evidence'))) {
                $existingEvidence = is_array($ticket->technical_evidence_json) ? $ticket->technical_evidence_json : [];
                $newEvidence = collect(array_values(data_get($input, 'technical_evidence')))
                    ->map(function (array $evidence, int $index) use ($actorUser, $input, $ticket): array {
                        $file = data_get($input, "technical_evidence.{$index}.file");
                        $filePath = $file instanceof UploadedFile
                            ? $file->store("quarantine/service-tickets/{$ticket->getKey()}/evidence", 'local')
                            : null;

                        return [
                            'type' => trim((string) ($evidence['type'] ?? 'service_note')),
                            'reference' => trim((string) ($evidence['reference'] ?? '')) ?: $file?->getClientOriginalName(),
                            'file_path' => $filePath,
                            'file_name' => $file?->getClientOriginalName(),
                            'mime_type' => $file?->getMimeType(),
                            'size_bytes' => $file?->getSize(),
                            'sha256_hash' => $file instanceof UploadedFile ? hash_file('sha256', $file->getRealPath()) : null,
                            'scan_status' => $file instanceof UploadedFile ? 'quarantine' : 'clean',
                            'uploaded_at' => now()->toIso8601String(),
                            'uploaded_by_user_id' => $actorUser?->getKey(),
                            'uploaded_by_employee_id' => $actorUser?->employee?->id,
                        ];
                    })
                    ->all();
                $ticket->technical_evidence_json = [...$existingEvidence, ...$newEvidence];
            }
            $ticket->completed_at = now();
            $slaDueAt = $ticket->sla_due_at ?? $ticket->estimated_completion_at;
            if ($slaDueAt && $ticket->completed_at->greaterThan($slaDueAt)) {
                $ticket->sla_breached_at ??= $ticket->completed_at;
            }
            $ticket->status = ServiceTicket::STATUS_COMPLETED;
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();

            foreach ($ticket->technical_evidence_json ?? [] as $index => $evidence) {
                if (($evidence['scan_status'] ?? null) === 'quarantine') {
                    ScanQuarantinedFile::dispatch('service_ticket', $ticket->id.':'.$index)->afterCommit();
                }
            }

            $this->auditTicketMutation('completed', $ticket, $before, actorId: $actorUser?->getKey());

            $activePeriod = $ticket->period ?? KpiPeriod::active();
            if ($activePeriod) {
                $this->syncService->syncPeriodOperationalData($activePeriod);
            }

            return ['ticket' => $ticket];
        });

        $ticket = $result['ticket'];

        return [
            'success' => true,
            'message' => 'Pengerjaan servis berhasil diselesaikan dan dicatat ke metrik KPI.',
            'data' => $this->formatTicket($ticket->fresh(['technicianEmployee', 'intakeEmployee']), $actorUser),
        ];
    }

    public function createWarrantyReturn(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.create']);
        Validator::make($input, [
            'initial_complaint' => 'required|string',
            'customer_needs' => 'nullable|string',
            'estimated_completion_at' => 'nullable|date',
            'technician_employee_id' => 'prohibited',
            'same_symptom_confirmed' => 'required|boolean',
            'warranty_reason' => 'required|string|min:3|max:2000',
        ])->validate();
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $this->isServiceNoteAuthorized($user)) {
            throw new HttpException(403, 'Hanya Pelayan atau manajemen yang dapat membuat tiket retur.');
        }

        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $original = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $original) {
                throw new HttpException(404, 'Tiket asal tidak ditemukan.');
            }
            if (! $this->authorizeTicketAccess($original, $actorUser)) {
                throw new HttpException(403, 'Tiket asal berada di luar cakupan Anda.');
            }
            if ($original->status !== ServiceTicket::STATUS_DELIVERED) {
                throw new HttpException(422, 'Retur garansi hanya dapat dibuat setelah tiket diserahkan ke customer.');
            }
            $warrantyExpiresAt = $original->warranty_expires_at
                ?? $original->delivered_at?->copy()->addDays(7);
            if (! $warrantyExpiresAt || now()->gt($warrantyExpiresAt)) {
                throw new HttpException(422, 'Masa garansi tiket sudah berakhir.');
            }
            if (! filter_var(data_get($input, 'same_symptom_confirmed'), FILTER_VALIDATE_BOOLEAN)) {
                throw new HttpException(422, 'Retur garansi hanya dapat diproses untuk gejala yang sama dan harus dikonfirmasi.');
            }
            if (ServiceTicket::where('warranty_returned_from_ticket_id', $original->id)->exists()) {
                throw new HttpException(422, 'Tiket asal sudah memiliki tiket retur garansi.');
            }

            $activePeriod = KpiPeriod::active();
            $employee = $user->employee;
            $technicianId = $original->technician_employee_id;
            if ($technicianId !== null && ! Employee::whereKey($technicianId)
                ->where('status', 'active')
                ->where('branch_id', $original->branch_id)
                ->whereHas('position', fn ($query) => $query->where('code', 'POS-TEK'))
                ->exists()) {
                throw new HttpException(422, 'Teknisi retur bukan teknisi aktif pada cabang tiket.');
            }
            $returnTicket = ServiceTicket::create([
                'ticket_number' => ServiceTicketNumber::next(),
                'customer_name' => $original->customer_name,
                'customer_phone' => $original->customer_phone,
                'customer_address' => $original->customer_address,
                'device_brand' => $original->device_brand,
                'device_model' => $original->device_model,
                'imei_or_serial' => $original->imei_or_serial,
                'passcode_or_pattern' => null,
                'physical_condition' => $original->physical_condition,
                'initial_complaint' => data_get($input, 'initial_complaint'),
                'customer_needs' => data_get($input, 'customer_needs'),
                'estimated_cost' => 0,
                'estimated_completion_at' => data_get($input, 'estimated_completion_at'),
                'branch_id' => $original->branch_id,
                'period_id' => $activePeriod?->id,
                'intake_by_employee_id' => $employee?->position?->code === 'POS-CS'
                    ? $employee->id
                    : $original->intake_by_employee_id,
                'technician_employee_id' => $technicianId,
                'status' => 'intake',
                'row_version' => 1,
                'result_status' => 'pending',
                'is_warranty_return' => true,
                'warranty_returned_from_ticket_id' => $original->id,
                'warranty_review_status' => 'pending',
                'warranty_review_reason' => data_get($input, 'warranty_reason'),
            ]);
            $this->auditTicketMutation('warranty_return_created', $returnTicket, null, actorId: $user->getKey(), reason: data_get($input, 'warranty_reason'));
            if ($activePeriod) {
                $this->syncService->syncPeriodOperationalData($activePeriod);
            }

            return ['ticket' => $returnTicket];
        });

        return [
            'success' => true,
            'message' => 'Tiket retur garansi berhasil dibuat dan ditautkan ke tiket asal.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee']), $actorUser),
        ];
    }

    public function reviewWarrantyReturn(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.supervise', 'tickets.manage']);
        Validator::make($input, [
            'decision' => 'required|in:approved,rejected,disputed',
            'reason' => 'required|string|min:3|max:2000',
            'row_version' => 'required|integer|min:1',
        ])->validate();
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $user->hasAnyRole(['owner_manager', 'supervisor'])) {
            throw new HttpException(403, 'Hanya Supervisor atau Manager yang dapat memvalidasi retur garansi.');
        }

        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket retur tidak ditemukan.');
            }
            if (! $ticket->is_warranty_return || ! $this->authorizeTicketAccess($ticket, $actorUser)) {
                throw new HttpException(403, 'Tiket bukan retur garansi atau berada di luar cakupan Anda.');
            }
            if ($ticket->warranty_review_status === 'approved' && data_get($input, 'decision') !== 'disputed') {
                throw new HttpException(422, 'Retur garansi sudah divalidasi.');
            }
            if ($user->hasRole('supervisor') && data_get($input, 'decision') === 'disputed') {
                throw new HttpException(403, 'Supervisor hanya dapat menyetujui atau menolak retur garansi.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain.');
            }

            $before = $this->ticketAuditSnapshot($ticket);
            $decision = data_get($input, 'decision');
            $ticket->warranty_review_status = $decision;
            $ticket->warranty_review_reason = data_get($input, 'reason');
            $ticket->warranty_reviewed_by_user_id = $user->getKey();
            $ticket->warranty_reviewed_at = now();
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('warranty_return_reviewed', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'reason'));
            if ($period = $ticket->period) {
                $this->syncService->syncPeriodOperationalData($period);
            }

            return ['ticket' => $ticket];
        });

        return [
            'success' => true,
            'message' => 'Keputusan retur garansi berhasil dicatat.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee', 'originalTicket']), $actorUser),
        ];
    }

    public function deliver(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.deliver', 'tickets.supervise', 'tickets.manage']);
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        $positionCode = $user->employee?->position?->code;
        $managerOverride = $user->hasAnyRole(['owner_manager']);
        $supervisorOverride = $user->hasRole('supervisor');
        if ($positionCode !== 'POS-CS' && ! $managerOverride && ! $supervisorOverride) {
            throw new HttpException(403, 'Hanya Pelayan atau delegasi Supervisor/Manager yang dapat melakukan serah terima.');
        }
        Validator::make($input, [
            'recipient_type' => 'required|in:customer,representative',
            'recipient_name' => 'required|string|max:150',
            'delivery_notes' => 'nullable|string|max:2000',
            'rating' => 'prohibited',
            'comments' => 'prohibited',
            'feedback_channel' => 'prohibited',
            'follow_up_ontime' => 'prohibited',
            'row_version' => 'required|integer|min:1',
        ])->validate();

        $result = DB::transaction(function () use ($actorUser, $input, $id, $user, $managerOverride, $supervisorOverride) {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            $managerCanOverride = $managerOverride && $this->authorizeTicketAccess($ticket, $actorUser);
            $supervisorCanOverride = $supervisorOverride && $this->authorizeSupervisorPickup($ticket, $user);
            if (! $managerCanOverride && ! $supervisorCanOverride && ! $this->authorizePickup($ticket, $actorUser)) {
                throw new HttpException(403, 'Pelayan hanya dapat menyerahkan tiket yang ditanganinya atau tiket teknisi di bawah supervisinya.');
            }
            abort_if(($managerCanOverride || $supervisorCanOverride) && mb_strlen(trim((string) data_get($input, 'delivery_notes'))) < 3, 422, 'Delegasi penyerahan oleh Supervisor atau Manager wajib memiliki alasan.');
            $before = $this->ticketAuditSnapshot($ticket);
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum menyerahkan unit.');
            }
            try {
                $ticket->assertTransition('delivered');
            } catch (\RuntimeException $exception) {
                throw new HttpException(422, $exception->getMessage());
            }
            if ($ticket->status !== 'completed') {
                throw new HttpException(422, 'Unit hanya dapat diserahkan setelah servis berstatus selesai.');
            }
            if ($ticket->result_status === ServiceTicket::RESULT_SUCCESS
                && (float) $ticket->estimated_cost > 0
                && (float) $ticket->final_cost <= 0) {
                throw new HttpException(422, 'Biaya final belum tercatat atau belum ada pembayaran aktual.');
            }
            if ((float) $ticket->final_cost > 0
                && ! in_array($ticket->payment_status, ['paid', 'exception_approved'], true)) {
                throw new HttpException(422, 'Tiket hanya dapat diserahkan setelah lunas atau mendapat pengecualian pembayaran dari Manager.');
            }

            $ticket->status = 'delivered';
            $ticket->delivered_at = now();
            $ticket->delivery_recipient_type = data_get($input, 'recipient_type');
            $ticket->delivery_recipient_name = data_get($input, 'recipient_name');
            $ticket->delivered_by_employee_id = $user->employee?->id;
            $ticket->delivery_notes = data_get($input, 'delivery_notes');
            $ticket->warranty_expires_at = now()->addDays(7);
            $ticket->passcode_or_pattern = null;
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();

            $this->auditTicketMutation('delivered', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'delivery_notes'));

            $activePeriod = $ticket->period ?? KpiPeriod::active();
            if ($activePeriod) {
                $this->syncService->syncPeriodOperationalData($activePeriod);
            }

            return ['ticket' => $ticket];
        });

        $ticket = $result['ticket'];

        return [
            'success' => true,
            'message' => 'Unit berhasil diserahkan ke penerima. Feedback customer dikirim melalui QR atau link terpisah.',
            'data' => $this->formatTicket($ticket->fresh(['feedback', 'intakeEmployee', 'cashierEmployee']), $actorUser),
        ];
    }

    /**
     * Backward-compatible route name. Delivery and customer feedback are now separate actions.
     */
    public function pickupAndFeedback(User $actorUser, array $input, string $id): array
    {
        return $this->deliver($actorUser, $input, $id);
    }

    public function updateFeedbackFollowUp(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['feedback.followup.manage']);
        Validator::make($input, [
            'status' => 'required|in:contacted,completed,no_response,escalated,exception',
            'contact_channel' => 'required_if:status,contacted,completed|string|max:30',
            'outcome' => 'required_if:status,contacted,completed,no_response,escalated,exception|string|max:30',
            'response_summary' => 'required_if:status,contacted,completed,no_response,escalated,exception|string|max:2000',
            'evidence' => 'nullable|array',
            'evidence.*.type' => 'required_with:evidence|string|max:30',
            'evidence.*.reference' => 'required_with:evidence|string|max:500',
            'assigned_employee_id' => 'nullable|string|exists:employees,id',
            'assignment_reason' => 'required_with:assigned_employee_id|string|min:3|max:1000',
            'row_version' => 'required|integer|min:1',
        ])->validate();

        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $followUp = FeedbackFollowUp::with(['feedback', 'ticket'])
                ->whereKey($id)
                ->lockForUpdate()
                ->first();
            if (! $followUp || ! $followUp->ticket) {
                throw new HttpException(404, 'Tugas follow-up tidak ditemukan.');
            }

            $employee = $user->employee;
            $manager = $user->hasAnyRole(['owner_manager']);
            $supervisor = $user->hasRole('supervisor');
            $isAssignee = $employee?->status === 'active'
                && (string) $followUp->assigned_employee_id === (string) $employee->id;
            if (! $manager && ! $supervisor && ! $isAssignee) {
                throw new HttpException(403, 'Tugas follow-up bukan tanggung jawab Anda.');
            }
            if (! $this->authorizeTicketAccess($followUp->ticket, $actorUser)) {
                throw new HttpException(403, 'Tugas follow-up berada di luar cakupan Anda.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($followUp->row_version ?? 1)) {
                throw new HttpException(409, 'Tugas follow-up telah berubah oleh pengguna lain.');
            }
            if ($followUp->status === FeedbackFollowUp::STATUS_COMPLETED && ! $manager) {
                throw new HttpException(422, 'Follow-up yang sudah selesai tidak dapat diubah oleh Pelayan.');
            }

            if (filled(data_get($input, 'assigned_employee_id'))) {
                if (! $manager && ! $supervisor) {
                    throw new HttpException(403, 'Hanya Supervisor atau Manager yang dapat mendelegasikan follow-up.');
                }
                $assignee = Employee::query()
                    ->whereKey(data_get($input, 'assigned_employee_id'))
                    ->where('status', 'active')
                    ->where('branch_id', $followUp->ticket->branch_id)
                    ->whereHas('position', fn ($query) => $query->where('code', 'POS-CS'))
                    ->first();
                if (! $assignee) {
                    throw new HttpException(422, 'Delegasi harus diberikan kepada Pelayan aktif di cabang yang sama.');
                }
                $followUp->assigned_employee_id = $assignee->id;
                $followUp->assigned_by_user_id = $user->getKey();
            }

            $status = data_get($input, 'status');
            if ($status === FeedbackFollowUp::STATUS_NO_RESPONSE
                && (! $followUp->due_at || now()->lessThan($followUp->due_at->copy()->addWeekdays(2)))) {
                throw new HttpException(422, 'Status tidak terhubung baru boleh ditutup setelah 2 hari kerja tanpa respons.');
            }
            if ($status === FeedbackFollowUp::STATUS_COMPLETED && empty(data_get($input, 'evidence', []))) {
                throw new HttpException(422, 'Follow-up selesai wajib memiliki evidence.');
            }

            $before = $followUp->getAttributes();
            $followUp->status = $status;
            $followUp->contact_channel = data_get($input, 'contact_channel', $followUp->contact_channel);
            $followUp->outcome = data_get($input, 'outcome');
            $followUp->response_summary = data_get($input, 'response_summary');
            if (in_array($status, [FeedbackFollowUp::STATUS_CONTACTED, FeedbackFollowUp::STATUS_COMPLETED], true)) {
                $followUp->first_contacted_at ??= now();
            }
            if ($status === FeedbackFollowUp::STATUS_COMPLETED) {
                $followUp->completed_at = now();
                $followUp->completed_by_user_id = $user->getKey();
            }
            if (Arr::has($input, 'evidence')) {
                $followUp->evidence_json = collect(data_get($input, 'evidence', []))->map(fn (array $evidence): array => [
                    ...$evidence,
                    'recorded_at' => now()->toIso8601String(),
                    'recorded_by_user_id' => $user->getKey(),
                ])->all();
            }
            $followUp->row_version = (int) ($followUp->row_version ?? 1) + 1;
            $followUp->save();

            AuditEvent::log(
                action: 'api_feedback_follow_up_updated',
                subjectType: 'FeedbackFollowUp',
                subjectId: (string) $followUp->getKey(),
                before: $before,
                after: $followUp->getAttributes(),
                reason: data_get($input, 'assignment_reason', data_get($input, 'response_summary')),
                actorId: $user->getKey(),
            );

            return ['follow_up' => $followUp];
        });

        $period = $result['follow_up']->ticket?->period ?? KpiPeriod::active();
        if ($period) {
            $this->syncService->syncPeriodOperationalData($period);
        }

        return [
            'success' => true,
            'message' => 'Follow-up feedback berhasil dicatat.',
            'data' => $result['follow_up']->fresh(['feedback', 'ticket', 'assignedEmployee']),
        ];
    }

    public function addTechnicalEvidence(User $actorUser, array $input, string $id): array
    {
        $this->requireCapability($actorUser, ['tickets.evidence']);
        Validator::make($input, [
            'type' => 'required|string|max:30',
            'reference' => 'required|string|max:500',
            'note' => 'nullable|string|max:1000',
            'row_version' => 'required|integer|min:1',
        ])->validate();

        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        $result = DB::transaction(function () use ($actorUser, $input, $id, $user): array {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (! $ticket) {
                throw new HttpException(404, 'Tiket tidak ditemukan.');
            }
            if (! $this->authorizeTicketAccess($ticket, $actorUser)) {
                throw new HttpException(403, 'Tiket berada di luar cakupan Anda.');
            }
            if ($ticket->isFinal()) {
                throw new HttpException(422, 'Evidence tiket final tidak dapat ditambah melalui alur biasa.');
            }
            $position = $user->employee?->position?->code;
            $isTech = $position === 'POS-TEK' && (string) $ticket->technician_employee_id === (string) $user->employee?->id;
            if (! $isTech) {
                throw new HttpException(403, 'Hanya Teknisi penanggung jawab yang dapat menambah bukti teknis.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain.');
            }

            $before = $this->ticketAuditSnapshot($ticket);
            $evidence = is_array($ticket->technical_evidence_json) ? $ticket->technical_evidence_json : [];
            $evidence[] = [
                'type' => data_get($input, 'type'),
                'reference' => data_get($input, 'reference'),
                'note' => data_get($input, 'note'),
                'uploaded_at' => now()->toIso8601String(),
                'uploaded_by_user_id' => $user->getKey(),
                'uploaded_by_employee_id' => $user->employee?->id,
            ];
            $ticket->technical_evidence_json = $evidence;
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('technical_evidence_appended', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'note'));
            if ($period = $ticket->period) {
                $this->syncService->syncPeriodOperationalData($period);
            }

            return ['ticket' => $ticket];
        });

        return [
            'success' => true,
            'message' => 'Evidence teknis ditambahkan sebagai versi baru.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee']), $actorUser),
        ];
    }

    public function confirmSparepart(User $actorUser, array $input, string $requestId): array
    {
        $this->requireCapability($actorUser, ['spareparts.confirm']);
        Validator::make($input, ['row_version' => 'required|integer|min:1'])->validate();
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        $result = DB::transaction(function () use ($actorUser, $input, $requestId, $user): array {
            $reference = SparepartRequest::findOrFail($requestId);
            $ticket = ServiceTicket::whereKey($reference->service_ticket_id)->lockForUpdate()->firstOrFail();
            $sparepartRequest = SparepartRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
            $sparepartRequest->setRelation('ticket', $ticket);
            if (! $sparepartRequest) {
                throw new HttpException(404, 'Permintaan sparepart tidak ditemukan.');
            }
            $ticket = $sparepartRequest->ticket;
            $this->assertSparepartTicket($ticket, $input);
            if (! $ticket || ! $this->authorizeTechnicalWrite($ticket, $actorUser)) {
                throw new HttpException(403, 'Hanya Teknisi penanggung jawab yang dapat mengonfirmasi sparepart.');
            }
            if ($sparepartRequest->status !== 'fulfilled') {
                throw new HttpException(422, 'Sparepart baru dapat dikonfirmasi setelah dipenuhi Gudang.');
            }
            if ($sparepartRequest->confirmed_at) {
                throw new HttpException(422, 'Sparepart ini sudah dikonfirmasi sebelumnya.');
            }
            if (filled(data_get($input, 'row_version')) && (int) data_get($input, 'row_version') !== (int) ($ticket->row_version ?? 1)) {
                throw new HttpException(409, 'Tiket telah berubah oleh pengguna lain.');
            }

            $confirmedAt = now();
            $sparepartRequest->confirmed_at = $confirmedAt;
            $sparepartRequest->confirmed_by_employee_id = $user->employee?->id;
            $sparepartRequest->save();
            $waitMinutes = 0;
            $beforeTicket = $this->ticketAuditSnapshot($ticket);
            if (! $ticket->hasPendingSparepartRequests() && ! $ticket->hasUnconfirmedSparepartRequests()) {
                $requests = $ticket->sparepartRequests()->whereNotNull('confirmed_at')->orderBy('requested_at')->get();
                $end = null;
                foreach ($requests as $partRequest) {
                    $start = $partRequest->requested_at;
                    if (! $start) {
                        continue;
                    }
                    if ($end && $start->lt($end)) {
                        $start = $end;
                    }
                    if ($partRequest->confirmed_at->gt($start)) {
                        $waitMinutes += (int) $start->diffInMinutes($partRequest->confirmed_at);
                    }
                    if (! $end || $partRequest->confirmed_at->gt($end)) {
                        $end = $partRequest->confirmed_at;
                    }
                }
                $ticket->sparepart_wait_minutes = $waitMinutes;
                $baselineDueAt = $ticket->sla_baseline_due_at ?? $ticket->estimated_completion_at;
                $ticket->sla_due_at = $baselineDueAt?->copy()->addMinutes($waitMinutes);
                $ticket->estimated_completion_at = $ticket->sla_due_at;
            }
            $ticket->row_version++;
            $ticket->save();
            $this->auditTicketMutation('sparepart_confirmed', $ticket, $beforeTicket, actorId: $user->getKey());
            AuditEvent::log(
                action: 'api_sparepart_confirmed',
                subjectType: 'SparepartRequest',
                subjectId: (string) $sparepartRequest->getKey(),
                after: [
                    'confirmed_at' => $sparepartRequest->confirmed_at,
                    'confirmed_by_employee_id' => $sparepartRequest->confirmed_by_employee_id,
                    'wait_minutes' => $waitMinutes,
                ],
                actorId: $user->getKey(),
            );

            return ['request' => $sparepartRequest];
        });

        return [
            'success' => true,
            'message' => 'Sparepart dikonfirmasi teknisi. Tiket dapat dilanjutkan bila tidak ada blocker lain.',
            'data' => $this->formatSparepartRequest($result['request']->fresh(['sparepart', 'ticket', 'technician'])),
        ];
    }

    public function markSparepartUnavailable(User $actorUser, array $input, string $requestId): array
    {
        $this->requireCapability($actorUser, ['spareparts.fulfill']);
        Validator::make($input, [
            'note' => 'required|string|min:3|max:2000',
            'row_version' => 'required|integer|min:1',
        ])->validate();
        $user = $actorUser->loadMissing(['employee.position', 'roles']);
        if (! $this->isWarehouseAuthorized($user)) {
            throw new HttpException(403, 'Hanya Gudang atau manajemen yang dapat mencatat sparepart tidak tersedia.');
        }

        $result = DB::transaction(function () use ($actorUser, $input, $requestId, $user): array {
            $reference = SparepartRequest::findOrFail($requestId);
            $ticket = ServiceTicket::whereKey($reference->service_ticket_id)->lockForUpdate()->firstOrFail();
            $sparepartRequest = SparepartRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
            $sparepartRequest->setRelation('ticket', $ticket);
            if (! $sparepartRequest) {
                throw new HttpException(404, 'Permintaan sparepart tidak ditemukan.');
            }
            if (! $sparepartRequest->ticket || ! $this->authorizeTicketAccess($sparepartRequest->ticket, $actorUser)) {
                throw new HttpException(403, 'Permintaan berada di luar cakupan Anda.');
            }
            $this->assertSparepartTicket($ticket, $input);
            if ($sparepartRequest->status !== 'pending') {
                throw new HttpException(422, 'Permintaan sparepart sudah diproses sebelumnya.');
            }

            $ticket->row_version++;
            $ticket->save();
            $sparepartRequest->status = 'unavailable';
            $sparepartRequest->availability_note = data_get($input, 'note');
            $sparepartRequest->save();
            AuditEvent::log(
                action: 'api_sparepart_unavailable',
                subjectType: 'SparepartRequest',
                subjectId: (string) $sparepartRequest->getKey(),
                after: ['status' => 'unavailable', 'note' => $sparepartRequest->availability_note],
                reason: data_get($input, 'note'),
                actorId: $user->getKey(),
            );

            return ['request' => $sparepartRequest];
        });

        return [
            'success' => true,
            'message' => 'Ketersediaan sparepart dicatat. Keputusan menunggu Supervisor atau Manager.',
            'data' => $this->formatSparepartRequest($result['request']->fresh(['sparepart', 'ticket', 'technician'])),
        ];
    }

    public function spareparts(User $actorUser, array $input): array
    {
        $this->requireCapability($actorUser, ['spareparts.request', 'spareparts.manage', 'tickets.cost']);
        $user = $actorUser->loadMissing('employee');
        if (! $user->employee || $user->employee->status !== 'active') {
            throw new HttpException(403, 'Profil karyawan tidak aktif.');
        }

        $query = Sparepart::query();
        $query->forBranch($user->employee->branch_id);
        $spareparts = $query->orderBy('product_type')->orderBy('category')->orderBy('name')->get();

        return [
            'success' => true,
            'data' => $spareparts->map(fn ($p) => [
                'id' => $p->id,
                'code' => $p->code,
                'product_type' => $p->product_type,
                'product_type_label' => Sparepart::TYPES[$p->product_type] ?? ucfirst((string) $p->product_type),
                'name' => $p->name,
                'category' => $p->category,
                'stock' => $p->stock_quantity,
                'min_stock' => $p->min_stock_alert,
                'selling_price' => (float) $p->selling_price,
                'is_critical' => (bool) $p->is_critical,
            ]),
        ];
    }

    public function sparepartRequests(User $actorUser, array $input): array
    {
        $this->requireCapability($actorUser, ['spareparts.fulfill']);
        $user = $actorUser->loadMissing('employee');

        // Daftar permintaan pending hanya untuk Gudang / manager / supervisor.
        if (! $this->isWarehouseAuthorized($user)) {
            throw new HttpException(403, 'Hanya Gudang yang dapat melihat daftar permintaan sparepart.');
        }

        $requestsQuery = SparepartRequest::with(['sparepart', 'ticket', 'technician'])
            ->where('status', 'pending');
        $requestsQuery->whereHas('ticket', fn ($query) => $query->where('branch_id', $user->employee->branch_id))
            ->whereHas('sparepart', fn ($query) => $query->forBranch($user->employee->branch_id));
        $requests = $requestsQuery->orderByDesc('created_at')->get();

        return [
            'success' => true,
            'data' => $requests->map(fn ($r) => $this->formatSparepartRequest($r)),
        ];
    }

    private function formatSparepartRequest(SparepartRequest $request): array
    {
        return [
            'id' => $request->id,
            'quantity' => $request->quantity,
            'status' => $request->status,
            'warehouse_employee_id' => $request->warehouse_employee_id,
            'confirmed_at' => $request->confirmed_at?->toIso8601String(),
            'availability_note' => $request->availability_note,
            'sparepart' => $request->sparepart ? [
                'id' => $request->sparepart->id,
                'name' => $request->sparepart->name,
                'code' => $request->sparepart->code,
            ] : null,
            'ticket' => $request->ticket ? [
                'id' => $request->ticket->id,
                'ticket_number' => $request->ticket->ticket_number,
                'row_version' => (int) $request->ticket->row_version,
            ] : null,
            'requested_by' => $request->technician ? ['name' => $request->technician->name] : null,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    public function requestSparepart(User $actorUser, array $input): array
    {
        $this->requireCapability($actorUser, ['spareparts.request']);
        Validator::make($input, [
            'service_ticket_id' => 'required|exists:service_tickets,id',
            'sparepart_id' => 'required|exists:spareparts,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:2000',
            'row_version' => 'required|integer|min:1',
        ])->validate();
        $user = $actorUser;
        $partRequest = DB::transaction(function () use ($actorUser, $input, $user) {
            $ticket = ServiceTicket::whereKey(data_get($input, 'service_ticket_id'))->lockForUpdate()->firstOrFail();
            abort_unless($this->authorizeTechnicalWrite($ticket, $actorUser), 403, 'Hanya Teknisi penanggung jawab yang dapat meminta sparepart.');
            $this->assertSparepartTicket($ticket, $input);
            $part = Sparepart::whereKey(data_get($input, 'sparepart_id'))->firstOrFail();
            abort_unless($part->product_type === Sparepart::TYPE_SPAREPART && $part->belongsToBranch($ticket->branch_id), 422, 'Sparepart tidak tersedia untuk cabang tiket ini.');
            abort_if($ticket->sparepartRequests()->where('sparepart_id', $part->id)->whereIn('status', ['pending', 'fulfilled'])->whereNull('confirmed_at')->exists(), 422, 'Permintaan sparepart yang sama masih belum selesai.');
            $before = $this->ticketAuditSnapshot($ticket);
            $ticket->status = ServiceTicket::STATUS_WAITING_SPAREPART;
            $ticket->row_version++;
            $ticket->save();
            $this->auditTicketMutation('waiting_sparepart', $ticket, $before, actorId: $user->getKey(), reason: data_get($input, 'notes'));

            return SparepartRequest::create([
                'service_ticket_id' => $ticket->id,
                'sparepart_id' => $part->id,
                'technician_employee_id' => $ticket->technician_employee_id,
                'quantity' => data_get($input, 'quantity'),
                'status' => 'pending',
                'requested_at' => now(),
                'sla_deadline_at' => now()->addMinutes(15),
                'notes' => data_get($input, 'notes'),
            ]);
        });

        return ['success' => true, 'message' => 'Permintaan sparepart telah dikirim ke Gudang.', 'data' => $this->formatSparepartRequest($partRequest->fresh(['sparepart', 'ticket', 'technician']))];
    }

    public function fulfillSparepart(User $actorUser, array $input, string $requestId): array
    {
        $this->requireCapability($actorUser, ['spareparts.fulfill']);
        Validator::make($input, ['row_version' => 'required|integer|min:1'])->validate();
        $user = $actorUser;
        $partRequest = DB::transaction(function () use ($actorUser, $input, $requestId, $user) {
            // Ticket first, request second: every sparepart mutation locks in the same order.
            $reference = SparepartRequest::findOrFail($requestId);
            $ticket = ServiceTicket::whereKey($reference->service_ticket_id)->lockForUpdate()->firstOrFail();
            abort_unless($this->authorizeTicketAccess($ticket, $actorUser), 403, 'Permintaan berada di luar cakupan cabang Anda.');
            $this->assertSparepartTicket($ticket, $input);
            $partRequest = SparepartRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
            abort_unless($partRequest->status === 'pending', 422, 'Permintaan sparepart sudah diproses sebelumnya.');
            $part = Sparepart::whereKey($partRequest->sparepart_id)->lockForUpdate()->firstOrFail();
            abort_unless($part->belongsToBranch($ticket->branch_id) && $part->stock_quantity >= $partRequest->quantity, 422, 'Stok sparepart tidak mencukupi atau berada di luar cakupan cabang.');
            $before = $part->stock_quantity;
            $part->stock_quantity -= $partRequest->quantity;
            $part->save();
            $partRequest->update([
                'status' => 'fulfilled',
                'fulfilled_at' => now(),
                'warehouse_employee_id' => $user->employee->id,
                'availability_note' => null,
            ]);
            $ticket->row_version++;
            $ticket->save();
            StockMovement::create([
                'sparepart_id' => $part->id,
                'movement_type' => StockMovement::TYPE_REQUEST_OUT,
                'quantity' => -$partRequest->quantity,
                'stock_before' => $before,
                'stock_after' => $part->stock_quantity,
                'reference_type' => 'sparepart_request',
                'reference_id' => $partRequest->id,
                'note' => 'Penyerahan sparepart ke teknisi',
                'user_id' => $user->id,
            ]);
            AuditEvent::log(action: 'api_sparepart_fulfilled', subjectType: 'SparepartRequest', subjectId: (string) $partRequest->id, after: $partRequest->getAttributes(), actorId: $user->getKey());

            return $partRequest;
        });
        if ($period = $partRequest->ticket->period) {
            $this->syncService->syncPeriodOperationalData($period);
        }

        return ['success' => true, 'message' => 'Sparepart diserahkan ke Teknisi dan stok Gudang terpotong.', 'data' => $this->formatSparepartRequest($partRequest->fresh(['sparepart', 'ticket', 'technician']))];
    }

    private function assertSparepartTicket(ServiceTicket $ticket, array $input): void
    {
        abort_unless(in_array($ticket->status, [ServiceTicket::STATUS_DIAGNOSING, ServiceTicket::STATUS_WAITING_SPAREPART, ServiceTicket::STATUS_IN_PROGRESS], true), 422, 'Status tiket tidak dapat memproses sparepart.');
        abort_unless((int) data_get($input, 'row_version') === (int) $ticket->row_version, 409, 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum melanjutkan.');
        abort_if($ticket->is_warranty_return && $ticket->warranty_review_status !== 'approved', 422, 'Retur garansi harus divalidasi Supervisor sebelum dikerjakan.');
    }

    public function syncKpi(User $actorUser, array $input): array
    {
        $this->requireCapability($actorUser, ['kpi.sync']);
        $user = $actorUser->loadMissing('employee.position');
        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if (! $activePeriod) {
            throw new HttpException(404, 'Tidak ada periode KPI yang aktif.');
        }

        $res = $this->syncService->syncPeriodOperationalData($activePeriod);

        return $res;
    }

    public function formatTicket(ServiceTicket $t, User $actorUser, bool $full = false): array
    {
        $data = [
            'id' => $t->id,
            'ticket_number' => $t->ticket_number,
            'customer_name' => $t->customer_name,
            'customer_phone' => $t->customer_phone,
            'device_brand' => $t->device_brand,
            'device_model' => $t->device_model,
            'initial_complaint' => $t->initial_complaint,
            'estimated_cost' => (float) $t->estimated_cost,
            'service_category' => $t->service_category,
            'service_complexity' => $t->service_complexity,
            'sla_due_at' => $t->sla_due_at?->toIso8601String(),
            'sla_breached_at' => $t->sla_breached_at?->toIso8601String(),
            'sparepart_wait_minutes' => (int) ($t->sparepart_wait_minutes ?? 0),
            'status' => $t->status,
            'row_version' => (int) ($t->row_version ?? 1),
            'result_status' => $t->result_status,
            'pelayan_name' => $t->intakeEmployee?->name ?? 'Belum Dicatat',
            'cashier_name' => $t->cashierEmployee?->name ?? 'Belum Dicatat',
            'technician_name' => $t->technicianEmployee?->name ?? 'Belum Ditugaskan',
            'technician_employee_id' => $t->technician_employee_id,
            'is_warranty_return' => (bool) $t->is_warranty_return,
            'warranty_returned_from_ticket_id' => $t->warranty_returned_from_ticket_id,
            'customer_consent_status' => $t->customer_consent_status ?? 'pending',
            'payment_status' => $t->payment_status ?? 'unpaid',
            'paid_amount' => (float) ($t->paid_amount ?? 0),
            'delivery_recipient_type' => $t->delivery_recipient_type,
            'delivery_recipient_name' => $t->delivery_recipient_name,
            'warranty_expires_at' => $t->warranty_expires_at?->toIso8601String(),
            'warranty_review_status' => $t->warranty_review_status,
            'created_at' => $t->created_at->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'delivered_at' => $t->delivered_at?->toIso8601String(),
            'available_actions' => $this->availableTicketActions($t, $actorUser),
            'progress_statuses' => $this->authorizeTechnicalWrite($t, $actorUser) ? array_values(array_filter(
                array_unique([$t->status, ...(ServiceTicket::TRANSITIONS[$t->status] ?? [])]),
                fn (string $status): bool => in_array($status, ['diagnosing', 'waiting_sparepart', 'in_progress', 'qc_ready'], true),
            )) : [],
        ];

        if ($full) {
            $data['customer_address'] = $t->customer_address;
            $data['imei_or_serial'] = $t->imei_or_serial;
            $data['passcode_or_pattern'] = $t->passcode_or_pattern;
            $data['physical_condition'] = $t->physical_condition;
            $data['customer_needs'] = $t->customer_needs;
            $data['diagnosis_notes'] = $t->diagnosis_notes;
            $data['action_notes'] = $t->action_notes;
            $data['qc_checklist'] = $t->qc_checklist_json;
            $data['final_cost'] = (float) $t->final_cost;
            $data['technical_evidence'] = collect($t->technical_evidence_json ?? [])->map(fn (array $evidence, int $index): array => [
                ...Arr::except($evidence, ['file_path']),
                'index' => $index,
                'has_file' => ! empty($evidence['file_path']) && ($evidence['scan_status'] ?? null) === 'clean',
            ])->all();
            $data['unrepairable_reason'] = $t->unrepairable_reason;
            $data['customer_declined_reason'] = $t->customer_declined_reason;
            $data['cancellation_reason'] = $t->cancellation_reason;
            $data['delivery_notes'] = $t->delivery_notes;
            $data['customer_consent_notes'] = $t->customer_consent_notes;
            $data['payment_exception_type'] = $t->payment_exception_type;
            $data['payment_exception_reason'] = $t->payment_exception_reason;
            $data['warranty_review_reason'] = $t->warranty_review_reason;
            $data['sparepart_requests'] = $t->sparepartRequests->map(fn ($r) => [
                'id' => $r->id,
                'part_name' => $r->sparepart?->name,
                'part_code' => $r->sparepart?->code,
                'quantity' => $r->quantity,
                'status' => $r->status,
                'availability_note' => $r->availability_note,
                'sla_deadline_at' => $r->sla_deadline_at?->toIso8601String(),
                'fulfilled_at' => $r->fulfilled_at?->toIso8601String(),
                'confirmed_at' => $r->confirmed_at?->toIso8601String(),
            ]);
            $data['feedback'] = $t->feedback ? [
                'rating' => $t->feedback->rating,
                'comments' => $t->feedback->comments,
            ] : null;
        }

        return $data;
    }

    private function auditTicketMutation(
        string $action,
        ServiceTicket $ticket,
        ?array $before,
        ?int $actorId = null,
        ?string $reason = null,
    ): void {
        AuditEvent::log(
            action: "api_ticket_{$action}",
            subjectType: 'ServiceTicket',
            subjectId: (string) $ticket->getKey(),
            before: $before,
            after: $this->ticketAuditSnapshot($ticket),
            reason: $reason,
            actorId: $actorId,
        );
    }

    private function ticketAuditSnapshot(ServiceTicket $ticket): array
    {
        return array_intersect_key($ticket->getAttributes(), array_flip([
            'status',
            'result_status',
            'branch_id',
            'period_id',
            'intake_by_employee_id',
            'cashier_employee_id',
            'technician_employee_id',
            'row_version',
            'started_at',
            'completed_at',
            'delivered_at',
            'estimated_cost',
            'final_cost',
            'is_warranty_return',
            'customer_consent_status',
            'customer_consent_at',
            'payment_status',
            'paid_amount',
            'unrepairable_reason',
            'customer_declined_reason',
            'payment_exception_type',
            'payment_exception_approved_at',
            'delivery_recipient_type',
            'delivery_recipient_name',
            'delivered_by_employee_id',
            'warranty_expires_at',
            'warranty_review_status',
            'warranty_reviewed_at',
        ]));
    }

    public function scopeTickets(User $user): Builder
    {
        $user->loadMissing(['employee.position', 'roles']);
        $query = ServiceTicket::query();
        $employee = $user->employee;
        if (! CapabilityMatrix::has($user, 'tickets.view') || ! $employee?->branch_id) {
            return $query->whereRaw('1 = 0');
        }
        $query->where('branch_id', $employee->branch_id);
        if ($employee->position?->code === 'POS-TEK') {
            $query->where(fn ($scope) => $scope->where('technician_employee_id', $employee->id)
                ->orWhere(fn ($unassigned) => $unassigned->whereNull('technician_employee_id')->where('status', ServiceTicket::STATUS_INTAKE)));
        } elseif (CapabilityMatrix::has($user, 'tickets.supervise')) {
            $query->where(fn ($scope) => $scope->where('intake_by_employee_id', $employee->id)
                ->orWhereHas('intakeEmployee', fn ($staff) => $staff->where('supervisor_id', $employee->id))
                ->orWhereHas('technicianEmployee', fn ($staff) => $staff->where('supervisor_id', $employee->id))
                ->orWhere(fn ($unassigned) => $unassigned->whereNull('technician_employee_id')->where('status', ServiceTicket::STATUS_INTAKE)));
        }

        return $query;
    }

    private function authorizeTicketAccess(ServiceTicket $ticket, User $user): bool
    {

        return $user && $this->scopeTickets($user)->whereKey($ticket->getKey())->exists();
    }

    public function availableTicketActions(ServiceTicket $ticket, User $user): array
    {
        if (! $user || ! $this->authorizeTicketAccess($ticket, $user)) {
            return [];
        }
        $can = fn (string $capability): bool => CapabilityMatrix::has($user, $capability);
        $open = in_array($ticket->status, ServiceTicket::ESTIMATED_COST_EDITABLE_STATUSES, true);
        $technical = $this->authorizeTechnicalWrite($ticket, $user);
        $supervise = $can('tickets.supervise') || $can('tickets.manage');
        $intakeOwner = (string) $ticket->intake_by_employee_id === (string) $user->employee->id;
        $cashierOwner = ! $ticket->cashier_employee_id || (string) $ticket->cashier_employee_id === (string) $user->employee->id;
        $actions = [];
        if ($open && ($supervise || ($can('tickets.claim') && $ticket->technician_employee_id === null && $ticket->status === ServiceTicket::STATUS_INTAKE))) {
            $actions[] = 'assign';
        }
        if ($supervise && $ticket->is_warranty_return && $open) {
            $actions[] = 'warranty-review';
        }
        if ($technical && $open && (! $ticket->is_warranty_return || $ticket->warranty_review_status === 'approved')) {
            $actions[] = 'progress';
            $actions[] = 'technical-evidence';
            if (in_array(ServiceTicket::STATUS_COMPLETED, ServiceTicket::TRANSITIONS[$ticket->status] ?? [], true)) {
                $actions[] = 'complete';
            }
            if (in_array($ticket->status, [ServiceTicket::STATUS_DIAGNOSING, ServiceTicket::STATUS_WAITING_SPAREPART, ServiceTicket::STATUS_IN_PROGRESS], true)) {
                $actions[] = 'sparepart-request';
            }
            if ($ticket->hasUnconfirmedSparepartRequests()) {
                $actions[] = 'sparepart-confirm';
            }
        }
        if ($open && (($can('tickets.consent') && $intakeOwner) || $can('tickets.manage'))) {
            $actions[] = 'consent';
        }
        if ($ticket->status === ServiceTicket::STATUS_INTAKE && (($can('tickets.create') && $intakeOwner) || $can('tickets.manage'))) {
            $actions[] = 'cancel';
        }
        if ($open && $can('tickets.cost') && $cashierOwner) {
            $actions[] = 'estimated-cost';
        }
        if ($ticket->status === ServiceTicket::STATUS_COMPLETED) {
            if ($can('tickets.cost') && $cashierOwner) {
                $actions[] = 'final-cost';
            }
            if ($can('tickets.payment') && $cashierOwner) {
                $actions[] = 'payment';
            }
            if ($can('tickets.manage')) {
                $actions[] = 'payment-exception';
            }
            if (($can('tickets.deliver') && $intakeOwner) || $supervise) {
                $actions[] = 'deliver';
            }
        }
        if ($ticket->status === ServiceTicket::STATUS_DELIVERED) {
            if ($can('tickets.feedback-link')) {
                $actions[] = 'feedback-link';
            }
            if ($can('tickets.create') && $ticket->warranty_expires_at?->isFuture()) {
                $actions[] = 'warranty-return';
            }
            if ($can('feedback.followup.manage')) {
                $actions[] = 'feedback-followup';
            }
        }
        if ($can('spareparts.fulfill') && $open && $ticket->hasPendingSparepartRequests()) {
            $actions[] = 'sparepart-fulfill';
            $actions[] = 'sparepart-unavailable';
        }

        return $actions;
    }

    private function authorizeTechnicalWrite(ServiceTicket $ticket, User $user): bool
    {

        return $user && CapabilityMatrix::has($user, 'tickets.progress')
            && $this->authorizeTicketAccess($ticket, $user)
            && (string) $ticket->technician_employee_id === (string) $user->employee?->id;
    }

    private function isSparepartRequester(User $user): bool
    {
        return CapabilityMatrix::has($user, 'spareparts.request');
    }

    private function canViewSensitiveTicketFields(User $user, ServiceTicket $ticket): bool
    {
        return (CapabilityMatrix::has($user, 'tickets.progress') && (string) $ticket->technician_employee_id === (string) $user->employee->id)
            || (CapabilityMatrix::has($user, 'tickets.create') && (string) $ticket->intake_by_employee_id === (string) $user->employee->id);
    }

    private function authorizePickup(ServiceTicket $ticket, User $user): bool
    {
        return CapabilityMatrix::has($user, 'tickets.deliver')
            && (string) $ticket->intake_by_employee_id === (string) $user->employee?->id
            && $this->authorizeTicketAccess($ticket, $user);
    }

    private function authorizeSupervisorPickup(ServiceTicket $ticket, User $user): bool
    {
        return CapabilityMatrix::has($user, 'tickets.supervise') && $this->authorizeTicketAccess($ticket, $user);
    }

    private function isWarehouseAuthorized(User $user): bool
    {
        return CapabilityMatrix::has($user, 'spareparts.fulfill');
    }

    private function isCashierOrManagement(User $user): bool
    {
        return CapabilityMatrix::has($user, 'tickets.cost');
    }

    private function isServiceNoteAuthorized(User $user): bool
    {
        return CapabilityMatrix::has($user, 'tickets.create') || CapabilityMatrix::has($user, 'tickets.manage');
    }

    private function requireCapability(User $user, array $capabilities): void
    {
        abort_unless(count(array_intersect($capabilities, CapabilityMatrix::for($user))) > 0, 403, 'Anda tidak berwenang menjalankan tindakan ini.');
    }
}
