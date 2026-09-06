<?php

use App\Http\Controllers\Api\V1\AdminResourceApiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashierApiController;
use App\Http\Controllers\Api\V1\DailyAssessmentController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\FeedbackApiController;
use App\Http\Controllers\Api\V1\KpiCorrectionController;
use App\Http\Controllers\Api\V1\KpiEvidenceController;
use App\Http\Controllers\Api\V1\KpiReportApiController;
use App\Http\Controllers\Api\V1\ManagerApprovalController;
use App\Http\Controllers\Api\V1\MyKpiController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PeriodApiController;
use App\Http\Controllers\Api\V1\ReportSubmissionController;
use App\Http\Controllers\Api\V1\ServiceTicketApiController;
use App\Http\Controllers\Api\V1\SupervisorAttendanceApiController;
use App\Http\Controllers\Api\V1\SupervisorReviewController;
use App\Http\Controllers\ServiceTicketEvidenceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/openapi', fn () => response()->file(base_path('../docs/openapi-v1.yaml'), ['Content-Type' => 'application/yaml']));
    // Public routes
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

    // Authenticated routes
    Route::middleware(['auth:sanctum', 'platform:mobile', 'throttle:api-mutations'])->group(function () {
        // Auth profile
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('/auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('/auth/sessions/{type}/{id}', [AuthController::class, 'revokeSession']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::put('/devices/push-token', [DeviceTokenController::class, 'update']);
        Route::delete('/devices/push-token', [DeviceTokenController::class, 'destroy']);
        Route::get('/kpi/evidence/{evidenceId}', [KpiEvidenceController::class, 'download'])
            ->middleware('signed')
            ->whereNumber('evidenceId')
            ->name('api.v1.kpi.evidence.download');

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/reports/kpi', [KpiReportApiController::class, 'index']);
        Route::get('/reports/kpi/export/{format}', [KpiReportApiController::class, 'export'])->middleware('throttle:export')
            ->whereIn('format', ['csv', 'xlsx', 'pdf']);
        Route::get('/reports/{type}', [KpiReportApiController::class, 'typedIndex']);
        Route::get('/reports/{type}/export/{format}', [KpiReportApiController::class, 'typedExport'])->middleware('throttle:export')
            ->whereIn('format', ['xlsx', 'pdf']);

        // Resource operasional memakai registry yang sama dengan admin web.
        Route::get('/resources/{resource}', [AdminResourceApiController::class, 'index']);
        Route::post('/resources/{resource}', [AdminResourceApiController::class, 'store']);
        Route::get('/resources/{resource}/{record}', [AdminResourceApiController::class, 'show']);
        Route::put('/resources/{resource}/{record}', [AdminResourceApiController::class, 'update']);
        Route::post('/resources/{resource}/actions/{action}', [AdminResourceApiController::class, 'action']);
        Route::post('/resources/{resource}/{record}/actions/{action}', [AdminResourceApiController::class, 'recordAction']);

        // Supervisor mencatat fakta absensi harian dari roster snapshot KPI.
        Route::get('/supervisor/attendance', [SupervisorAttendanceApiController::class, 'index']);
        Route::post('/supervisor/attendance', [SupervisorAttendanceApiController::class, 'store']);

        // Feedback pelanggan tetap melalui signed URL; mobile hanya membuat link dan memantau follow-up.
        Route::get('/operational/feedback', [FeedbackApiController::class, 'index']);
        Route::get('/operational/tickets/{ticketId}/feedback-link', [FeedbackApiController::class, 'link']);

        // Operational Service Management & Tickets
        Route::get('/operational/pelayan', [ServiceTicketApiController::class, 'pelayanEmployees']);
        Route::get('/operational/technicians', [ServiceTicketApiController::class, 'technicianEmployees']);
        Route::get('/operational/tickets', [ServiceTicketApiController::class, 'index']);
        Route::post('/operational/tickets', [ServiceTicketApiController::class, 'store']);
        Route::get('/operational/tickets/{id}/evidence/{index}', [ServiceTicketEvidenceController::class, 'download'])->whereNumber('index');
        Route::get('/operational/tickets/{id}', [ServiceTicketApiController::class, 'show']);
        Route::post('/operational/tickets/{id}/assign', [ServiceTicketApiController::class, 'assignTechnician']);
        Route::post('/operational/tickets/{id}/estimated-cost', [ServiceTicketApiController::class, 'recordEstimatedCost']);
        Route::post('/operational/tickets/{id}/consent', [ServiceTicketApiController::class, 'recordConsent']);
        Route::post('/operational/tickets/{id}/final-cost', [ServiceTicketApiController::class, 'recordFinalCost']);
        Route::post('/operational/tickets/{id}/payment', [ServiceTicketApiController::class, 'recordPayment']);
        Route::post('/operational/tickets/{id}/payment-exception', [ServiceTicketApiController::class, 'approvePaymentException']);
        Route::post('/operational/tickets/{id}/update-progress', [ServiceTicketApiController::class, 'updateProgress']);
        Route::post('/operational/tickets/{id}/complete', [ServiceTicketApiController::class, 'complete']);
        Route::post('/operational/tickets/{id}/technical-evidence', [ServiceTicketApiController::class, 'addTechnicalEvidence']);
        Route::post('/operational/tickets/{id}/warranty-return', [ServiceTicketApiController::class, 'createWarrantyReturn']);
        Route::post('/operational/tickets/{id}/warranty-review', [ServiceTicketApiController::class, 'reviewWarrantyReturn']);
        Route::post('/operational/tickets/{id}/cancel', [ServiceTicketApiController::class, 'cancel']);
        Route::post('/operational/tickets/{id}/deliver', [ServiceTicketApiController::class, 'deliver']);
        Route::post('/operational/tickets/{id}/feedback', [ServiceTicketApiController::class, 'pickupAndFeedback']);
        Route::post('/operational/feedback-followups/{id}', [ServiceTicketApiController::class, 'updateFeedbackFollowUp']);
        Route::get('/operational/spareparts', [ServiceTicketApiController::class, 'spareparts']);
        Route::get('/operational/sparepart-requests', [ServiceTicketApiController::class, 'sparepartRequests']);
        Route::post('/operational/spareparts/request', [ServiceTicketApiController::class, 'requestSparepart']);
        Route::post('/operational/spareparts/fulfill/{id}', [ServiceTicketApiController::class, 'fulfillSparepart']);
        Route::post('/operational/spareparts/confirm/{id}', [ServiceTicketApiController::class, 'confirmSparepart']);
        Route::post('/operational/spareparts/unavailable/{id}', [ServiceTicketApiController::class, 'markSparepartUnavailable']);
        Route::post('/operational/sync-kpi', [ServiceTicketApiController::class, 'syncKpi']);

        // Employee: My KPI
        Route::get('/my-kpi/active', [MyKpiController::class, 'active']);
        Route::get('/my-kpi/daily', [DailyAssessmentController::class, 'employeeDay']);
        Route::get('/my-kpi/items/{id}', [MyKpiController::class, 'getItem']);
        Route::get('/my-kpi/history', [MyKpiController::class, 'history']);

        // Input KPI milik sendiri tetap tersedia bagi employee, supervisor,
        // dan role operasional lain yang memiliki snapshot KPI sendiri.
        Route::post('/my-kpi/daily', [DailyAssessmentController::class, 'saveEmployeeDay']);
        Route::post('/my-kpi/items/{id}/draft', [MyKpiController::class, 'saveDraft']);
        Route::post('/my-kpi/items/{id}/evidence', [MyKpiController::class, 'uploadEvidence'])->middleware('throttle:upload');
        Route::post('/my-kpi/submit', [MyKpiController::class, 'submit']);

        // Supervisor: Review Queue (hanya Supervisor yang ditugaskan)
        Route::middleware('capability:kpi.supervisor.review')->group(function () {
            Route::get('/supervisor/daily', [DailyAssessmentController::class, 'supervisorQueue']);
            Route::post('/supervisor/daily/{entryId}/assess', [DailyAssessmentController::class, 'assessSupervisor'])
                ->whereNumber('entryId');
            Route::get('/supervisor/queue', [SupervisorReviewController::class, 'queue']);
            Route::get('/supervisor/review/{kpiId}', [SupervisorReviewController::class, 'detail']);
            Route::post('/supervisor/review/{kpiId}/items/{itemId}/verify', [SupervisorReviewController::class, 'verifyItem']);
            Route::post('/supervisor/review/{kpiId}/items/{itemId}/rubric', [SupervisorReviewController::class, 'submitRubric']);
            Route::post('/supervisor/review/{kpiId}/request-revision', [SupervisorReviewController::class, 'requestRevision']);
            Route::post('/supervisor/review/{kpiId}/forward', [SupervisorReviewController::class, 'forward']);
        });

        // Manager / Owner: Approval Queue (hanya Manager yang ditugaskan)
        Route::middleware('capability:kpi.manager.approval')->group(function () {
            Route::get('/manager/daily', [DailyAssessmentController::class, 'managerQueue']);
            Route::post('/manager/daily/{entryId}/assess', [DailyAssessmentController::class, 'assessManager'])
                ->whereNumber('entryId');
            Route::get('/manager/queue', [ManagerApprovalController::class, 'queue']);
            Route::get('/manager/approval/{kpiId}', [ManagerApprovalController::class, 'detail']);
            Route::post('/manager/approval/{kpiId}/items/{itemId}/assess', [ManagerApprovalController::class, 'assessItem']);
            Route::post('/manager/approval/{kpiId}/items/{itemId}/rubric', [ManagerApprovalController::class, 'assessRubric']);
            Route::post('/manager/approval/{kpiId}/approve', [ManagerApprovalController::class, 'approve']);
            Route::post('/manager/approval/{kpiId}/return', [ManagerApprovalController::class, 'return']);
        });

        // KPI correction requests: requester and assigned reviewer/manager scope is enforced by the service.
        Route::get('/kpi/corrections', [KpiCorrectionController::class, 'index']);
        Route::post('/kpi/{kpiId}/corrections', [KpiCorrectionController::class, 'request']);
        Route::post('/kpi/corrections/{correctionId}/approve', [KpiCorrectionController::class, 'approve'])
            ->whereNumber('correctionId');
        Route::post('/kpi/corrections/{correctionId}/reject', [KpiCorrectionController::class, 'reject'])
            ->whereNumber('correctionId');

        // Cashier Report Import
        Route::post('/cashier/import', [CashierApiController::class, 'upload'])->middleware('throttle:upload');
        Route::get('/cashier/imports', [CashierApiController::class, 'index']);
        Route::get('/cashier/import/{batchId}', [CashierApiController::class, 'status']);
        Route::post('/cashier/import/{batchId}/confirm', [CashierApiController::class, 'confirm']);

        // Periode KPI (untuk picker di mobile)
        Route::get('/periods', [PeriodApiController::class, 'index']);
        Route::get('/report-submissions', [ReportSubmissionController::class, 'index']);
        Route::post('/report-submissions/{id}/submit', [ReportSubmissionController::class, 'submit'])->whereNumber('id');

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    });
});
