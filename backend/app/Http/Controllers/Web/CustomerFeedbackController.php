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
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerFeedbackController extends Controller
{
    private const STATUS_LABELS = [
        ServiceTicket::STATUS_INTAKE => 'Diterima',
        ServiceTicket::STATUS_DIAGNOSING => 'Diagnosis',
        ServiceTicket::STATUS_WAITING_SPAREPART => 'Menunggu sparepart',
        ServiceTicket::STATUS_IN_PROGRESS => 'Dikerjakan',
        ServiceTicket::STATUS_QC_READY => 'Siap QC',
        ServiceTicket::STATUS_COMPLETED => 'Selesai',
        ServiceTicket::STATUS_DELIVERED => 'Diserahkan',
        ServiceTicket::STATUS_CANCELLED => 'Dibatalkan',
    ];

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);
        $ticketScope = app(ServiceTicketService::class)->scopeTickets($request->user());
        $tickets = (clone $ticketScope)
            ->customerProgressAvailable()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'ticket_number',
                'customer_name',
                'device_brand',
                'device_model',
                'status',
                'delivered_at',
                'updated_at',
            ]);
        $selectedTicket = $tickets->firstWhere('id', $request->integer('ticket'));

        $feedbackQuery = CustomerFeedback::whereIn('service_ticket_id', (clone $ticketScope)->select('id'));
        $feedbackStats = (clone $feedbackQuery)
            ->selectRaw('COUNT(*) AS total, AVG(rating) AS average, AVG(technician_rating) AS technician_average')
            ->first();
        $feedbacks = $feedbackQuery
            ->with(['ticket', 'csEmployee', 'technicianEmployee'])
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (CustomerFeedback $feedback): array => $this->feedbackPayload($feedback))
            ->values()
            ->all();
        $pelayanAverage = round((float) ($feedbackStats?->average ?? 0), 1);
        $technicianAverage = $feedbackStats?->technician_average === null
            ? null
            : round((float) $feedbackStats->technician_average, 1);

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
                'status' => $selectedTicket->status,
            ] : null,
            'feedback_url' => $selectedTicket ? $this->feedbackUrl($selectedTicket) : null,
            'feedbacks' => $feedbacks,
            'stats' => [
                'total' => (int) ($feedbackStats?->total ?? 0),
                'average' => $pelayanAverage,
                'pelayan_average' => $pelayanAverage,
                'technician_average' => $technicianAverage,
                'pending' => (clone $ticketScope)
                    ->customerProgressAvailable()
                    ->where('status', ServiceTicket::STATUS_DELIVERED)
                    ->whereDoesntHave('feedback')
                    ->count(),
            ],
        ]);
    }

    public function show(ServiceTicket $ticket): Response
    {
        $ticket->load(['feedback', 'branch', 'intakeEmployee', 'technicianEmployee']);
        abort_unless($ticket->customerProgressIsAvailable(), 404);

        return Inertia::render('CustomerFeedback/Form', [
            'ticket' => [
                'number' => $ticket->ticket_number,
                'customer_name' => $ticket->customer_name,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'initial_complaint' => $ticket->initial_complaint,
                'branch' => $ticket->branch?->name,
                'pelayan' => $ticket->intakeEmployee?->name,
                'technician' => $ticket->technicianEmployee?->name,
                'estimated_completion_at' => $ticket->estimated_completion_at?->toIso8601String(),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
                'status' => $ticket->status,
                'status_label' => self::STATUS_LABELS[$ticket->status] ?? $ticket->status,
                'timeline' => $this->timeline($ticket->status),
                'can_feedback' => $ticket->status === ServiceTicket::STATUS_DELIVERED
                    && ! $ticket->feedback
                    && $ticket->customerProgressIsAvailable(),
            ],
            'has_feedback' => (bool) $ticket->feedback,
            'submit_url' => URL::signedRoute('customer-feedback.store', ['ticket' => $ticket->getKey()]),
        ]);
    }

    public function store(Request $request, ServiceTicket $ticket): RedirectResponse
    {
        DB::transaction(function () use ($request, $ticket): void {
            $lockedTicket = ServiceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            abort_if($lockedTicket->feedback()->exists(), 409, 'Feedback untuk tiket ini sudah pernah dikirim.');
            abort_unless(
                $lockedTicket->status === ServiceTicket::STATUS_DELIVERED
                    && $lockedTicket->customerProgressIsAvailable(),
                422,
                'Feedback hanya dapat diberikan maksimal 7 hari kalender setelah barang diserahkan.',
            );

            $validated = Validator::make($request->all(), [
                'rating' => ['required', 'integer', 'between:1,5'],
                'technician_rating' => $lockedTicket->technician_employee_id
                    ? ['required', 'integer', 'between:1,5']
                    : ['prohibited'],
                'comments' => ['nullable', 'string', 'max:2000'],
            ])->validate();

            $feedback = CustomerFeedback::create([
                'service_ticket_id' => $lockedTicket->getKey(),
                'cs_employee_id' => $lockedTicket->intake_by_employee_id,
                'technician_employee_id' => $lockedTicket->technician_employee_id,
                'customer_name' => $lockedTicket->customer_name,
                'rating' => $validated['rating'],
                'technician_rating' => $validated['technician_rating'] ?? null,
                'comments' => $validated['comments'] ?? null,
                'feedback_channel' => 'qr_code',
            ]);

            if ((int) $feedback->rating <= 2 || ($feedback->technician_rating !== null && (int) $feedback->technician_rating <= 2)) {
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
                    'technician_rating' => $feedback->technician_rating,
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

    private function feedbackUrl(ServiceTicket $ticket): string
    {
        return URL::signedRoute('customer-feedback.show', ['ticket' => $ticket->getKey()]);
    }

    private function feedbackPayload(CustomerFeedback $feedback): array
    {
        return [
            'id' => $feedback->getKey(),
            'ticket_number' => $feedback->ticket?->ticket_number,
            'customer_name' => $feedback->customer_name,
            'rating' => $feedback->rating,
            'pelayan_rating' => $feedback->rating,
            'technician_rating' => $feedback->technician_rating,
            'comments' => $feedback->comments,
            'employee' => $feedback->csEmployee?->name,
            'pelayan_employee' => $feedback->csEmployee?->name,
            'technician_employee' => $feedback->technicianEmployee?->name,
            'created_at' => $feedback->created_at?->format('d M Y, H:i'),
        ];
    }

    private function timeline(string $currentStatus): array
    {
        $statuses = $currentStatus === ServiceTicket::STATUS_CANCELLED
            ? [
                ServiceTicket::STATUS_INTAKE => self::STATUS_LABELS[ServiceTicket::STATUS_INTAKE],
                ServiceTicket::STATUS_CANCELLED => self::STATUS_LABELS[ServiceTicket::STATUS_CANCELLED],
            ]
            : array_diff_key(self::STATUS_LABELS, [ServiceTicket::STATUS_CANCELLED => true]);
        $keys = array_keys($statuses);
        $currentPosition = array_search($currentStatus, $keys, true);
        $optionalStatuses = [
            ServiceTicket::STATUS_WAITING_SPAREPART,
            ServiceTicket::STATUS_IN_PROGRESS,
            ServiceTicket::STATUS_QC_READY,
        ];

        return collect($statuses)
            ->map(function (string $label, string $key) use ($currentPosition, $keys, $optionalStatuses): array {
                $position = array_search($key, $keys, true);

                return [
                    'key' => $key,
                    'label' => $label,
                    'position' => $position + 1,
                    'state' => $key === $keys[$currentPosition]
                        ? 'current'
                        : (in_array($key, $optionalStatuses, true)
                            ? 'optional'
                            : ($position < $currentPosition ? 'completed' : 'upcoming')),
                ];
            })
            ->values()
            ->all();
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user()->loadMissing('employee.position');

        abort_unless(CapabilityMatrix::has($user, 'feedback.view'), 403);
    }
}
