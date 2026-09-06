<?php

namespace App\Http\Controllers;

use App\Modules\Service\ServiceTicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ServiceTicketEvidenceController extends Controller
{
    public function download(Request $request, string $id, int $index, ServiceTicketService $tickets): StreamedResponse
    {
        $ticket = $tickets->scopeTickets($request->user())->findOrFail($id);
        $evidence = $ticket->technical_evidence_json[$index] ?? [];
        abort_unless(($evidence['scan_status'] ?? null) === 'clean', 404);
        $path = $evidence['file_path'] ?? '';
        abort_unless(str_starts_with($path, "quarantine/service-tickets/{$ticket->id}/evidence/") && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $evidence['file_name'] ?? basename($path));
    }
}
