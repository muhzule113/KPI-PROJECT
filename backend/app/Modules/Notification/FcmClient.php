<?php

namespace App\Modules\Notification;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FcmClient
{
    public function send(string $token, array $notification, array $data): string
    {
        $project = config('services.fcm.project_id');
        $bearer = $this->bearerToken();
        if (! $project || ! $bearer) {
            return 'disabled';
        }
        $response = Http::withToken($bearer)->post(
            "https://fcm.googleapis.com/v1/projects/{$project}/messages:send",
            ['message' => ['token' => $token, 'notification' => $notification, 'data' => array_map('strval', $data)]],
        );
        if ($response->successful()) {
            return 'sent';
        }
        $body = $response->body();
        if (in_array($response->status(), [400, 404], true) && (str_contains($body, 'UNREGISTERED') || str_contains($body, 'registration-token-not-registered'))) {
            return 'invalid';
        }
        $response->throw();

        return 'failed';
    }

    private function bearerToken(): ?string
    {
        if ($token = config('services.fcm.bearer_token')) {
            return (string) $token;
        }
        $configuredPath = config('services.fcm.service_account');
        if (! $configuredPath) {
            return null;
        }
        $path = is_file((string) $configuredPath) ? (string) $configuredPath : base_path((string) $configuredPath);
        $credentials = json_decode((string) @file_get_contents($path), true);
        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException('Kredensial service account FCM tidak valid.');
        }

        return Cache::remember('fcm-access-token-'.sha1($path), 3000, function () use ($credentials): string {
            $now = time();
            $encode = fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
            $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
                'iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]);
            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Gagal menandatangani token FCM.');
            }
            $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            $response = Http::asForm()->post($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
            ])->throw();

            return (string) $response->json('access_token');
        });
    }
}
