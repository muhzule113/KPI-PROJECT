<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerFeedback;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\SparepartRequest;
use App\Models\StockMovement;
use App\Modules\Assessment\OperationalKpiSyncService;
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

        $query = ServiceTicket::with(['technicianEmployee', 'intakeEmployee', 'branch', 'feedback'])
            ->orderByDesc('id');

        // Filter by role scope
        if ($user->hasRole('employee')) {
            if ($employee?->position?->code === 'POS-TEK') {
                $query->where('technician_employee_id', $employee->id);
            } elseif ($employee?->position?->code === 'POS-CS') {
                // CS can see all tickets or their intake
            }
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

    public function store(Request $request): JsonResponse
    {
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
            'estimated_cost' => 'nullable|numeric',
            'estimated_completion_at' => 'nullable|date',
            'technician_employee_id' => 'nullable|string',
        ]);

        $user = $request->user()->loadMissing('employee');
        $employee = $user->employee;
        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();

        $ticketCount = ServiceTicket::whereYear('created_at', date('Y'))->whereMonth('created_at', date('m'))->count() + 1;
        $ticketNumber = 'SRV-' . date('Ym') . '-' . str_pad((string)$ticketCount, 4, '0', STR_PAD_LEFT);

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
            'estimated_cost' => $request->estimated_cost ?? 0,
            'estimated_completion_at' => $request->estimated_completion_at ?? now()->addDays(2),
            'branch_id' => $employee?->branch_id,
            'period_id' => $activePeriod?->id,
            'intake_by_employee_id' => $employee?->id,
            'technician_employee_id' => $request->technician_employee_id,
            'status' => 'intake',
            'result_status' => 'pending',
        ]);

        // Trigger automatic CS KPI update
        if ($activePeriod) {
            $this->syncService->syncPeriodOperationalData($activePeriod);
        }

        return response()->json([
            'success' => true,
            'message' => "Tiket servis {$ticket->ticket_number} berhasil dibuat.",
            'data' => $this->formatTicket($ticket->fresh(['technicianEmployee', 'intakeEmployee'])),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $ticket = ServiceTicket::with([
            'technicianEmployee',
            'intakeEmployee',
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

        return response()->json([
            'success' => true,
            'data' => $this->formatTicket($ticket, true),
        ]);
    }

    public function assignTechnician(Request $request, string $id): JsonResponse
    {
        $ticket = ServiceTicket::where('id', $id)->first();
        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }

        if (in_array($ticket->status, ['completed', 'delivered'])) {
            return response()->json(['success' => false, 'message' => 'Tiket sudah selesai, tidak bisa diubah teknisi.'], 422);
        }

        // Teknisi: hanya bisa claim tiket yang belum punya teknisi (atau tiket miliknya sendiri)
        $employee = $request->user()->employee;
        $isTechnician = $employee?->position?->code === 'POS-TEK';

        if ($isTechnician) {
            $targetId = $request->input('technician_employee_id') ?? $employee->id;
            // Teknisi tidak boleh mengubah penugasan tiket orang lain
            if ($targetId !== $employee->id) {
                return response()->json(['success' => false, 'message' => 'Teknisi hanya bisa mengambil tiket untuk dirinya sendiri.'], 403);
            }
            // Tidak boleh claim tiket yang sudah ditugaskan ke teknisi lain
            if ($ticket->technician_employee_id !== null && $ticket->technician_employee_id !== $employee->id) {
                return response()->json(['success' => false, 'message' => 'Tiket ini sudah ditugaskan ke teknisi lain.'], 403);
            }
        } elseif (!in_array($employee?->position?->code, ['POS-CS', 'POS-GUD']) && !$request->user()->hasAnyRole(['owner_manager', 'super_admin', 'supervisor'])) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berhak menugaskan teknisi.'], 403);
        }

        $technicianId = $request->input('technician_employee_id') ?? $employee?->id;

        if (!$technicianId) {
            return response()->json(['success' => false, 'message' => 'Teknisi tidak ditemukan pada akun Anda.'], 422);
        }

        $technician = Employee::where('id', $technicianId)
            ->whereHas('position', fn($q) => $q->where('code', 'POS-TEK'))
            ->first();

        if (!$technician) {
            return response()->json(['success' => false, 'message' => 'Karyawan terpilih bukan Teknisi.'], 422);
        }

        $ticket->technician_employee_id = $technician->id;
        $ticket->save();

        return response()->json([
            'success' => true,
            'message' => "Tiket {$ticket->ticket_number} ditugaskan ke {$technician->name}.",
            'data' => $this->formatTicket($ticket->fresh(['technicianEmployee', 'intakeEmployee'])),
        ]);
    }

    public function updateProgress(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'diagnosis_notes' => 'nullable|string',
            'action_notes' => 'nullable|string',
            'status' => 'nullable|string|in:diagnosing,waiting_sparepart,in_progress,qc_ready',
        ]);

        $ticket = ServiceTicket::where('id', $id)->first();
        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }

        // Teknisi hanya bisa update tiket miliknya
        if (!$this->authorizeTicketAccess($ticket)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki akses ke tiket ini.'], 403);
        }

        $ticket->diagnosis_notes = $request->diagnosis_notes ?? $ticket->diagnosis_notes;
        $ticket->action_notes = $request->action_notes ?? $ticket->action_notes;
        if ($request->status) {
            $ticket->status = $request->status;
        }

        if (!$ticket->started_at && in_array($ticket->status, ['in_progress', 'diagnosing'])) {
            $ticket->started_at = now();
        }

        $ticket->save();

        return response()->json([
            'success' => true,
            'message' => 'Progress tiket berhasil diperbarui.',
            'data' => $this->formatTicket($ticket->fresh(['technicianEmployee', 'intakeEmployee'])),
        ]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'result_status' => 'required|in:success,unrepairable,warranty_return',
            'diagnosis_notes' => 'required|string',
            'action_notes' => 'required|string',
            'qc_checklist' => 'required|array',
            'final_cost' => 'nullable|numeric',
        ]);

        $ticket = ServiceTicket::where('id', $id)->first();
        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }

        // Teknisi hanya bisa complete tiket miliknya
        if (!$this->authorizeTicketAccess($ticket)) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki akses ke tiket ini.'], 403);
        }

        $ticket->status = 'completed';
        $ticket->result_status = $request->result_status;
        $ticket->diagnosis_notes = $request->diagnosis_notes;
        $ticket->action_notes = $request->action_notes;
        $ticket->qc_checklist_json = $request->qc_checklist;
        $ticket->final_cost = $request->final_cost ?? $ticket->estimated_cost;
        $ticket->completed_at = now();
        $ticket->save();

        // Auto-sync KPI for technician and active period
        $activePeriod = $ticket->period ?? KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if ($activePeriod) {
            $this->syncService->syncPeriodOperationalData($activePeriod);
        }

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
        ]);

        $ticket = ServiceTicket::where('id', $id)->first();
        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }

        $user = $request->user()->loadMissing('employee');

        DB::transaction(function () use ($ticket, $request, $user) {
            $ticket->status = 'delivered';
            $ticket->delivered_at = now();
            $ticket->save();

            CustomerFeedback::updateOrCreate(
                ['service_ticket_id' => $ticket->id],
                [
                    'cs_employee_id' => $user->employee?->id,
                    'customer_name' => $ticket->customer_name,
                    'rating' => $request->rating,
                    'comments' => $request->comments,
                    'follow_up_ontime' => true,
                    'feedback_channel' => $request->feedback_channel ?? 'in_store',
                ]
            );
        });

        // Auto-sync KPI for CS and active period
        $activePeriod = $ticket->period ?? KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if ($activePeriod) {
            $this->syncService->syncPeriodOperationalData($activePeriod);
        }

        return response()->json([
            'success' => true,
            'message' => 'Unit berhasil diserahkan ke pelanggan dan rating kepuasan CSAT tercatat ke KPI!',
            'data' => $this->formatTicket($ticket->fresh(['feedback'])),
        ]);
    }

    public function spareparts(): JsonResponse
    {
        $spareparts = Sparepart::orderBy('product_type')->orderBy('category')->orderBy('name')->get();

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

    public function sparepartRequests(): JsonResponse
    {
        $requests = SparepartRequest::with(['sparepart', 'ticket', 'technician'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

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

        $req = SparepartRequest::create([
            'service_ticket_id' => $request->service_ticket_id,
            'sparepart_id' => $request->sparepart_id,
            'technician_employee_id' => $user->employee?->id,
            'quantity' => $request->quantity ?? 1,
            'status' => 'pending',
            'requested_at' => now(),
            'notes' => $request->notes,
        ]);

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

        DB::transaction(function () use ($req, $user) {
            $req->status = 'fulfilled';
            $req->fulfilled_at = now();
            $req->warehouse_employee_id = $user->employee?->id;
            $req->save();

            if ($req->sparepart && $req->sparepart->stock_quantity >= $req->quantity) {
                $before = $req->sparepart->stock_quantity;
                $req->sparepart->decrement('stock_quantity', $req->quantity);

                StockMovement::create([
                    'sparepart_id' => $req->sparepart->id,
                    'movement_type' => StockMovement::TYPE_REQUEST_OUT,
                    'quantity' => -1 * $req->quantity,
                    'stock_before' => $before,
                    'stock_after' => $req->sparepart->fresh()->stock_quantity,
                    'reference_type' => 'sparepart_request',
                    'reference_id' => $req->id,
                    'note' => 'Penyerahan sparepart ke teknisi',
                    'user_id' => $user->id,
                ]);
            }
        });

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

    /**
     * Otentikasi akses tiket: teknisi hanya boleh akses tiket miliknya.
     * CS (intake/feedback), Gudang (fulfill), manager & super admin boleh semua.
     */
    private function authorizeTicketAccess(ServiceTicket $ticket): bool
    {
        $user = auth()->user();
        if (!$user) return false;

        // Manager/supervisor/super_admin: full access
        if ($user->hasAnyRole(['owner_manager', 'super_admin', 'supervisor'])) {
            return true;
        }

        $employee = $user->employee;
        if (!$employee) return false;

        $positionCode = $employee->position?->code;

        // CS & Gudang: full access (mereka terlibat di intake/fulfill/feedback)
        if (in_array($positionCode, ['POS-CS', 'POS-GUD'])) {
            return true;
        }

        // Teknisi: hanya tiket miliknya (atau belum ditugaskan)
        if ($positionCode === 'POS-TEK') {
            return $ticket->technician_employee_id === null || $ticket->technician_employee_id === $employee->id;
        }

        return false;
    }
}
