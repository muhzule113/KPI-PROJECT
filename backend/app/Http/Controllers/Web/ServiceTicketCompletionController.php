<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ServiceTicket;
use App\Modules\Service\ServiceTicketService;
use App\Support\CapabilityMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ServiceTicketCompletionController extends Controller
{
    private const RESULT_LABELS = [
        ServiceTicket::RESULT_SUCCESS => 'Berhasil diperbaiki',
        ServiceTicket::RESULT_UNREPAIRABLE => 'Tidak dapat diperbaiki',
        ServiceTicket::RESULT_CUSTOMER_DECLINED => 'Customer menolak perbaikan',
    ];

    private const CHECKLIST_LABELS = [
        'display' => 'Layar / display',
        'touch' => 'Touchscreen',
        'camera' => 'Kamera',
        'mic' => 'Mikrofon',
        'speaker' => 'Speaker',
        'cellular' => 'Sinyal / jaringan seluler',
        'charging' => 'Pengisian daya',
        'biometric' => 'Biometrik',
    ];

    public function edit(Request $request, string $record): Response
    {
        $ticket = $this->ticket($request, $record);
        $this->assertQcReady($ticket);

        return Inertia::render('Admin/ServiceTicketCompletionForm', [
            'ticket' => [
                'id' => (string) $ticket->getKey(),
                'ticket_number' => $ticket->ticket_number,
                'customer_name' => $ticket->customer_name,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'initial_complaint' => $ticket->initial_complaint,
                'customer_needs' => $ticket->customer_needs,
                'status' => $ticket->status,
                'status_label' => 'Siap QC',
                'result_status' => $ticket->result_status,
                'row_version' => (int) ($ticket->row_version ?? 1),
                'diagnosis_notes' => $ticket->diagnosis_notes ?? '',
                'action_notes' => $ticket->action_notes ?? '',
                'qc_checklist' => $ticket->qc_checklist_json ?? [],
                'technical_evidence' => $ticket->technical_evidence_json ?? [],
                'customer_consent_status' => $ticket->customer_consent_status,
                'customer_consent_notes' => $ticket->customer_consent_notes,
                'pelayan_name' => $ticket->intakeEmployee?->name ?? 'Belum dicatat',
                'technician_name' => $ticket->technicianEmployee?->name ?? 'Tidak menggunakan Teknisi',
            ],
            'checklist_options' => $this->checklistOptions(),
            'result_options' => collect(self::RESULT_LABELS)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function update(Request $request, string $record): RedirectResponse
    {
        $ticket = $this->ticket($request, $record);
        $this->assertQcReady($ticket);

        $request->validate([
            'result_status' => ['required', Rule::in(array_keys(self::RESULT_LABELS))],
            'diagnosis_notes' => ['required', 'string'],
            'action_notes' => ['required', 'string'],
            'qc_checklist' => ['required', 'array'],
            'qc_checklist.*' => ['required', 'boolean'],
            'unrepairable_reason' => ['nullable', 'string', 'max:2000'],
            'customer_declined_reason' => ['nullable', 'string', 'max:2000'],
            'technical_evidence' => ['nullable', 'array'],
            'technical_evidence.*.type' => ['required_with:technical_evidence', 'string', 'max:30'],
            'technical_evidence.*.reference' => ['nullable', 'string', 'max:500'],
            'technical_evidence.*.file' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
            'customer_consent_confirmed' => ['nullable', 'boolean'],
            'customer_consent_notes' => ['nullable', 'string', 'max:2000'],
            'row_version' => ['required', 'integer', 'min:1'],
        ]);

        $response = app(ServiceTicketService::class)->complete($request->user(), $request->all(), (string) $ticket->getKey());
        $payload = $response;

        if (! ($response['success'] ?? false)) {
            return back()
                ->withErrors(['completion' => $payload['message'] ?? 'Tiket tidak dapat diselesaikan.'])
                ->withInput();
        }

        return redirect('/app/service-tickets')
            ->with('success', $payload['message'] ?? 'Tiket servis berhasil diselesaikan.');
    }

    private function ticket(Request $request, string $record): ServiceTicket
    {
        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        abort_unless(CapabilityMatrix::has($user, 'tickets.progress'), 403);

        return app(ServiceTicketService::class)->scopeTickets($user)
            ->with(['intakeEmployee', 'cashierEmployee', 'technicianEmployee', 'sparepartRequests.sparepart'])
            ->findOrFail($record);
    }

    private function assertQcReady(ServiceTicket $ticket): void
    {
        abort_unless(
            $ticket->status === ServiceTicket::STATUS_QC_READY,
            409,
            'Tiket harus berstatus Siap QC sebelum dapat diselesaikan.',
        );
    }

    private function checklistOptions(): array
    {
        return collect(ServiceTicket::REQUIRED_QC_KEYS)
            ->map(fn (string $value): array => [
                'value' => $value,
                'label' => self::CHECKLIST_LABELS[$value] ?? $value,
            ])
            ->all();
    }
}
