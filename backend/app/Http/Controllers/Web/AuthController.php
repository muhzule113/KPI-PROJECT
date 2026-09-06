<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\CapabilityMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class AuthController extends Controller
{
    public function showLogin(): Response|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('app.dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        if (! Auth::guard('web')->attempt(['email' => $credentials['email'], 'password' => $credentials['password']], (bool) ($credentials['remember'] ?? false))) {
            return back()->withErrors(['email' => 'Email atau kata sandi yang Anda masukkan salah.'])->onlyInput('email');
        }

        $user = Auth::guard('web')->user()->loadMissing(['employee.position', 'employee.branch', 'roles']);
        if ($error = CapabilityMatrix::accessError($user, 'web')) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => $error])->onlyInput('email');
        }

        $request->session()->regenerate();
        AuditEvent::log('web_login', 'User', (string) $user->getKey(), actorId: $user->getKey());

        return redirect()->intended(route('app.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $userId = $request->user()?->getKey();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($userId) {
            AuditEvent::log('web_logout', 'User', (string) $userId, actorId: $userId);
        }

        return redirect()->route('login');
    }
}
