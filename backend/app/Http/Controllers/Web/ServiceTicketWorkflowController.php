<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Service\ServiceTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ServiceTicketWorkflowController extends Controller
{
    private const ACTIONS = [
        'assign' => 'assignTechnician',
        'consent' => 'recordConsent',
        'payment-exception' => 'approvePaymentException',
        'warranty-review' => 'reviewWarrantyReturn',
        'deliver' => 'deliver',
        'cancel' => 'cancel',
    ];

    public function show(Request $request, string $record, ServiceTicketService $tickets): Response
    {
        $ticket = $tickets->show($request->user(), [], $record)['data'];
        $technicians = in_array('assign', $ticket['available_actions'], true)
            ? $tickets->technicianEmployees($request->user(), [])['data']
            : [];

        return Inertia::render('Admin/ServiceTicketWorkflow', [
            'ticket' => $ticket,
            'technicians' => $technicians,
        ]);
    }

    public function update(Request $request, string $record, ServiceTicketService $tickets): RedirectResponse
    {
        $action = $request->string('action')->toString();
        abort_unless(isset(self::ACTIONS[$action]), 422, 'Tindakan tiket tidak tersedia.');
        try {
            $result = $tickets->{self::ACTIONS[$action]}($request->user(), $request->all(), $record);
        } catch (HttpException $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }

            return back()->withErrors(['action' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', $result['message']);
    }
}
