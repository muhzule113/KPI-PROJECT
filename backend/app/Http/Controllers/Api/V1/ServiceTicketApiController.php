<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CustomerFeedback;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\SparepartRequest;
use App\Models\StockMovement;
use App\Modules\Assessment\OperationalKpiSyncService;
use App\Support\ServiceTicketNumber;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceTicketApiController extends Controller
{
    public function __construct(
        protected OperationalKpiSyncService $syncService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        $employee = $user->employee;

        $query = ServiceTicket::with(['technicianEmployee', 'intakeEmployee', 'cashierEmployee', 'branch', 'feedback'])
            ->orderByDesc('id');

        // Filter by role and branch scope
        if ($user->hasRole('employee') && $employee?->position?->code === 'POS-TEK') {
            $query->where('technician_employee_id', $employee->id);
        } elseif ($employee?->branch_id && !$user->hasAnyRole(['owner_manager', 'super_admin'])) {
            $query->where('branch_id', $employee->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $tickets = $query->limit(50)->get();

        return response()->json([
            'success' => true,
            'data' => $tickets->map(fn($t) => $this->formatTicket($t)),
        ]);
    }

    public function pelayanEmployees(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        if (!$this->isServiceNoteAuthorized($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Pelayan, Kasir, atau manajemen yang dapat memilih Pelayan.',
            ], 403);
        }

        $employees = Employee::with('position')
            ->where('status', 'active')
            ->when(
                $user->employee?->branch_id
                && !$user->hasAnyRole(['owner_manager', 'super_admin']),
                fn ($query) => $query->where('branch_id', $user->employee->branch_id)
            )
            ->whereHas('position', fn ($query) => $query->where('code', 'POS-CS'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $employees->map(fn($employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_number' => $employee->employee_number,
                'position' => 'Pelayan',
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        $employee = $user->employee;

        // Pelayan dapat membuat tiket langsung; Kasir/manajemen tetap dapat membuat nota sebagai fallback operasional.
        if (!$this->isServiceNoteAuthorized($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Pelayan, Kasir, atau manajemen yang dapat membuat Nota Servis.',
            ], 403);
        }

        $pelayanRequired = $employee?->position?->code === 'POS-CS' ? 'nullable' : 'required';
        $request->validate([
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
            'estimated_cost' => 'nullable|numeric',
            'estimated_completion_at' => 'nullable|date',
            'technician_employee_id' => 'nullable|string',
            'pelayan_employee_id' => [$pelayanRequired, 'string', 'exists:employees,id'],
        ]);

        $pelayanEmployeeId = $employee?->position?->code === 'POS-CS'
            ? $employee->id
            : $request->pelayan_employee_id;
        $pelayanQuery = Employee::whereKey($pelayanEmployeeId)
            ->where('status', 'active')
            ->whereHas('position', fn($query) => $query->where('code', 'POS-CS'));
        if (!$user->hasAnyRole(['owner_manager', 'super_admin']) && $employee?->branch_id) {
            $pelayanQuery->where('branch_id', $employee->branch_id);
        }
        $pelayan = $pelayanQuery->first();

        if ($request->filled('technician_employee_id')) {
            $technicianQuery = Employee::whereKey($request->technician_employee_id)
                ->where('status', 'active')
                ->whereHas('position', fn($query) => $query->where('code', 'POS-TEK'));
            if (!$user->hasAnyRole(['owner_manager', 'super_admin']) && $employee?->branch_id) {
                $technicianQuery->where('branch_id', $employee->branch_id);
            }
            if (!$technicianQuery->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Teknisi yang dipilih bukan Teknisi aktif pada cakupan cabang Anda.',
                ], 422);
            }
        }
        if (!$pelayan) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan yang dipilih bukan Pelayan aktif.',
            ], 422);
        }

        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();

        $ticket = DB::transaction(function () use ($request, $employee, $pelayan, $activePeriod, $user) {
            $ticketNumber = ServiceTicketNumber::next();

            $ticket = ServiceTicket::create([
                'ticket_number' => $ticketNumber,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_address' => $request->customer_address,
                'device_brand' => $request->device_brand,
                'device_model' => $request->device_model,
                'imei_or_serial' => $request->imei_or_serial,
                'passcode_or_pattern' => $request->passcode_or_pattern,
                'physical_condition' => $request->physical_condition,
                'initial_complaint' => $request->initial_complaint,
                'customer_needs' => $request->customer_needs,
                'estimated_cost' => $request->estimated_cost ?? 0,
                'estimated_completion_at' => $request->estimated_completion_at ?? now()->addDays(2),
                'branch_id' => $employee?->branch_id,
                'period_id' => $activePeriod?->id,
                'intake_by_employee_id' => $pelayan->id,
                'cashier_employee_id' => $employee?->position?->code === 'POS-KSR' ? $employee->id : null,
                'technician_employee_id' => $request->technician_employee_id,
                'status' => 'intake',
                'result_status' => 'pending',
            ]);

            $this->auditTicketMutation('created', $ticket, null, actorId: $user->getKey());

            if ($activePeriod) {
                $this->syncService->syncPeriodOperationalData($activePeriod);
            }

            return $ticket;
        });

        return response()->json([
            'success' => true,
            'message' => "Nota Servis {$ticket->ticket_number} berhasil dibuat.",
            'data' => $this->formatTicket($ticket->fresh(['technicianEmployee', 'intakeEmployee', 'cashierEmployee'])),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $ticket = ServiceTicket::with([
            'technicianEmployee',
            'intakeEmployee',
            'cashierEmployee',
            'branch',
            'sparepartRequests.sparepart',
            'feedback',
        ])->where('id', $id)->first();

        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }

        // Teknisi hanya boleh lihat tiket miliknya
        if (!$this->authorizeTicketAccess($ticket)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki akses ke tiket ini.'], 403);
        }

        $data = $this->formatTicket($ticket, true);
        if (!$this->canViewSensitiveTicketFields($request->user())) {
            unset($data['imei_or_serial'], $data['passcode_or_pattern']);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function assignTechnician(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'technician_employee_id' => 'nullable|string',
            'row_version' => 'nullable|integer|min:1',
        ]);
        $actor = $request->user()->loadMissing(['employee.position']);
        $employee = $actor->employee;

        $result = DB::transaction(function () use ($request, $id, $actor, $employee) {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (!$ticket) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404)];
            }
            if ($employee?->branch_id && !$actor->hasAnyRole(['owner_manager', 'super_admin'])
                && (string) $ticket->branch_id !== (string) $employee->branch_id) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket berada di luar cakupan cabang Anda.'], 403)];
            }
            if ($ticket->isFinal()) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket sudah final, tidak bisa diubah teknisi.'], 422)];
            }
            if ($request->filled('row_version') && (int) $request->input('row_version') !== (int) ($ticket->row_version ?? 1)) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum menugaskan teknisi.'], 409)];
            }

            $isTechnician = $employee?->position?->code === 'POS-TEK';
            $technicianId = $request->input('technician_employee_id') ?? $employee?->id;
            if ($isTechnician && (string) $technicianId !== (string) $employee->id) {
                return ['response' => response()->json(['success' => false, 'message' => 'Teknisi hanya bisa mengambil tiket untuk dirinya sendiri.'], 403)];
            }
            if ($isTechnician && $ticket->technician_employee_id !== null && (string) $ticket->technician_employee_id !== (string) $employee->id) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket ini sudah ditugaskan ke teknisi lain.'], 403)];
            }
            if (!$isTechnician && !in_array($employee?->position?->code, ['POS-CS', 'POS-GUD', 'POS-KSR'], true)
                && !$actor->hasAnyRole(['owner_manager', 'super_admin', 'supervisor'])) {
                return ['response' => response()->json(['success' => false, 'message' => 'Anda tidak berhak menugaskan teknisi.'], 403)];
            }
            if (!$technicianId) {
                return ['response' => response()->json(['success' => false, 'message' => 'Teknisi tidak ditemukan pada akun Anda.'], 422)];
            }

            $before = $this->ticketAuditSnapshot($ticket);

            $technician = Employee::whereKey($technicianId)
                ->where('status', 'active')
                ->where('branch_id', $ticket->branch_id)
                ->whereHas('position', fn ($query) => $query->where('code', 'POS-TEK'))
                ->first();
            if (!$technician) {
                return ['response' => response()->json(['success' => false, 'message' => 'Karyawan terpilih bukan Teknisi.'], 422)];
            }

            $ticket->technician_employee_id = $technician->id;
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $this->auditTicketMutation('assigned', $ticket, $before, actorId: $actor->getKey());
            return ['ticket' => $ticket, 'technician' => $technician];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        return response()->json([
            'success' => true,
            'message' => "Tiket {$result['ticket']->ticket_number} ditugaskan ke {$result['technician']->name}.",
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee'])),
        ]);
    }

    public function updateProgress(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'diagnosis_notes' => 'nullable|string',
            'action_notes' => 'nullable|string',
            'status' => 'nullable|string|in:diagnosing,waiting_sparepart,in_progress,qc_ready',
            'row_version' => 'nullable|integer|min:1',
        ]);

        $result = DB::transaction(function () use ($request, $id) {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (!$ticket) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404)];
            }
            if (!$this->authorizeTechnicalWrite($ticket)) {
                return ['response' => response()->json(['success' => false, 'message' => 'Hanya teknisi yang ditugaskan yang dapat mengubah progress tiket ini.'], 403)];
            }
            $before = $this->ticketAuditSnapshot($ticket);
            if ($ticket->isFinal()) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket sudah final dan tidak dapat diubah.'], 422)];
            }
            if ($request->filled('row_version') && (int) $request->input('row_version') !== (int) ($ticket->row_version ?? 1)) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum menyimpan progress.'], 409)];
            }

            $nextStatus = $request->input('status');

            if (!$nextStatus && !$request->hasAny(['diagnosis_notes', 'action_notes'])) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tidak ada perubahan progress yang dikirim.'], 422)];
            }
            if ($nextStatus) {
                try {
                    $ticket->assertTransition($nextStatus);
                } catch (\RuntimeException $exception) {
                    return ['response' => response()->json(['success' => false, 'message' => $exception->getMessage()], 422)];
                }
                $ticket->status = $nextStatus;
            }
            $ticket->diagnosis_notes = $request->input('diagnosis_notes', $ticket->diagnosis_notes);
            $ticket->action_notes = $request->input('action_notes', $ticket->action_notes);
            if (!$ticket->started_at && in_array($ticket->status, ['in_progress', 'diagnosing'], true)) {
                $ticket->started_at = now();
            }
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $ticket->refresh();
            $this->auditTicketMutation('progress_updated', $ticket, $before, actorId: $request->user()?->getKey());
            return ['ticket' => $ticket];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        return response()->json([
            'success' => true,
            'message' => 'Progress tiket berhasil diperbarui.',
            'data' => $this->formatTicket($result['ticket']->fresh(['technicianEmployee', 'intakeEmployee'])),
        ]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'result_status' => 'required|in:success,unrepairable,warranty_return',
            'diagnosis_notes' => 'required|string',
            'action_notes' => 'required|string',
            'qc_checklist' => 'required|array',
            'qc_checklist.*' => 'required|boolean',
            'final_cost' => 'nullable|numeric',
            'row_version' => 'nullable|integer|min:1',
        ]);

        $result = DB::transaction(function () use ($request, $id) {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (!$ticket) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404)];
            }
            if (!$this->authorizeTechnicalWrite($ticket)) {
                return ['response' => response()->json(['success' => false, 'message' => 'Hanya teknisi yang ditugaskan yang dapat menyelesaikan tiket ini.'], 403)];
            }
            $before = $this->ticketAuditSnapshot($ticket);
            if ($request->filled('row_version') && (int) $request->input('row_version') !== (int) ($ticket->row_version ?? 1)) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum menyelesaikan tiket.'], 409)];
            }
            $requiredQcKeys = ['display', 'touch', 'camera', 'mic', 'speaker', 'cellular', 'charging', 'biometric'];
            $qcChecklist = $request->input('qc_checklist');
            if (array_key_exists('face_id', $qcChecklist) && !array_key_exists('biometric', $qcChecklist)) {
                $qcChecklist['biometric'] = $qcChecklist['face_id'];
            }
            if (array_diff($requiredQcKeys, array_keys($qcChecklist))) {
                return ['response' => response()->json([
                    'success' => false,
                    'message' => 'Checklist QC wajib memuat seluruh komponen pengujian.',
                ], 422)];
            }
            try {
                if ($ticket->status !== 'qc_ready') {
                    return ['response' => response()->json([
                        'success' => false,
                        'message' => 'Tiket harus berstatus Siap QC sebelum diselesaikan.',
                    ], 422)];
                }
                $ticket->assertTransition('completed');

            } catch (\RuntimeException $exception) {
                return ['response' => response()->json(['success' => false, 'message' => $exception->getMessage()], 422)];
            }

            $ticket->result_status = $request->input('result_status');
            $ticket->is_warranty_return = $ticket->result_status === 'warranty_return';
            $ticket->diagnosis_notes = $request->input('diagnosis_notes');
            $ticket->action_notes = $request->input('action_notes');
            $ticket->qc_checklist_json = $qcChecklist;
            $ticket->final_cost = $request->input('final_cost', $ticket->estimated_cost);
            $ticket->completed_at = now();
            $ticket->status = 'completed';
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();

            $this->auditTicketMutation('completed', $ticket, $before, actorId: $request->user()?->getKey());

            $activePeriod = $ticket->period ?? KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
            if ($activePeriod) {
                $this->syncService->syncPeriodOperationalData($activePeriod);
            }

            return ['ticket' => $ticket];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        $ticket = $result['ticket'];

        return response()->json([
            'success' => true,
            'message' => 'Pengerjaan servis berhasil diselesaikan dan dicatat ke metrik KPI Teknisi!',
            'data' => $this->formatTicket($ticket->fresh(['technicianEmployee', 'intakeEmployee'])),
        ]);
    }

    public function pickupAndFeedback(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comments' => 'nullable|string',
            'feedback_channel' => 'nullable|string',
            'follow_up_ontime' => 'sometimes|boolean',
            'row_version' => 'nullable|integer|min:1',
        ]);

        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        $positionCode = $user->employee?->position?->code;
        $managerOverride = $user->hasAnyRole(['owner_manager', 'super_admin']);
        $supervisorOverride = $user->hasRole('supervisor');
        if ($positionCode !== 'POS-CS' && !$managerOverride && !$supervisorOverride) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Pelayan yang dapat melakukan serah terima dan mencatat CSAT.',
            ], 403);
        }

        $result = DB::transaction(function () use ($request, $id, $user, $managerOverride, $supervisorOverride) {
            $ticket = ServiceTicket::whereKey($id)->lockForUpdate()->first();
            if (!$ticket) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404)];
            }
            $managerCanOverride = $managerOverride && $this->authorizeTicketAccess($ticket);
            $supervisorCanOverride = $supervisorOverride && $this->authorizeSupervisorPickup($ticket, $user);
            if (!$managerCanOverride && !$supervisorCanOverride && !$this->authorizePickup($ticket)) {
                return ['response' => response()->json(['success' => false, 'message' => 'Pelayan hanya dapat menyerahkan tiket yang ditanganinya atau tiket teknisi di bawah supervisinya.'], 403)];
            }
            $before = $this->ticketAuditSnapshot($ticket);
            if ($request->filled('row_version') && (int) $request->input('row_version') !== (int) ($ticket->row_version ?? 1)) {
                return ['response' => response()->json(['success' => false, 'message' => 'Tiket telah berubah oleh pengguna lain. Muat ulang sebelum menyerahkan unit.'], 409)];
            }
            try {
                $ticket->assertTransition('delivered');
            } catch (\RuntimeException $exception) {
                return ['response' => response()->json(['success' => false, 'message' => $exception->getMessage()], 422)];
            }
            if ($ticket->status !== 'completed') {
                return ['response' => response()->json(['success' => false, 'message' => 'Unit hanya dapat diserahkan setelah servis berstatus selesai.'], 422)];
            }
            if ($ticket->feedback()->exists()) {
                return ['response' => response()->json(['success' => false, 'message' => 'Feedback tiket sudah tercatat dan tidak dapat ditimpa.'], 422)];
            }

            $ticket->status = 'delivered';
            $ticket->delivered_at = now();
            $ticket->row_version = (int) ($ticket->row_version ?? 1) + 1;
            $ticket->save();
            $feedback = CustomerFeedback::create([
                'service_ticket_id' => $ticket->id,
                'cs_employee_id' => $user->employee?->id,
                'customer_name' => $ticket->customer_name,
                'rating' => $request->input('rating'),
                'comments' => $request->input('comments'),
                'follow_up_ontime' => $request->boolean('follow_up_ontime'),
                'feedback_channel' => $request->input('feedback_channel', 'in_store'),
            ]);

            $this->auditTicketMutation('delivered', $ticket, $before, actorId: $user->getKey());
            AuditEvent::log(
                action: 'api_feedback_created',
                subjectType: 'CustomerFeedback',
                subjectId: (string) $feedback->getKey(),
                after: [
                    'service_ticket_id' => $ticket->getKey(),
                    'rating' => $feedback->rating,
                    'feedback_channel' => $feedback->feedback_channel,
                ],
                actorId: $user->getKey(),
            );

            $activePeriod = $ticket->period ?? KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
            if ($activePeriod) {
                $this->syncService->syncPeriodOperationalData($activePeriod);
            }

            return ['ticket' => $ticket];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        $ticket = $result['ticket'];

        return response()->json([
            'success' => true,
            'message' => 'Unit berhasil diserahkan ke pelanggan dan rating kepuasan Pelayan tercatat ke KPI.',
            'data' => $this->formatTicket($ticket->fresh(['feedback', 'intakeEmployee', 'cashierEmployee'])),
        ]);
    }

    public function spareparts(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('employee');
        if (!$user->employee || $user->employee->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Profil karyawan tidak aktif.'], 403);
        }

        $query = Sparepart::query();
        if (!$user->hasAnyRole(['owner_manager', 'super_admin'])) {
            $query->forBranch($user->employee->branch_id);
        }
        $spareparts = $query->orderBy('product_type')->orderBy('category')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $spareparts->map(fn($p) => [
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
        ]);
    }

    public function sparepartRequests(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('employee');

        // Daftar permintaan pending hanya untuk Gudang / manager / supervisor.
        if (!$this->isWarehouseAuthorized($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Gudang yang dapat melihat daftar permintaan sparepart.',
            ], 403);
        }

        $requestsQuery = SparepartRequest::with(['sparepart', 'ticket', 'technician'])
            ->where('status', 'pending');
        if (!$user->hasAnyRole(['owner_manager', 'super_admin'])) {
            $requestsQuery->whereHas('ticket', fn ($query) => $query->where('branch_id', $user->employee->branch_id))
                ->whereHas('sparepart', fn ($query) => $query->where('branch_id', $user->employee->branch_id)->orWhereNull('branch_id'));
        }
        $requests = $requestsQuery->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => $requests->map(fn($r) => [
                'id' => $r->id,
                'quantity' => $r->quantity,
                'status' => $r->status,
                'sparepart' => $r->sparepart ? [
                    'id' => $r->sparepart->id,
                    'name' => $r->sparepart->name,
                    'code' => $r->sparepart->code,
                ] : null,
                'ticket' => $r->ticket ? [
                    'id' => $r->ticket->id,
                    'ticket_number' => $r->ticket->ticket_number,
                ] : null,
                'requested_by' => $r->technician ? [
                    'name' => $r->technician->name,
                ] : null,
                'created_at' => $r->created_at?->toISOString(),
            ]),
        ]);
    }

    public function requestSparepart(Request $request): JsonResponse
    {
        $request->validate([
            'service_ticket_id' => 'required|exists:service_tickets,id',
            'sparepart_id' => 'required|exists:spareparts,id',
            'quantity' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user()->loadMissing('employee');

        $ticket = ServiceTicket::where('id', $request->service_ticket_id)->first();
        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }

        // Teknisi hanya boleh request sparepart untuk tiket miliknya (ownership check).
        if (!$this->authorizeTechnicalWrite($ticket)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki akses ke tiket ini.'], 403);
        }
        if (!in_array($ticket->status, ['waiting_sparepart', 'in_progress'], true)) {
            return response()->json(['success' => false, 'message' => 'Tiket belum berada pada status yang dapat meminta sparepart.'], 422);
        }
        if (!$user->employee || $user->employee->status !== 'active' || $user->employee->position?->code !== 'POS-TEK') {
            return response()->json(['success' => false, 'message' => 'Hanya Teknisi aktif yang dapat meminta sparepart.'], 403);
        }

        try {
            $req = DB::transaction(function () use ($request, $ticket, $user) {
                $lockedTicket = ServiceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                if (!$this->authorizeTechnicalWrite($lockedTicket)) {
                    throw new Exception('Hanya teknisi yang ditugaskan yang dapat meminta sparepart.');
                }
                if (!in_array($lockedTicket->status, ['waiting_sparepart', 'in_progress'], true)) {
                    throw new Exception('Tiket belum berada pada status yang dapat meminta sparepart.');
                }
                $sparepart = Sparepart::whereKey($request->sparepart_id)
                    ->lockForUpdate()
                    ->first();
                if (!$sparepart || !$sparepart->belongsToBranch($lockedTicket->branch_id)) {
                    throw new Exception('Sparepart tidak tersedia untuk cabang tiket ini.');
                }
                if (SparepartRequest::where('service_ticket_id', $lockedTicket->id)
                    ->where('sparepart_id', $sparepart->id)
                    ->where('status', 'pending')
                    ->exists()) {
                    throw new Exception('Permintaan sparepart yang sama masih menunggu diproses.');
                }

                return SparepartRequest::create([
                    'service_ticket_id' => $lockedTicket->id,
                    'sparepart_id' => $sparepart->id,
                    'technician_employee_id' => $user->employee->id,
                    'quantity' => $request->quantity ?? 1,
                    'status' => 'pending',
                    'requested_at' => now(),
                    'notes' => $request->notes,
                ]);
            });
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Permintaan sparepart telah dikirim ke Gudang.',
            'data' => $req->fresh(['sparepart']),
        ]);
    }

    public function fulfillSparepart(Request $request, string $requestId): JsonResponse
    {
        $req = SparepartRequest::with('sparepart')->where('id', $requestId)->first();
        if (!$req) {
            return response()->json(['success' => false, 'message' => 'Permintaan tidak ditemukan.'], 404);
        }

        $user = $request->user()->loadMissing('employee');

        // Penyerahan sparepart hanya boleh dilakukan Gudang / manager / supervisor.
        if (!$this->isWarehouseAuthorized($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Gudang yang dapat menyerahkan sparepart.',
            ], 403);
        }

        try {
            DB::transaction(function () use ($req, $user) {
                $lockedRequest = SparepartRequest::with('ticket')
                    ->whereKey($req->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedRequest->status !== 'pending') {
                    throw new Exception('Permintaan sparepart sudah diproses sebelumnya.');
                }
                if (!$lockedRequest->ticket || !in_array($lockedRequest->ticket->status, ['waiting_sparepart', 'in_progress'], true)) {
                    throw new Exception('Tiket belum berada pada status yang dapat menerima sparepart.');
                }
                if ($user->employee?->branch_id
                    && (string) $lockedRequest->ticket->branch_id !== (string) $user->employee->branch_id
                    && !$user->hasAnyRole(['owner_manager', 'super_admin'])) {
                    throw new Exception('Permintaan berada di luar cakupan cabang Anda.');
                }

                $sparepartQuery = Sparepart::whereKey($lockedRequest->sparepart_id);
                if (!$user->hasAnyRole(['owner_manager', 'super_admin'])) {
                    $sparepartQuery->where(function ($query) use ($user) {
                        $query->where('branch_id', $user->employee?->branch_id)
                            ->orWhereNull('branch_id');
                    });
                }
                $sparepart = $sparepartQuery->lockForUpdate()->first();
                if (!$sparepart || $sparepart->stock_quantity < $lockedRequest->quantity) {
                    throw new Exception('Stok sparepart tidak mencukupi atau berada di luar cakupan cabang.');
                }

                $before = $sparepart->stock_quantity;
                $sparepart->stock_quantity -= $lockedRequest->quantity;
                $sparepart->save();
                $lockedRequest->status = 'fulfilled';
                $lockedRequest->fulfilled_at = now();
                $lockedRequest->warehouse_employee_id = $user->employee?->id;
                $lockedRequest->save();

                StockMovement::create([
                    'sparepart_id' => $sparepart->id,
                    'movement_type' => StockMovement::TYPE_REQUEST_OUT,
                    'quantity' => -1 * $lockedRequest->quantity,
                    'stock_before' => $before,
                    'stock_after' => $sparepart->stock_quantity,
                    'reference_type' => 'sparepart_request',
                    'reference_id' => $lockedRequest->id,
                    'note' => 'Penyerahan sparepart ke teknisi',
                    'user_id' => $user->id,
                ]);
            });
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $req->refresh();
        $req->load('sparepart');

        // Auto-sync Gudang KPI
        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if ($activePeriod) {
            $this->syncService->syncPeriodOperationalData($activePeriod);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sparepart berhasil diserahkan ke teknisi dan stok gudang terpotong.',
            'data' => $req->fresh(['sparepart']),
        ]);
    }

    public function syncKpi(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('employee.position');
        if (!$user->hasAnyRole(['owner_manager', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak berwenang menjalankan sinkronisasi KPI.',
            ], 403);
        }

        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if (!$activePeriod) {
            return response()->json(['success' => false, 'message' => 'Tidak ada periode KPI yang aktif.'], 404);
        }

        $res = $this->syncService->syncPeriodOperationalData($activePeriod);

        return response()->json($res);
    }

    protected function formatTicket(ServiceTicket $t, bool $full = false): array
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
            'status' => $t->status,
            'result_status' => $t->result_status,
            'pelayan_name' => $t->intakeEmployee?->name ?? 'Belum Dicatat',
            'cashier_name' => $t->cashierEmployee?->name ?? 'Belum Dicatat',
            'technician_name' => $t->technicianEmployee?->name ?? 'Belum Ditugaskan',
            'technician_employee_id' => $t->technician_employee_id,
            'created_at' => $t->created_at->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
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
            $data['sparepart_requests'] = $t->sparepartRequests->map(fn($r) => [
                'id' => $r->id,
                'part_name' => $r->sparepart?->name,
                'part_code' => $r->sparepart?->code,
                'quantity' => $r->quantity,
                'status' => $r->status,
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
    ): void {
        AuditEvent::log(
            action: "api_ticket_{$action}",
            subjectType: 'ServiceTicket',
            subjectId: (string) $ticket->getKey(),
            before: $before,
            after: $this->ticketAuditSnapshot($ticket),
            actorId: $actorId ?? auth()->id(),
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
            'final_cost',
            'is_warranty_return',
        ]));
    }

    /**
     * Otentikasi akses tiket: teknisi hanya boleh akses tiket miliknya.
     * Pelayan (intake/feedback), Gudang (fulfill), manager & super admin boleh semua.
     */
    private function authorizeTicketAccess(ServiceTicket $ticket): bool
    {
        $user = auth()->user();
        if (!$user) return false;

        $employee = $user->employee;
        if (!$employee || $employee->status !== 'active') return false;
        if ($user->hasRole('super_admin')) return true;
        if ($employee->branch_id && (string) $ticket->branch_id !== (string) $employee->branch_id) return false;

        if ($user->hasRole('owner_manager')) return true;
        if ($user->hasRole('supervisor')) {
            return (string) $ticket->intake_by_employee_id === (string) $employee->id
                || (string) $ticket->technician_employee_id === (string) $employee->id
                || Employee::whereKey($ticket->technician_employee_id)
                    ->where('supervisor_id', $employee->id)
                    ->exists();
        }

        $positionCode = $employee->position?->code;
        if (in_array($positionCode, ['POS-CS', 'POS-KSR', 'POS-GUD'], true)) return true;
        return $positionCode === 'POS-TEK'
            && (string) $ticket->technician_employee_id === (string) $employee->id;
    }

    private function authorizeTechnicalWrite(ServiceTicket $ticket): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        $employee = $user->employee;
        if (!$employee || $employee->status !== 'active') {
            return false;
        }
        if ($user->hasRole('super_admin')) {
            return true;
        }
        if ($employee->branch_id && (string) $ticket->branch_id !== (string) $employee->branch_id) {
            return false;
        }
        if ($user->hasRole('owner_manager')) {
            return true;
        }

        return $employee->position?->code === 'POS-TEK'
            && (string) $ticket->technician_employee_id === (string) $employee->id;
    }

    private function canViewSensitiveTicketFields($user): bool
    {
        if ($user->hasAnyRole(['super_admin', 'owner_manager', 'supervisor'])) {
            return true;
        }

        return $user->employee?->status === 'active'
            && $user->employee?->position?->code === 'POS-TEK';
    }

    private function authorizePickup(ServiceTicket $ticket): bool
    {
        $user = auth()->user();
        $employee = $user?->employee;

        return $employee?->status === 'active'
            && $employee->position?->code === 'POS-CS'
            && (string) $ticket->intake_by_employee_id === (string) $employee->id
            && (!$employee->branch_id || (string) $ticket->branch_id === (string) $employee->branch_id);
    }

    private function authorizeSupervisorPickup(ServiceTicket $ticket, $user): bool
    {
        $employee = $user->employee;
        if (!$employee || $employee->status !== 'active' || !$employee->branch_id
            || (string) $ticket->branch_id !== (string) $employee->branch_id) {
            return false;
        }

        return $ticket->technician_employee_id !== null
            && Employee::whereKey($ticket->technician_employee_id)
                ->where('supervisor_id', $employee->id)
                ->where('status', 'active')
                ->exists();
    }

    /**
     * Akses warehouse (sparepart): hanya Gudang / manager / supervisor yang berwenang.
     */
    private function isWarehouseAuthorized($user): bool
    {
        if ($user->hasRole('super_admin') || $user->hasRole('owner_manager')) return true;

        $employee = $user->employee;
        return $employee?->status === 'active'
            && ($employee->position?->code === 'POS-GUD' || $user->hasRole('supervisor'));
    }

    /** Akses pembuatan Nota Servis: Pelayan, Kasir, atau manajemen. */
    private function isServiceNoteAuthorized($user): bool
    {
        if ($user->hasAnyRole(['owner_manager', 'super_admin', 'supervisor'])) {
            return true;
        }

        return in_array($user->employee?->position?->code, ['POS-CS', 'POS-KSR'], true);
    }
}
