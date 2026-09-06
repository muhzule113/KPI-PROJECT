<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Modules\Service\ServiceTicketService;
use App\Support\CapabilityMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ServiceTicketSparepartRequestController extends Controller
{
    private const REQUESTABLE_STATUSES = [
        ServiceTicket::STATUS_WAITING_SPAREPART,
        ServiceTicket::STATUS_IN_PROGRESS,
    ];

    private const STATUS_LABELS = [
        ServiceTicket::STATUS_WAITING_SPAREPART => 'Menunggu Sparepart',
        ServiceTicket::STATUS_IN_PROGRESS => 'Sedang Dikerjakan',
    ];

    public function edit(Request $request, string $record): Response
    {
        $ticket = $this->ticket($request, $record);
        $this->assertRequestable($ticket);

        return Inertia::render('Admin/ServiceTicketSparepartRequestForm', [
            'ticket' => [
                'id' => (string) $ticket->getKey(),
                'ticket_number' => $ticket->ticket_number,
                'customer_name' => $ticket->customer_name,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'status' => $ticket->status,
                'row_version' => (int) $ticket->row_version,
                'status_label' => self::STATUS_LABELS[$ticket->status] ?? $ticket->status,
                'technician_name' => $ticket->technicianEmployee?->name ?? 'Belum ditugaskan',
            ],
            'part_options' => $this->partOptions($ticket),
            'requests' => $ticket->sparepartRequests->map(fn ($sparepartRequest): array => [
                'part_name' => $sparepartRequest->sparepart?->name ?? 'Sparepart',
                'part_code' => $sparepartRequest->sparepart?->code,
                'quantity' => $sparepartRequest->quantity,
                'status' => $sparepartRequest->status,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, string $record): RedirectResponse
    {
        $ticket = $this->ticket($request, $record);
        $this->assertRequestable($ticket);

        $data = $request->validate([
            'sparepart_id' => ['required', 'integer', 'exists:spareparts,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'row_version' => ['required', 'integer', 'min:1'],
        ]);

        $apiRequest = Request::create('', 'POST', [
            'service_ticket_id' => $ticket->getKey(),
            'sparepart_id' => $data['sparepart_id'],
            'quantity' => $data['quantity'],
            'notes' => $data['notes'] ?? null,
            'row_version' => $data['row_version'],
        ]);
        $apiRequest->setUserResolver(fn () => $request->user());

        $response = app(ServiceTicketService::class)->requestSparepart($request->user(), $apiRequest->all());
        $payload = $response;

        if (! ($response['success'] ?? false)) {
            return back()
                ->withErrors(['sparepart_id' => $payload['message'] ?? 'Permintaan sparepart tidak dapat dibuat.'])
                ->withInput();
        }

        return redirect('/app/service-tickets')
            ->with('success', $payload['message'] ?? 'Permintaan sparepart telah dikirim ke Gudang.');
    }

    private function ticket(Request $request, string $record): ServiceTicket
    {
        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        abort_unless(CapabilityMatrix::has($user, 'tickets.progress'), 403);

        return app(ServiceTicketService::class)->scopeTickets($user)
            ->with(['intakeEmployee', 'cashierEmployee', 'technicianEmployee', 'sparepartRequests.sparepart'])
            ->findOrFail($record);
    }

    private function assertRequestable(ServiceTicket $ticket): void
    {
        abort_unless(
            in_array($ticket->status, self::REQUESTABLE_STATUSES, true),
            409,
            'Permintaan sparepart hanya dapat dibuat saat tiket sedang dikerjakan atau menunggu sparepart.',
        );
    }

    private function partOptions(ServiceTicket $ticket): array
    {
        return Sparepart::query()
            ->forBranch($ticket->branch_id)
            ->where('product_type', Sparepart::TYPE_SPAREPART)
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Sparepart $part): array => [
                'value' => (string) $part->getKey(),
                'label' => $part->name,
                'code' => $part->code,
                'category' => $part->category,
                'stock' => (int) $part->stock_quantity,
            ])
            ->all();
    }
}
