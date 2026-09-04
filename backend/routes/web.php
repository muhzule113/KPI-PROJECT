<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\AdminResourceController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\KpiReportController;
use App\Http\Controllers\Web\SupervisorReviewController;
use App\Http\Controllers\Web\ImportController;
use App\Http\Controllers\Web\StockOpnameController;
use App\Http\Controllers\Web\KpiCorrectionController;
use App\Http\Controllers\Web\CustomerFeedbackController;
use App\Http\Controllers\Web\ManagerApprovalController;
use App\Http\Controllers\Web\DailyAssessmentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function () {
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

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
      Route::get('/app', [DashboardController::class, 'index'])->name('app.dashboard');
      Route::get('/app/reports/kpi.csv', [KpiReportController::class, 'export'])->name('app.reports.kpi.export');
      Route::get('/app/reports/kpi.xlsx', [KpiReportController::class, 'exportXlsx'])->name('app.reports.kpi.xlsx');
      Route::get('/app/reports/kpi.pdf', [KpiReportController::class, 'exportPdf'])->name('app.reports.kpi.pdf');
    Route::get('/my-kpi/{kpi}', [DailyAssessmentController::class, 'legacyKpi'])->name('app.my-kpi.legacy');
    Route::get('/app/my-kpi/daily', [DailyAssessmentController::class, 'employee'])->name('app.my-kpi.daily');
    Route::post('/app/my-kpi/daily', [DailyAssessmentController::class, 'saveEmployee'])->name('app.my-kpi.daily.save');
    Route::get('/app/supervisor-daily-assessments', [DailyAssessmentController::class, 'supervisorQueue'])->name('app.supervisor-daily.index');
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
