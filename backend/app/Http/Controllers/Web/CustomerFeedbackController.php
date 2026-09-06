<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CustomerFeedback;
use App\Models\Employee;
use App\Models\FeedbackFollowUp;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Modules\Assessment\OperationalKpiSyncService;
use App\Modules\Service\ServiceTicketService;
use App\Support\CapabilityMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerFeedbackController extends Controller
{
    private const FEEDBACK_WINDOW_DAYS = 7;

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);
        $ticketScope = app(ServiceTicketService::class)->scopeTickets($request->user());
        $tickets = (clone $ticketScope)
            ->where('status', ServiceTicket::STATUS_DELIVERED)
            ->whereDoesntHave('feedback')
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'ticket_number',
                'customer_name',
                'device_brand',
                'device_model',
                'status',
                'delivered_at',
            ]);
        $selectedTicket = $tickets->firstWhere('id', $request->integer('ticket'));

        $feedbackQuery = CustomerFeedback::whereIn('service_ticket_id', (clone $ticketScope)->select('id'));
        $feedbackStats = (clone $feedbackQuery)
            ->selectRaw('COUNT(*) AS total, AVG(rating) AS average')
            ->first();
        $feedbacks = $feedbackQuery
            ->with(['ticket', 'csEmployee'])
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (CustomerFeedback $feedback): array => [
                'id' => $feedback->getKey(),
                'ticket_number' => $feedback->ticket?->ticket_number,
                'customer_name' => $feedback->customer_name,
                'rating' => $feedback->rating,
                'comments' => $feedback->comments,
                'employee' => $feedback->csEmployee?->name,
                'created_at' => $feedback->created_at?->format('d M Y, H:i'),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/CustomerFeedback', [
            'tickets' => $tickets->map(fn (ServiceTicket $ticket): array => [
                'id' => $ticket->getKey(),
                'ticket_number' => $ticket->ticket_number,
                'customer_name' => $ticket->customer_name,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'status' => $ticket->status,
            ])->values()->all(),
            'selected_ticket' => $selectedTicket ? [
                'id' => $selectedTicket->getKey(),
                'ticket_number' => $selectedTicket->ticket_number,
                'customer_name' => $selectedTicket->customer_name,
                'device' => trim("{$selectedTicket->device_brand} {$selectedTicket->device_model}"),
            ] : null,
            'feedback_url' => $selectedTicket
                ? $this->feedbackUrl($selectedTicket)
                : null,
            'feedbacks' => $feedbacks,
            'stats' => [
                'total' => (int) ($feedbackStats?->total ?? 0),
                'average' => round((float) ($feedbackStats?->average ?? 0), 1),
                'pending' => $tickets->count(),
            ],
        ]);
    }

    public function show(Request $request, ServiceTicket $ticket): Response
    {
        $ticket->load(['feedback', 'branch']);
        $feedbackDeadline = $ticket->delivered_at?->copy()->addDays(self::FEEDBACK_WINDOW_DAYS);
        abort_unless(
            $ticket->status === ServiceTicket::STATUS_DELIVERED
                && ($ticket->feedback || ($feedbackDeadline && now()->lessThan($feedbackDeadline))),
            404,
        );

        return Inertia::render('CustomerFeedback/Form', [
            'ticket' => [
                'number' => $ticket->ticket_number,
                'customer_name' => $ticket->customer_name,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'branch' => $ticket->branch?->name,
            ],
            'has_feedback' => (bool) $ticket->feedback,
            'submit_url' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, ServiceTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($ticket, $validated): void {
            $lockedTicket = ServiceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if ($lockedTicket->feedback()->exists()) {
                return;
            }

            abort_unless(
                $lockedTicket->status === ServiceTicket::STATUS_DELIVERED
                    && $lockedTicket->delivered_at
                    && now()->lessThan($lockedTicket->delivered_at->copy()->addDays(self::FEEDBACK_WINDOW_DAYS)),
                422,
                'Feedback hanya dapat diberikan maksimal 7 hari kalender setelah barang diserahkan.',
            );

            $feedback = CustomerFeedback::create([
                'service_ticket_id' => $lockedTicket->getKey(),
                'cs_employee_id' => $lockedTicket->intake_by_employee_id,
                'customer_name' => $lockedTicket->customer_name,
                'rating' => $validated['rating'],
                'comments' => $validated['comments'] ?? null,
                'feedback_channel' => 'qr_code',
            ]);

            if ((int) $feedback->rating <= 2) {
                $assignee = Employee::query()
                    ->whereKey($lockedTicket->intake_by_employee_id)
                    ->where('status', 'active')
                    ->whereHas('position', fn ($query) => $query->where('code', 'POS-CS'))
                    ->first();
                $followUp = FeedbackFollowUp::create([
                    'customer_feedback_id' => $feedback->getKey(),
                    'service_ticket_id' => $lockedTicket->getKey(),
                    'assigned_employee_id' => $assignee?->getKey(),
                    'status' => $assignee ? FeedbackFollowUp::STATUS_PENDING : FeedbackFollowUp::STATUS_EXCEPTION,
                    'due_at' => $assignee ? $feedback->created_at?->copy()->addWeekdays(1) : null,
                    'response_summary' => $assignee ? null : 'Tidak ada Pelayan aktif untuk menerima tugas follow-up.',
                ]);
                AuditEvent::log(
                    action: 'web_feedback_follow_up_created',
                    subjectType: 'FeedbackFollowUp',
                    subjectId: (string) $followUp->getKey(),
                    after: [
                        'status' => $followUp->status,
                        'assigned_employee_id' => $followUp->assigned_employee_id,
                        'due_at' => $followUp->due_at,
                    ],
                    actorType: 'customer',
                );
            }

            AuditEvent::log(
                action: 'web_feedback_created',
                subjectType: 'CustomerFeedback',
                subjectId: (string) $feedback->getKey(),
                after: [
                    'service_ticket_id' => $lockedTicket->getKey(),
                    'rating' => $feedback->rating,
                    'feedback_channel' => $feedback->feedback_channel,
                ],
                actorType: 'customer',
            );

            $period = $lockedTicket->period ?? KpiPeriod::query()->where('status', 'OPEN')->orderByDesc('id')->first();
            if ($period) {
                app(OperationalKpiSyncService::class)->syncPeriodOperationalData($period);
            }
        });

        return redirect()->to(URL::signedRoute('customer-feedback.show', ['ticket' => $ticket->getKey()]));
    }

    private function feedbackUrl(ServiceTicket $ticket): ?string
    {
        if (! $ticket->delivered_at) {
            return null;
        }

        $expiresAt = $ticket->delivered_at->copy()->addDays(self::FEEDBACK_WINDOW_DAYS);
        if (now()->greaterThanOrEqualTo($expiresAt)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'customer-feedback.show',
            $expiresAt,
            ['ticket' => $ticket->getKey()],
        );
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user()->loadMissing('employee.position');

        abort_unless(CapabilityMatrix::has($user, 'feedback.view'), 403);
    }
}
