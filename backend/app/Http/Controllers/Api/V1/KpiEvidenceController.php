<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KpiEvidence;
use App\Support\KpiVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KpiEvidenceController extends Controller
{
    public function download(Request $request, int $evidenceId): StreamedResponse
    {
        $evidence = KpiEvidence::with('item.employeeKpi.employee')->find($evidenceId);
        abort_unless($evidence && $evidence->scan_status === 'clean', 404);

        $kpi = $evidence->item?->employeeKpi;
        abort_unless($kpi, 404);
        abort_unless($this->canAccess($request, $kpi), 403);
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);

        return Storage::disk('local')->download(
            $evidence->file_path,
            $evidence->file_name,
            ['Content-Type' => $evidence->mime_type]
        );
    }

    private function canAccess(Request $request, $kpi): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        return KpiVisibility::canRead($user, $kpi);
    }
}
