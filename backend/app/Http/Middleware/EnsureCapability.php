<?php

namespace App\Http\Middleware;

use App\Support\CapabilityMatrix;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        abort_unless($request->user() && CapabilityMatrix::has($request->user(), $capability), 403, 'Anda tidak memiliki akses untuk tindakan ini.');

        return $next($request);
    }
}
