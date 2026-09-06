<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceTokenController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:android,ios,web'],
            'device_id' => ['required', 'string', 'max:191'],
            'device_name' => ['nullable', 'string', 'max:191'],
        ]);
        $token = DeviceToken::where('token', $data['token'])->first();
        $token ??= DeviceToken::where('user_id', $request->user()->id)
            ->where('platform', $data['platform'])->where('device_id', $data['device_id'])->first();
        $token ??= new DeviceToken;
        $token->fill([...$data, 'user_id' => $request->user()->id, 'last_seen_at' => now(), 'revoked_at' => null])->save();

        return response()->json(['success' => true, 'data' => $token]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['nullable', 'string'], 'device_id' => ['nullable', 'string']]);
        abort_if(empty($data['token']) && empty($data['device_id']), 422, 'Token atau device_id wajib diisi.');
        DeviceToken::where('user_id', $request->user()->id)
            ->when($data['token'] ?? null, fn ($query, string $token) => $query->where('token', $token))
            ->when($data['device_id'] ?? null, fn ($query, string $id) => $query->where('device_id', $id))
            ->update(['revoked_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Push token dicabut.']);
    }
}
