<?php

namespace App\Http\Middleware;

use App\Support\CapabilityMatrix;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformAccess
{
    public function handle(Request $request, Closure $next, string $platform): Response
    {
        $user = $request->user()->refresh()->load(['employee.position', 'employee.branch', 'roles']);
        $token = $user->currentAccessToken();
        if ($platform === 'authenticated') {
            $platform = $token instanceof PersonalAccessToken ? 'mobile' : 'web';
        }
        $error = CapabilityMatrix::accessError($user, $platform);
        if (! $error && $platform === 'mobile' && (! $token instanceof PersonalAccessToken
            || ! in_array('platform:mobile', $token->abilities ?? [], true))) {
            $error = 'Sesi mobile tidak valid. Silakan masuk kembali melalui aplikasi mobile.';
        }

        if ($error) {
            if ($platform === 'web') {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['email' => $error]);
            }
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json(['success' => false, 'code' => 'SESSION_REVOKED', 'message' => $error], 403);
        }

        return $next($request);
    }
}
