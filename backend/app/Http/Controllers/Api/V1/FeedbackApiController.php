<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerFeedback;
use App\Models\ServiceTicket;
use App\Modules\Service\ServiceTicketService;
use App\Support\CapabilityMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

final class FeedbackApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! CapabilityMatrix::has($request->user(), 'feedback.view')) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang melihat feedback pelanggan.'], 403);
        }

        $ticketScope = app(ServiceTicketService::class)->scopeTickets($request->user());
        $linkTickets = (clone $ticketScope)
            ->customerProgressAvailable()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get([
                'id', 'ticket_number', 'customer_name', 'device_brand', 'device_model',
                'status', 'delivered_at', 'updated_at',
            ]);

        $feedbackQuery = CustomerFeedback::whereIn('service_ticket_id', (clone $ticketScope)->select('id'));
        $stats = (clone $feedbackQuery)
            ->selectRaw('COUNT(*) AS total, AVG(rating) AS average, AVG(technician_rating) AS technician_average')
            ->first();
        $feedbacks = $feedbackQuery
            ->with(['ticket', 'csEmployee', 'technicianEmployee', 'followUp'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (CustomerFeedback $feedback): array => $this->feedbackPayload($feedback))
            ->values();
        $ticketPayloads = $linkTickets->map(fn (ServiceTicket $ticket): array => $this->ticketPayload($ticket))->values();
        $pelayanAverage = round((float) ($stats?->average ?? 0), 1);

        return response()->json([
            'success' => true,
            'data' => [
                'tickets' => $ticketPayloads,
                'pending_tickets' => $ticketPayloads,
                'feedbacks' => $feedbacks,
                'stats' => [
                    'total' => (int) ($stats?->total ?? 0),
                    'average' => $pelayanAverage,
                    'pelayan_average' => $pelayanAverage,
                    'technician_average' => $stats?->technician_average === null
                        ? null
                        : round((float) $stats->technician_average, 1),
                    'pending' => (clone $ticketScope)
                        ->customerProgressAvailable()
                        ->where('status', ServiceTicket::STATUS_DELIVERED)
                        ->whereDoesntHave('feedback')
                        ->count(),
                ],
            ],
        ]);
    }

    public function link(Request $request, string $ticketId): JsonResponse
    {
        if (! CapabilityMatrix::has($request->user(), 'tickets.feedback-link')) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang membuat link progres servis.'], 403);
        }

        $ticket = app(ServiceTicketService::class)->scopeTickets($request->user())->find($ticketId);
        if (! $ticket) {
            return response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }
        if (! $ticket->customerProgressIsAvailable()) {
            return response()->json(['success' => false, 'message' => 'Masa akses progres tiket telah berakhir.'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'ticket_id' => (string) $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'url' => URL::signedRoute('customer-feedback.show', ['ticket' => $ticket->getKey()]),
                'expires_at' => $ticket->customerProgressExpiresAt()?->toIso8601String(),
            ],
        ]);
    }

    private function ticketPayload(ServiceTicket $ticket): array
    {
        return [
            'id' => (string) $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'customer_name' => $ticket->customer_name,
            'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
            'status' => $ticket->status,
            'delivered_at' => $ticket->delivered_at?->toIso8601String(),
            'expires_at' => $ticket->customerProgressExpiresAt()?->toIso8601String(),
        ];
    }

    private function feedbackPayload(CustomerFeedback $feedback): array
    {
        return [
            'id' => (string) $feedback->getKey(),
            'ticket_id' => (string) $feedback->service_ticket_id,
            'ticket_number' => $feedback->ticket?->ticket_number,
            'customer_name' => $feedback->customer_name,
            'rating' => $feedback->rating,
            'pelayan_rating' => $feedback->rating,
            'technician_rating' => $feedback->technician_rating,
            'comments' => $feedback->comments,
            'employee' => $feedback->csEmployee?->name,
            'pelayan_employee' => $feedback->csEmployee?->name,
            'technician_employee' => $feedback->technicianEmployee?->name,
            'created_at' => $feedback->created_at?->toIso8601String(),
            'follow_up' => $feedback->followUp ? [
                'id' => (string) $feedback->followUp->getKey(),
                'row_version' => (int) ($feedback->followUp->row_version ?? 1),
                'status' => $feedback->followUp->status,
                'assigned_employee_id' => $feedback->followUp->assigned_employee_id,
                'due_at' => $feedback->followUp->due_at?->toIso8601String(),
                'response_summary' => $feedback->followUp->response_summary,
            ] : null,
        ];
    }
}
