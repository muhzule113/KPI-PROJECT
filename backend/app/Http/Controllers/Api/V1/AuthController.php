<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\CapabilityMatrix;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:191',
        ]);

        $user = User::with(['employee.position', 'employee.branch', 'roles'])->where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password yang Anda masukkan salah.',
            ], 401);
        }

        if ($error = CapabilityMatrix::accessError($user, 'mobile')) {
            return response()->json(['success' => false, 'message' => $error], 403);
        }

        $deviceName = $request->device_name ?? $request->header('User-Agent') ?? 'Mobile Device';
        $token = $user->createToken($deviceName, ['platform:mobile'])->plainTextToken;

        AuditEvent::log(
            action: 'api_login',
            subjectType: 'User',
            subjectId: (string) $user->id,
            actorId: $user->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                ],
                'capabilities' => CapabilityMatrix::for($user),
                'allowed_platforms' => CapabilityMatrix::allowedPlatforms($user),
                'employee' => $user->employee ? [
                    'id' => $user->employee->id,
                    'employee_number' => $user->employee->employee_number,
                    'name' => $user->employee->name,
                    'position' => $user->employee->position?->name,
                    'position_code' => $user->employee->position?->code,
                    'branch' => $user->employee->branch?->name,
                    'status' => $user->employee->status,
                ] : null,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['employee.position', 'employee.branch', 'roles']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                ],
                'capabilities' => CapabilityMatrix::for($user),
                'allowed_platforms' => CapabilityMatrix::allowedPlatforms($user),
                'employee' => $user->employee ? [
                    'id' => $user->employee->id,
                    'employee_number' => $user->employee->employee_number,
                    'name' => $user->employee->name,
                    'position' => $user->employee->position?->name,
                    'position_code' => $user->employee->position?->code,
                    'branch' => $user->employee->branch?->name,
                    'status' => $user->employee->status,
                ] : null,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Kata sandi saat ini salah.',
            ], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Kata sandi berhasil diperbarui.',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($data);

        return response()->json(['success' => true, 'message' => 'Jika email terdaftar, tautan reset telah dikirim.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $user->revokeAllSessions();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['success' => false, 'message' => __($status)], 422);
        }

        return response()->json(['success' => true, 'message' => 'Kata sandi berhasil direset. Silakan masuk kembali.']);
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $web = DB::table('sessions')->where('user_id', $user->id)->get()->map(fn ($session) => [
            'type' => 'web', 'id' => $session->id, 'device_name' => $session->user_agent,
            'ip_address' => $session->ip_address, 'last_used_at' => date(DATE_ATOM, $session->last_activity),
        ]);
        $mobile = $user->tokens()->get()->map(fn ($token) => [
            'type' => 'mobile', 'id' => (string) $token->id, 'device_name' => $token->name,
            'last_used_at' => $token->last_used_at?->toIso8601String(), 'created_at' => $token->created_at?->toIso8601String(),
        ]);

        return response()->json(['success' => true, 'data' => $web->concat($mobile)->values()]);
    }

    public function revokeSession(Request $request, string $type, string $id): JsonResponse
    {
        abort_unless(in_array($type, ['web', 'mobile'], true), 404);
        $query = $type === 'web'
            ? DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $id)
            : $request->user()->tokens()->whereKey($id);
        abort_unless($query->delete(), 404);

        return response()->json(['success' => true, 'message' => 'Sesi berhasil dicabut.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->revokeAllSessions();

        return response()->json(['success' => true, 'message' => 'Semua sesi telah dicabut.']);
    }
}
