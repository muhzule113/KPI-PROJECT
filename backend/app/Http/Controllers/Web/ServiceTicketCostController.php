<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ServiceTicket;
use App\Modules\Service\ServiceTicketService;
use App\Support\CapabilityMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ServiceTicketCostController extends Controller
{
    public function edit(Request $request, string $record): Response
    {
        $ticket = $this->ticket($request, $record);
        abort_unless(
            in_array($ticket->status, [
                ...ServiceTicket::ESTIMATED_COST_EDITABLE_STATUSES,
                ServiceTicket::STATUS_COMPLETED,
            ], true),
            409,
            'Biaya tiket hanya dapat dikelola sebelum tiket selesai teknis atau setelah servis selesai.',
        );

        return Inertia::render('Admin/ServiceTicketCostForm', [
            'mode' => $ticket->status === ServiceTicket::STATUS_COMPLETED ? 'final' : 'estimate',
            'ticket' => [
                'id' => (string) $ticket->getKey(),
                'ticket_number' => $ticket->ticket_number,
                'customer_name' => $ticket->customer_name,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'pelayan_name' => $ticket->intakeEmployee?->name ?? 'Belum dicatat',
                'cashier_name' => $ticket->cashierEmployee?->name ?? 'Belum dicatat',
                'estimated_cost' => (float) $ticket->estimated_cost,
                'final_cost' => (float) $ticket->final_cost,
                'paid_amount' => (float) ($ticket->paid_amount ?? 0),
                'payment_status' => $ticket->payment_status ?? 'unpaid',
                'row_version' => (int) ($ticket->row_version ?? 1),
            ],
        ]);
    }

    public function update(Request $request, string $record): RedirectResponse
    {
        $ticket = $this->ticket($request, $record);
        $data = $request->validate([
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'final_cost' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'row_version' => ['required', 'integer', 'min:1'],
        ]);

        $rowVersion = (int) $data['row_version'];
        if (in_array($ticket->status, ServiceTicket::ESTIMATED_COST_EDITABLE_STATUSES, true)) {
            $response = $this->saveCost($request, 'recordEstimatedCost', $ticket, [
                'estimated_cost' => $data['estimated_cost'],
                'note' => $data['note'] ?? null,
                'row_version' => $rowVersion,
            ]);
            if (! ($response['success'] ?? false)) {
                return back()->withErrors(['cost' => $response['message'] ?? 'Estimasi biaya tidak dapat disimpan.'])->withInput();
            }

            return redirect('/app/service-tickets')->with('success', 'Estimasi biaya berhasil dicatat.');
        }

        abort_unless($ticket->status === ServiceTicket::STATUS_COMPLETED, 409, 'Biaya final belum dapat dikelola pada status tiket ini.');

        $response = $this->saveCost($request, 'recordFinalCost', $ticket, [
            'final_cost' => $data['final_cost'],
            'note' => $data['note'] ?? null,
            'row_version' => $rowVersion,
        ]);
        if (! ($response['success'] ?? false)) {
            return back()->withErrors(['cost' => $response['message'] ?? 'Biaya final tidak dapat disimpan.'])->withInput();
        }

        if (array_key_exists('paid_amount', $data)) {
            $rowVersion = (int) ($response['data']['row_version'] ?? ($rowVersion + 1));
            $paymentResponse = $this->saveCost($request, 'recordPayment', $ticket, [
                'paid_amount' => $data['paid_amount'],
                'note' => $data['note'] ?? null,
                'row_version' => $rowVersion,
            ]);
            if (! ($paymentResponse['success'] ?? false)) {
                return back()->withErrors(['cost' => $paymentResponse['message'] ?? 'Pembayaran tidak dapat disimpan.'])->withInput();
            }
        }

        return redirect('/app/service-tickets')->with('success', 'Biaya final dan pembayaran berhasil dicatat.');
    }

    private function ticket(Request $request, string $record): ServiceTicket
    {
        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        abort_unless(CapabilityMatrix::has($user, 'tickets.cost'), 403);

        return app(ServiceTicketService::class)->scopeTickets($user)
            ->with(['intakeEmployee', 'cashierEmployee', 'technicianEmployee', 'sparepartRequests.sparepart'])
            ->findOrFail($record);
    }

    private function saveCost(Request $request, string $method, ServiceTicket $ticket, array $payload): array
    {
        try {
            return app(ServiceTicketService::class)->{$method}($request->user(), $payload, (string) $ticket->getKey());
        } catch (HttpException $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }

            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }
}
