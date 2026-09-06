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
    private const FEEDBACK_WINDOW_DAYS = 7;

    public function index(Request $request): JsonResponse
    {
        if (! CapabilityMatrix::has($request->user(), 'feedback.view')) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang melihat feedback pelanggan.'], 403);
        }

        $ticketScope = app(ServiceTicketService::class)->scopeTickets($request->user());
        $pendingQuery = (clone $ticketScope)
            ->where('status', ServiceTicket::STATUS_DELIVERED)
            ->whereDoesntHave('feedback')
            ->orderByDesc('delivered_at')
            ->orderByDesc('id');
        $pending = $pendingQuery->limit(50)->get([
            'id', 'ticket_number', 'customer_name', 'device_brand', 'device_model', 'status', 'delivered_at',
        ]);

        $feedbackQuery = CustomerFeedback::whereIn('service_ticket_id', (clone $ticketScope)->select('id'));
        $stats = (clone $feedbackQuery)->selectRaw('COUNT(*) AS total, AVG(rating) AS average')->first();
        $feedbacks = $feedbackQuery
            ->with(['ticket', 'csEmployee', 'followUp'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (CustomerFeedback $feedback): array => $this->feedbackPayload($feedback))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'pending_tickets' => $pending->map(fn (ServiceTicket $ticket): array => $this->ticketPayload($ticket))->values(),
                'feedbacks' => $feedbacks,
                'stats' => [
                    'total' => (int) ($stats?->total ?? 0),
                    'average' => round((float) ($stats?->average ?? 0), 1),
                    'pending' => $pending->count(),
                ],
            ],
        ]);
    }

    public function link(Request $request, string $ticketId): JsonResponse
    {
        if (! CapabilityMatrix::has($request->user(), 'tickets.feedback-link')) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang membuat link feedback.'], 403);
        }

        $ticket = app(ServiceTicketService::class)->scopeTickets($request->user())->find($ticketId);
        if (! $ticket) {
            return response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }
        if ($ticket->status !== ServiceTicket::STATUS_DELIVERED || $ticket->feedback()->exists()) {
            return response()->json(['success' => false, 'message' => 'Link feedback hanya tersedia untuk tiket delivered yang belum memiliki feedback.'], 422);
        }
        if (! $ticket->delivered_at || now()->greaterThanOrEqualTo($ticket->delivered_at->copy()->addDays(self::FEEDBACK_WINDOW_DAYS))) {
            return response()->json(['success' => false, 'message' => 'Masa pengisian feedback tiket telah berakhir.'], 422);
        }

        $expiresAt = $ticket->delivered_at->copy()->addDays(self::FEEDBACK_WINDOW_DAYS);

        return response()->json([
            'success' => true,
            'data' => [
                'ticket_id' => (string) $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'url' => URL::temporarySignedRoute('customer-feedback.show', $expiresAt, ['ticket' => $ticket->getKey()]),
                'expires_at' => $expiresAt->toIso8601String(),
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
            'comments' => $feedback->comments,
            'employee' => $feedback->csEmployee?->name,
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
