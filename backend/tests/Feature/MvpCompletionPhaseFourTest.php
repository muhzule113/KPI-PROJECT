<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\ReportSubmission;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Notification\FcmClient;
use App\Modules\Reporting\KpiReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MvpCompletionPhaseFourTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_all_report_types_and_formats_use_the_same_authorized_scope(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $kpiId = EmployeeKpi::where('period_id', $period->id)->value('id');

        $this->actingAs($employee, 'sanctum')->getJson("/api/v1/reports/period_summary?period_id={$period->id}")->assertForbidden();
        $this->actingAs($manager, 'sanctum');
        foreach (KpiReportService::FORMATS as $type => $formats) {
            $query = http_build_query(array_filter(['period_id' => $period->id, 'kpi_id' => $type === 'individual' ? $kpiId : null]));
            $this->getJson("/api/v1/reports/{$type}?{$query}")->assertOk()->assertJsonPath('data.type', $type);
            foreach ($formats as $format) {
                $this->get("/api/v1/reports/{$type}/export/{$format}?{$query}")->assertOk();
            }
        }

    }

    public function test_web_uses_the_same_typed_report_export_and_scope(): void
    {
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $url = "/app/reports/period_summary/export/xlsx?period_id={$period->id}";

        $this->actingAs(User::where('email', 'kasir@toko.com')->firstOrFail(), 'web')->get($url)->assertForbidden();
        $this->actingAs(User::where('email', 'manager@toko.com')->firstOrFail(), 'web')->get($url)->assertOk();
    }

    public function test_due_report_reminders_are_idempotent_for_h3_and_h1(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-20 08:00:00');
        $submission = ReportSubmission::with('employee.user')->whereNotNull('employee_id')->firstOrFail();
        $submission->update(['deadline_at' => now()->addDays(3), 'submitted_at' => null, 'status' => 'scheduled']);

        $this->assertSame(0, Artisan::call('kpi:send-reminders'));
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $submission->employee->user_id,
            'type' => 'report_due_h3',
            'entity_type' => 'ReportSubmission',
            'entity_id' => (string) $submission->id,
        ]);
        $count = SystemNotification::count();
        Artisan::call('kpi:send-reminders');
        $this->assertSame($count, SystemNotification::count());

        $submission->update(['deadline_at' => now()->addDay()]);
        Artisan::call('kpi:send-reminders');
        $this->assertDatabaseHas('system_notifications', ['type' => 'report_due_h1', 'entity_id' => (string) $submission->id]);
    }

    public function test_openapi_and_dependency_health_are_exposed(): void
    {
        config()->set('services.clamav.fake_result', 'clean');
        config()->set('services.fcm.project_id', 'test-project');
        config()->set('services.fcm.bearer_token', 'test-token');
        config()->set('queue.default', 'database');

        $this->get('/api/v1/openapi')->assertOk()->assertHeader('Content-Type', 'application/yaml');
        $this->getJson('/health/ready')->assertOk()
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.queue', true)
            ->assertJsonPath('checks.clamav', true)
            ->assertJsonPath('checks.fcm', true);
    }

    public function test_push_is_deduplicated_safe_and_revokes_invalid_token(): void
    {
        Queue::fake();
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/devices/push-token', [
            'token' => 'valid-device-token', 'platform' => 'android', 'device_id' => 'phone-1', 'device_name' => 'Pixel',
        ])->assertOk();
        $notification = SystemNotification::send($user->id, 'Skor 99.50', 'Data sensitif', 'approval', 'EmployeeKpi', '01ABC');
        $duplicate = SystemNotification::send($user->id, 'Duplikat', 'Duplikat', 'approval', 'EmployeeKpi', '01ABC');
        $this->assertSame($notification->id, $duplicate->id);

        config()->set('services.fcm.project_id', 'test-project');
        config()->set('services.fcm.bearer_token', 'test-token');
        Http::fakeSequence()
            ->push(['name' => 'ok'])
            ->push(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404);
        (new SendPushNotification((string) $notification->id))->handle(app(FcmClient::class));
        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $encoded = json_encode($payload);

            return ! str_contains($encoded, '99.50')
                && ! str_contains($encoded, 'Data sensitif')
                && ($payload['message']['data']['entity_id'] ?? null) === '01ABC';
        });

        DeviceToken::where('token', 'valid-device-token')->update(['token' => 'invalid-device-token']);
        (new SendPushNotification((string) $notification->id))->handle(app(FcmClient::class));
        $this->assertNotNull(DeviceToken::where('token', 'invalid-device-token')->value('revoked_at'));
    }
}
