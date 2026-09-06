<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\SystemNotification;
use App\Modules\Notification\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendPushNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public string $notificationId) {}

    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(FcmClient $fcm): void
    {
        $notification = SystemNotification::find($this->notificationId);
        if (! $notification) {
            return;
        }
        $safe = $this->safeText($notification->type);
        foreach (DeviceToken::where('user_id', $notification->user_id)->whereNull('revoked_at')->get() as $device) {
            $result = $fcm->send($device->token, $safe, [
                'event' => $notification->type,
                'entity_type' => $notification->entity_type,
                'entity_id' => $notification->entity_id,
                'notification_id' => $notification->id,
            ]);
            if ($result === 'invalid') {
                $device->update(['revoked_at' => now()]);
            }
        }
    }

    private function safeText(string $type): array
    {
        return match ($type) {
            'period_opened' => ['title' => 'Periode KPI dibuka', 'body' => 'Buka aplikasi untuk melihat periode terbaru.'],
            'revision_required' => ['title' => 'Revisi KPI diperlukan', 'body' => 'Buka aplikasi untuk melihat detail revisi.'],
            'import_ready' => ['title' => 'Import siap ditinjau', 'body' => 'Buka aplikasi untuk memeriksa hasil import.'],
            'import_failed' => ['title' => 'Import gagal', 'body' => 'Buka aplikasi untuk melihat penyebab kegagalan.'],
            default => ['title' => 'Pembaruan KPI', 'body' => 'Buka aplikasi untuk melihat pembaruan terbaru.'],
        };
    }
}
