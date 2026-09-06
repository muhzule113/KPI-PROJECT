<?php

use App\Http\Controllers\Api\V1\KpiEvidenceController;
use App\Http\Controllers\Api\V1\KpiReportApiController;
use App\Http\Controllers\DeploymentHealthController;
use App\Http\Controllers\ServiceTicketEvidenceController;
use App\Http\Controllers\Web\AdminResourceController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CustomerFeedbackController;
use App\Http\Controllers\Web\DailyAssessmentController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ImportController;
use App\Http\Controllers\Web\KpiCorrectionController;
use App\Http\Controllers\Web\KpiReportController;
use App\Http\Controllers\Web\ManagerApprovalController;
use App\Http\Controllers\Web\ServiceTicketCompletionController;
use App\Http\Controllers\Web\ServiceTicketCostController;
use App\Http\Controllers\Web\ServiceTicketSparepartRequestController;
use App\Http\Controllers\Web\ServiceTicketStatusController;
use App\Http\Controllers\Web\ServiceTicketWorkflowController;
use App\Http\Controllers\Web\StockOpnameController;
use App\Http\Controllers\Web\SupervisorAttendanceController;
use App\Http\Controllers\Web\SupervisorReviewController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health/ready', DeploymentHealthController::class);
Route::redirect('/', '/login');

Route::middleware('guest:web')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::get('/customer-feedback/{ticket}', [CustomerFeedbackController::class, 'show'])
    ->whereNumber('ticket')
    ->middleware('signed')
    ->name('customer-feedback.show');
Route::post('/customer-feedback/{ticket}', [CustomerFeedbackController::class, 'store'])
    ->whereNumber('ticket')
    ->middleware(['signed', 'throttle:30,1'])
    ->name('customer-feedback.store');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:web')->name('logout');

