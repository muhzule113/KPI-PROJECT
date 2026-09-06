<?php

namespace App\Http\Controllers;

use App\Modules\Security\FileScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class DeploymentHealthController extends Controller
{
    public function __invoke(FileScanService $scanner): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('SELECT 1') !== []),
            'queue' => $this->check(fn () => config('queue.default') !== 'sync'
                && (config('queue.default') !== 'database' || Schema::hasTable((string) config('queue.connections.database.table', 'jobs')))),
            'clamav' => $this->check(fn () => $scanner->ping()),
            'fcm' => (bool) (config('services.fcm.project_id')
                && (config('services.fcm.bearer_token') || config('services.fcm.service_account'))),
        ];
        $healthy = ! in_array(false, $checks, true);

        return response()->json(['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks], $healthy ? 200 : 503);
    }

    private function check(callable $callback): bool
    {
        try {
            return (bool) $callback();
        } catch (Throwable) {
            return false;
        }
    }
}
