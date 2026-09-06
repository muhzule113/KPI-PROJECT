<?php

use App\Models\AuditEvent;
use App\Models\KpiPeriod;
use App\Models\ReportSubmission;
use App\Models\SystemNotification;
use App\Modules\Assessment\DailyAssessmentService;
use App\Modules\Calculation\KpiAuditRepairService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('kpi:prepare-daily {--date= : Siapkan sampai tanggal YYYY-MM-DD}', function (): int {
    $date = $this->option('date') ?: now()->toDateString();
    $validator = validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
    if ($validator->fails()) {
        $this->error('Tanggal harus YYYY-MM-DD dan tidak boleh di masa depan.');

        return 1;
    }
    $failed = false;
    foreach (KpiPeriod::whereIn('status', ['OPEN', 'SUBMISSION_CLOSED', 'IN_REVIEW', 'WAITING_APPROVAL'])
        ->whereDate('start_date', '<=', $date)->get() as $period) {
        try {
            $count = app(DailyAssessmentService::class)->preparePeriod($period, $date);
            AuditEvent::log(action: 'daily_kpi_sync_completed', subjectType: 'KpiPeriod', subjectId: (string) $period->id,
                after: ['through_date' => $date, 'prepared_kpis' => $count]);
            $this->info("{$period->name}: {$count} rekap disiapkan.");
        } catch (Throwable $exception) {
            $failed = true;
            report($exception);
            AuditEvent::log(action: 'daily_kpi_sync_failed', subjectType: 'KpiPeriod', subjectId: (string) $period->id,
                after: ['through_date' => $date, 'error' => $exception->getMessage()]);
            $this->error("{$period->name}: gagal. Periksa audit dan ulangi kpi:prepare-daily --date={$date}.");
        }
    }

    return $failed ? 1 : 0;
})->purpose('Sinkronkan sumber, siapkan fakta harian, dan perbarui rekap KPI; aman diulang.');

Schedule::command('kpi:prepare-daily')->hourly()->withoutOverlapping();

Artisan::command('kpi:send-reminders', function (): int {
    foreach ([3 => 'h3', 1 => 'h1'] as $days => $suffix) {
        ReportSubmission::with('employee.user')->whereNull('submitted_at')->whereDate('deadline_at', now()->addDays($days))
            ->each(function (ReportSubmission $submission) use ($suffix): void {
                if ($submission->employee?->user_id) {
                    SystemNotification::send($submission->employee->user_id, 'Deadline laporan mendekat',
                        'Buka aplikasi untuk menyelesaikan laporan sebelum deadline.', "report_due_{$suffix}",
                        'ReportSubmission', (string) $submission->id, '/report-submissions');
                }
            });

        KpiPeriod::whereIn('status', ['OPEN', 'SUBMISSION_CLOSED', 'IN_REVIEW'])
            ->whereDate('submission_deadline', now()->addDays($days))->with('employeeKpis.employee.user')
            ->each(fn (KpiPeriod $period) => $period->employeeKpis->whereIn('status', ['draft', 'revision_required'])->each(
                fn ($kpi) => $kpi->employee?->user_id && SystemNotification::send($kpi->employee->user_id,
                    'Deadline input KPI mendekat', 'Buka aplikasi untuk melihat pekerjaan KPI yang belum selesai.',
                    "submission_due_{$suffix}", 'EmployeeKpi', (string) $kpi->id, "/my-kpi/{$kpi->id}")
            ));

        KpiPeriod::whereIn('status', ['SUBMISSION_CLOSED', 'IN_REVIEW'])->whereDate('review_deadline', now()->addDays($days))
            ->with('employeeKpis.supervisorSnapshot.user')->each(fn (KpiPeriod $period) => $period->employeeKpis
            ->whereIn('status', ['submitted', 'under_review', 'revision_required', 'verified'])->each(
                fn ($kpi) => $kpi->supervisorSnapshot?->user_id && SystemNotification::send($kpi->supervisorSnapshot->user_id,
                    'Deadline review KPI mendekat', 'Buka aplikasi untuk menyelesaikan review tim sebelum deadline.',
                    "review_due_{$suffix}", 'EmployeeKpi', (string) $kpi->id, "/supervisor/review/{$kpi->id}")
            ));
    }

    return 0;
})->purpose('Kirim reminder H-3/H-1 untuk laporan, input, dan review KPI.');

Schedule::command('kpi:send-reminders')->dailyAt('08:00')->timezone('Asia/Makassar')->withoutOverlapping();

Artisan::command('kpi:audit-repair {--period=} {--dry-run} {--apply}', function (KpiAuditRepairService $service): int {
    if ($this->option('dry-run') && $this->option('apply')) {
        $this->error('Pilih salah satu mode --dry-run atau --apply.');

        return 1;
    }
    $results = $service->run($this->option('period') ? (int) $this->option('period') : null, (bool) $this->option('apply'));
    foreach ($results as $result) {
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return 0;
})->purpose('Audit perbedaan KPI dan perbaiki hanya periode yang belum LOCKED.');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
