<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Service\ServiceTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceTicketApiController extends Controller
{
    public function __construct(private ServiceTicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->tickets->index($request->user(), $request->all()), 200);
    }

    public function pelayanEmployees(Request $request): JsonResponse
    {
        return response()->json($this->tickets->pelayanEmployees($request->user(), $request->all()), 200);
    }

    public function technicianEmployees(Request $request): JsonResponse
    {
        return response()->json($this->tickets->technicianEmployees($request->user(), $request->all()), 200);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->tickets->store($request->user(), $request->all()), 201);
    }

    public function recordEstimatedCost(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->recordEstimatedCost($request->user(), $request->all(), $id), 200);
    }

    public function recordConsent(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->recordConsent($request->user(), $request->all(), $id), 200);
    }

    public function recordFinalCost(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->recordFinalCost($request->user(), $request->all(), $id), 200);
    }

    public function recordPayment(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->recordPayment($request->user(), $request->all(), $id), 200);
    }

    public function approvePaymentException(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->approvePaymentException($request->user(), $request->all(), $id), 200);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->cancel($request->user(), $request->all(), $id), 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->show($request->user(), $request->all(), $id), 200);
    }

    public function assignTechnician(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->assignTechnician($request->user(), $request->all(), $id), 200);
    }

    public function updateProgress(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->updateProgress($request->user(), $request->all(), $id), 200);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->complete($request->user(), $request->all(), $id), 200);
    }

    public function createWarrantyReturn(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->createWarrantyReturn($request->user(), $request->all(), $id), 201);
    }

    public function reviewWarrantyReturn(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->reviewWarrantyReturn($request->user(), $request->all(), $id), 200);
    }

    public function deliver(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->deliver($request->user(), $request->all(), $id), 200);
    }

    public function pickupAndFeedback(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->pickupAndFeedback($request->user(), $request->all(), $id), 200);
    }

    public function updateFeedbackFollowUp(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->updateFeedbackFollowUp($request->user(), $request->all(), $id), 200);
    }

    public function addTechnicalEvidence(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->addTechnicalEvidence($request->user(), $request->all(), $id), 200);
    }

    public function confirmSparepart(Request $request, string $requestId): JsonResponse
    {
        return response()->json($this->tickets->confirmSparepart($request->user(), $request->all(), $requestId), 200);
    }

    public function markSparepartUnavailable(Request $request, string $requestId): JsonResponse
    {
        return response()->json($this->tickets->markSparepartUnavailable($request->user(), $request->all(), $requestId), 200);
    }

    public function spareparts(Request $request): JsonResponse
    {
        return response()->json($this->tickets->spareparts($request->user(), $request->all()), 200);
    }

    public function sparepartRequests(Request $request): JsonResponse
    {
        return response()->json($this->tickets->sparepartRequests($request->user(), $request->all()), 200);
    }

    public function requestSparepart(Request $request): JsonResponse
    {
        return response()->json($this->tickets->requestSparepart($request->user(), $request->all()), 200);
    }

    public function fulfillSparepart(Request $request, string $requestId): JsonResponse
    {
        return response()->json($this->tickets->fulfillSparepart($request->user(), $request->all(), $requestId), 200);
    }

    public function syncKpi(Request $request): JsonResponse
    {
        return response()->json($this->tickets->syncKpi($request->user(), $request->all()), 200);
    }
}
