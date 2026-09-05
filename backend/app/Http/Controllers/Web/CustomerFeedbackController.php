<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CustomerFeedback;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Modules\Assessment\OperationalKpiSyncService;
use App\Support\MenuAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerFeedbackController extends Controller
{
    private const FEEDBACKABLE_STATUSES = ['completed', 'delivered'];

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);
        $activePeriod = KpiPeriod::active();

        $tickets = ServiceTicket::query()
            ->when($activePeriod, fn ($query) => $query->where(fn ($periodQuery) => $periodQuery
                ->where('period_id', $activePeriod->getKey())
                ->orWhereNull('period_id')))
            ->whereIn('status', self::FEEDBACKABLE_STATUSES)
            ->whereDoesntHave('feedback')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'ticket_number',
                'customer_name',
                'device_brand',
                'device_model',
                'status',
            ]);
        $selectedTicket = $tickets->firstWhere('id', $request->integer('ticket'));

        $feedbackQuery = CustomerFeedback::query()
            ->when(
                $activePeriod,
                fn ($query) => $query->whereHas('ticket', fn ($ticketQuery) => $ticketQuery
                    ->where('period_id', $activePeriod->getKey())
                    ->orWhereNull('period_id')),
            );
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
                ? URL::signedRoute('customer-feedback.show', ['ticket' => $selectedTicket->getKey()])
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
        abort_unless(
            $ticket->feedback || in_array($ticket->status, self::FEEDBACKABLE_STATUSES, true),
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

        $periodId = DB::transaction(function () use ($ticket, $validated): ?int {
            $lockedTicket = ServiceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if ($lockedTicket->feedback()->exists()) {
                return null;
            }

            $beforeStatus = $lockedTicket->status;

            abort_unless(
                in_array($lockedTicket->status, self::FEEDBACKABLE_STATUSES, true),
                422,
                'Feedback hanya dapat diberikan setelah servis selesai.',
            );

            if ($lockedTicket->status === 'completed') {
                $lockedTicket->assertTransition('delivered');
                $lockedTicket->status = 'delivered';
                $lockedTicket->delivered_at = now();
                $lockedTicket->row_version = (int) ($lockedTicket->row_version ?? 1) + 1;
                $lockedTicket->save();
            }

            $feedback = CustomerFeedback::create([
                'service_ticket_id' => $lockedTicket->getKey(),
                'cs_employee_id' => $lockedTicket->intake_by_employee_id,
                'customer_name' => $lockedTicket->customer_name,
                'rating' => $validated['rating'],
                'comments' => $validated['comments'] ?? null,
                'follow_up_ontime' => true,
                'feedback_channel' => 'qr_code',
            ]);

            AuditEvent::log(
                action: 'web_ticket_delivered',
                subjectType: 'ServiceTicket',
                subjectId: (string) $lockedTicket->getKey(),
                before: ['status' => $beforeStatus],
                after: [
                    'status' => $lockedTicket->status,
                    'delivered_at' => $lockedTicket->delivered_at,
                ],
                actorType: 'customer',
            );
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

            return $lockedTicket->period_id;
        });

        return redirect()->to(URL::signedRoute('customer-feedback.show', ['ticket' => $ticket->getKey()]));
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user()->loadMissing('employee.position');

        abort_unless(MenuAccess::can($user, [], ['POS-CS']), 403);
    }
}
