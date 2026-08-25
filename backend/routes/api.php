<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashierApiController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ManagerApprovalController;
use App\Http\Controllers\Api\V1\MyKpiController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ServiceTicketApiController;
use App\Http\Controllers\Api\V1\SupervisorReviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {
        // Auth profile
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Operational Service Management & Tickets
        Route::get('/operational/tickets', [ServiceTicketApiController::class, 'index']);
        Route::post('/operational/tickets', [ServiceTicketApiController::class, 'store']);
        Route::get('/operational/tickets/{id}', [ServiceTicketApiController::class, 'show']);
        Route::post('/operational/tickets/{id}/assign', [ServiceTicketApiController::class, 'assignTechnician']);
        Route::post('/operational/tickets/{id}/update-progress', [ServiceTicketApiController::class, 'updateProgress']);
        Route::post('/operational/tickets/{id}/complete', [ServiceTicketApiController::class, 'complete']);
        Route::post('/operational/tickets/{id}/feedback', [ServiceTicketApiController::class, 'pickupAndFeedback']);
        Route::get('/operational/spareparts', [ServiceTicketApiController::class, 'spareparts']);
        Route::post('/operational/spareparts/request', [ServiceTicketApiController::class, 'requestSparepart']);
        Route::post('/operational/spareparts/fulfill/{id}', [ServiceTicketApiController::class, 'fulfillSparepart']);
        Route::post('/operational/sync-kpi', [ServiceTicketApiController::class, 'syncKpi']);

        // Employee: My KPI
        Route::get('/my-kpi/active', [MyKpiController::class, 'active']);
        Route::get('/my-kpi/items/{id}', [MyKpiController::class, 'getItem']);
        Route::post('/my-kpi/items/{id}/draft', [MyKpiController::class, 'saveDraft']);
        Route::post('/my-kpi/items/{id}/evidence', [MyKpiController::class, 'uploadEvidence']);
        Route::post('/my-kpi/submit', [MyKpiController::class, 'submit']);
        Route::get('/my-kpi/history', [MyKpiController::class, 'history']);

        // Supervisor: Review Queue
        Route::get('/supervisor/queue', [SupervisorReviewController::class, 'queue']);
        Route::get('/supervisor/review/{kpiId}', [SupervisorReviewController::class, 'detail']);
        Route::post('/supervisor/review/{kpiId}/items/{itemId}/verify', [SupervisorReviewController::class, 'verifyItem']);
        Route::post('/supervisor/review/{kpiId}/items/{itemId}/rubric', [SupervisorReviewController::class, 'submitRubric']);
        Route::post('/supervisor/review/{kpiId}/request-revision', [SupervisorReviewController::class, 'requestRevision']);
        Route::post('/supervisor/review/{kpiId}/forward', [SupervisorReviewController::class, 'forward']);

        // Manager / Owner: Approval Queue
        Route::get('/manager/queue', [ManagerApprovalController::class, 'queue']);
        Route::get('/manager/approval/{kpiId}', [ManagerApprovalController::class, 'detail']);
        Route::post('/manager/approval/{kpiId}/approve', [ManagerApprovalController::class, 'approve']);
        Route::post('/manager/approval/{kpiId}/return', [ManagerApprovalController::class, 'return']);

        // Cashier Report Import
        Route::post('/cashier/import', [CashierApiController::class, 'upload']);
        Route::post('/cashier/import/{batchId}/confirm', [CashierApiController::class, 'confirm']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    });
});