Route::middleware(['auth:web', 'platform:web'])->group(function () {
    Route::get('/app/kpi-evidence/{evidenceId}', [KpiEvidenceController::class, 'download'])
        ->middleware('signed')->whereNumber('evidenceId')->name('app.kpi.evidence.download');
    Route::get('/csrf-token', function (Request $request) {
        return response()->json(['token' => $request->session()->token()]);
    })->name('csrf-token');
    Route::get('/app', [DashboardController::class, 'index'])->name('app.dashboard');
    Route::get('/app/reports/kpi.csv', [KpiReportController::class, 'export'])->name('app.reports.kpi.export');
    Route::get('/app/reports/kpi.xlsx', [KpiReportController::class, 'exportXlsx'])->name('app.reports.kpi.xlsx');
    Route::get('/app/reports/kpi.pdf', [KpiReportController::class, 'exportPdf'])->name('app.reports.kpi.pdf');
    Route::get('/app/reports/{type}/export/{format}', [KpiReportApiController::class, 'typedExport'])
        ->middleware('throttle:export')->name('app.reports.typed.export');
    Route::get('/my-kpi/{kpi}', [DailyAssessmentController::class, 'legacyKpi'])->name('app.my-kpi.legacy');
    Route::get('/app/my-kpi/daily', [DailyAssessmentController::class, 'employee'])->name('app.my-kpi.daily');
    Route::post('/app/my-kpi/daily', [DailyAssessmentController::class, 'saveEmployee'])->name('app.my-kpi.daily.save');
    Route::get('/app/supervisor-daily-assessments', [DailyAssessmentController::class, 'supervisorQueue'])->name('app.supervisor-daily.index');
    Route::get('/app/supervisor-attendance', [SupervisorAttendanceController::class, 'index'])->name('app.supervisor-attendance.index');
    Route::post('/app/supervisor-attendance', [SupervisorAttendanceController::class, 'store'])->name('app.supervisor-attendance.store');
    Route::post('/app/supervisor-daily-assessments/{entry}/assess', [DailyAssessmentController::class, 'assessSupervisor'])
        ->whereNumber('entry')
        ->name('app.supervisor-daily.assess');
    Route::get('/app/manager-daily-assessments', [DailyAssessmentController::class, 'managerQueue'])->name('app.manager-daily.index');
    Route::post('/app/manager-daily-assessments/{entry}/assess', [DailyAssessmentController::class, 'assessManager'])
        ->whereNumber('entry')
        ->name('app.manager-daily.assess');
    Route::get('/app/supervisor-reviews/{record}/review', [SupervisorReviewController::class, 'show'])->name('app.supervisor-review.show');
    Route::post('/app/supervisor-reviews/{record}/items/{item}/decision', [SupervisorReviewController::class, 'verifyItem'])->name('app.supervisor-review.decision');
    Route::post('/app/supervisor-reviews/{record}/items/{item}/rubric', [SupervisorReviewController::class, 'submitRubric'])->name('app.supervisor-review.rubric');
    Route::post('/app/supervisor-reviews/{record}/revision', [SupervisorReviewController::class, 'requestRevision'])->name('app.supervisor-review.revision');
    Route::post('/app/supervisor-reviews/{record}/forward', [SupervisorReviewController::class, 'forward'])->name('app.supervisor-review.forward');
    Route::get('/app/import-batches/upload', [ImportController::class, 'create'])->name('app.import.create');
    Route::post('/app/import-batches/upload', [ImportController::class, 'store'])->name('app.import.store');
    Route::get('/app/stock-opnames/{record}/edit', [StockOpnameController::class, 'edit'])->name('app.stock-opname.edit');
    Route::put('/app/stock-opnames/{record}', [StockOpnameController::class, 'update'])->name('app.stock-opname.update');
    Route::post('/app/stock-opnames/{record}/complete', [StockOpnameController::class, 'complete'])->name('app.stock-opname.complete');
    Route::get('/app/employee-kpis/{record}/correction', [KpiCorrectionController::class, 'create'])->name('app.kpi-correction.create');
    Route::post('/app/employee-kpis/{record}/correction', [KpiCorrectionController::class, 'store'])->name('app.kpi-correction.store');
    Route::get('/app/employee-kpis/{record}/assessment', [ManagerApprovalController::class, 'show'])->name('app.manager-assessment.show');
    Route::post('/app/employee-kpis/{record}/items/{item}/assessment', [ManagerApprovalController::class, 'assessItem'])->name('app.manager-assessment.item');
    Route::post('/app/employee-kpis/{record}/items/{item}/rubric', [ManagerApprovalController::class, 'assessRubric'])->name('app.manager-assessment.rubric');
    Route::post('/app/employee-kpis/{record}/assessment/approve', [ManagerApprovalController::class, 'approve'])->name('app.manager-assessment.approve');
    Route::post('/app/employee-kpis/{record}/assessment/return', [ManagerApprovalController::class, 'returnToSupervisor'])->name('app.manager-assessment.return');
    Route::get('/app/customer-feedback', [CustomerFeedbackController::class, 'index'])->name('app.customer-feedback.index');
    Route::get('/app/service-tickets/{id}/evidence/{index}', [ServiceTicketEvidenceController::class, 'download'])->whereNumber('index');
    Route::get('/app/service-tickets/{record}/workflow', [ServiceTicketWorkflowController::class, 'show']);
    Route::post('/app/service-tickets/{record}/workflow', [ServiceTicketWorkflowController::class, 'update']);
    Route::get('/app/service-tickets/{record}/cost', [ServiceTicketCostController::class, 'edit'])->name('app.service-ticket-cost.edit');
    Route::post('/app/service-tickets/{record}/cost', [ServiceTicketCostController::class, 'update'])->name('app.service-ticket-cost.update');
    Route::put('/app/service-tickets/{record}/cost', [ServiceTicketCostController::class, 'update'])->name('app.service-ticket-cost.update.put');
    Route::get('/app/service-tickets/{record}/sparepart-request', [ServiceTicketSparepartRequestController::class, 'edit'])->name('app.service-ticket-sparepart-request.edit');
    Route::post('/app/service-tickets/{record}/sparepart-request', [ServiceTicketSparepartRequestController::class, 'store'])->name('app.service-ticket-sparepart-request.store');
    Route::get('/app/service-tickets/{record}/status', [ServiceTicketStatusController::class, 'edit'])->name('app.service-ticket-status.edit');
    Route::put('/app/service-tickets/{record}/status', [ServiceTicketStatusController::class, 'update'])->name('app.service-ticket-status.update');
    Route::get('/app/service-tickets/{record}/complete', [ServiceTicketCompletionController::class, 'edit'])->name('app.service-ticket-completion.edit');
    Route::post('/app/service-tickets/{record}/complete', [ServiceTicketCompletionController::class, 'update'])->name('app.service-ticket-completion.store');
    Route::put('/app/service-tickets/{record}/complete', [ServiceTicketCompletionController::class, 'update'])->name('app.service-ticket-completion.update');

    Route::prefix('app')->name('app.')->group(function () {
        Route::get('/{resource}', [AdminResourceController::class, 'index'])->name('resource.index');
        Route::get('/{resource}/create', [AdminResourceController::class, 'create'])->name('resource.create');
        Route::post('/{resource}', [AdminResourceController::class, 'store'])->name('resource.store');
        Route::post('/{resource}/actions/{action}', [AdminResourceController::class, 'action'])->name('resource.action');
        Route::get('/{resource}/{record}/edit', [AdminResourceController::class, 'edit'])->name('resource.edit');
        Route::put('/{resource}/{record}', [AdminResourceController::class, 'update'])->name('resource.update');
        Route::delete('/{resource}/{record}', [AdminResourceController::class, 'destroy'])->name('resource.destroy');
        Route::post('/{resource}/{record}/actions/{action}', [AdminResourceController::class, 'recordAction'])->name('resource.record.action');
    });
});
