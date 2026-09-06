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

final class ServiceTicketStatusController extends Controller
{
    private const EDITABLE_STATUSES = [
        ServiceTicket::STATUS_DIAGNOSING,
        ServiceTicket::STATUS_WAITING_SPAREPART,
        ServiceTicket::STATUS_IN_PROGRESS,
        ServiceTicket::STATUS_QC_READY,
    ];

    private const STATUS_LABELS = [
        ServiceTicket::STATUS_INTAKE => 'Diterima Pelayan',
        ServiceTicket::STATUS_DIAGNOSING => 'Sedang Diagnosa',
        ServiceTicket::STATUS_WAITING_SPAREPART => 'Menunggu Sparepart',
        ServiceTicket::STATUS_IN_PROGRESS => 'Sedang Dikerjakan',
        ServiceTicket::STATUS_QC_READY => 'Siap QC',
        ServiceTicket::STATUS_COMPLETED => 'Selesai',
        ServiceTicket::STATUS_DELIVERED => 'Diserahkan',
        ServiceTicket::STATUS_CANCELLED => 'Dibatalkan',
    ];

    public function edit(Request $request, string $record): Response
    {
        $ticket = $this->ticket($request, $record);
        abort_if($ticket->isFinal(), 409, 'Tiket final tidak dapat diperbarui dari alur status pengerjaan.');

        return Inertia::render('Admin/ServiceTicketStatusForm', [
            'ticket' => [
                'id' => (string) $ticket->getKey(),
                'ticket_number' => $ticket->ticket_number,
                'customer_name' => $ticket->customer_name,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'initial_complaint' => $ticket->initial_complaint,
                'customer_needs' => $ticket->customer_needs,
                'status' => $ticket->status,
                'status_label' => self::STATUS_LABELS[$ticket->status] ?? $ticket->status,
                'row_version' => (int) ($ticket->row_version ?? 1),
                'diagnosis_notes' => $ticket->diagnosis_notes ?? '',
                'action_notes' => $ticket->action_notes ?? '',
                'pelayan_name' => $ticket->intakeEmployee?->name ?? 'Belum dicatat',
                'technician_name' => $ticket->technicianEmployee?->name ?? 'Tidak menggunakan Teknisi',
            ],
            'status_options' => $this->statusOptions($ticket),
        ]);
    }

    public function update(Request $request, string $record): RedirectResponse
    {
        $ticket = $this->ticket($request, $record);
        abort_if($ticket->isFinal(), 409, 'Tiket final tidak dapat diperbarui dari alur status pengerjaan.');

        $request->validate([
            'status' => ['required', 'string', Rule::in(self::EDITABLE_STATUSES)],
            'diagnosis_notes' => ['nullable', 'string'],
            'action_notes' => ['nullable', 'string'],
            'row_version' => ['required', 'integer', 'min:1'],
        ]);

        $response = app(ServiceTicketService::class)->updateProgress($request->user(), $request->all(), (string) $ticket->getKey());
        $payload = $response;

        if (! ($response['success'] ?? false)) {
            return back()
                ->withErrors(['status' => $payload['message'] ?? 'Status tiket tidak dapat diperbarui.'])
                ->withInput();
        }

        return redirect('/app/service-tickets')
            ->with('success', $payload['message'] ?? 'Status pengerjaan tiket berhasil diperbarui.');
    }

    private function ticket(Request $request, string $record): ServiceTicket
    {
        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        abort_unless(CapabilityMatrix::has($user, 'tickets.progress'), 403);

        return app(ServiceTicketService::class)->scopeTickets($user)
            ->with(['intakeEmployee', 'cashierEmployee', 'technicianEmployee', 'sparepartRequests.sparepart'])
            ->findOrFail($record);
    }

    private function statusOptions(ServiceTicket $ticket): array
    {
        $options = array_values(array_filter(
            ServiceTicket::TRANSITIONS[$ticket->status] ?? [],
            fn (string $status): bool => in_array($status, self::EDITABLE_STATUSES, true),
        ));

        if (in_array($ticket->status, self::EDITABLE_STATUSES, true)) {
            array_unshift($options, $ticket->status);
        }

        return collect($options)
            ->unique()
            ->map(fn (string $status): array => [
                'value' => $status,
                'label' => self::STATUS_LABELS[$status] ?? $status,
            ])
            ->values()
            ->all();
    }
}
