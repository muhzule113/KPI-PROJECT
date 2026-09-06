<?php

namespace App\Models;

use App\Jobs\SendPushNotification;
use App\Support\KpiVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemNotification extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'title',
        'body',
        'type',
        'entity_type',
        'entity_id',
        'action_url',
        'dedupe_key',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function visiblePayload(User $user): ?array
    {
        if ((int) $this->user_id !== (int) $user->id) {
            return null;
        }

        $destination = null;
        $body = $this->body;
        $title = $this->title;
        $dailyEntry = $this->entity_type === 'KpiDailyEntry' ? KpiDailyEntry::find($this->entity_id) : null;
        $kpi = match ($this->entity_type) {
            'EmployeeKpi' => EmployeeKpi::with('period')->find($this->entity_id),
            'KpiDailyEntry' => $dailyEntry?->item?->employeeKpi,
            default => null,
        };
        if (in_array($this->entity_type, ['EmployeeKpi', 'KpiDailyEntry'], true)) {
            if (! $kpi || ! KpiVisibility::canRead($user, $kpi)) {
                return null;
            }
            $own = (string) $user->employee?->id === (string) $kpi->employee_id;
            $screen = $own ? 'my-kpi' : ($user->hasRole('owner_manager') ? 'approval' : 'review');
            $destination = ['screen' => $screen, 'kpi_id' => $kpi->id, 'period_id' => $kpi->period_id];
            if ($dailyEntry && ! $own) {
                $destination = ['screen' => 'daily', 'manager' => $user->hasRole('owner_manager'), 'entry_id' => $dailyEntry->id, 'date' => $dailyEntry->entry_date->toDateString()];
            }
            if ($own && ! KpiVisibility::published($kpi->period)) {
                $title = 'Perkembangan KPI';
                $body = 'Status KPI diperbarui. Skor dan predikat tersedia setelah periode dipublikasikan.';
            }
        }

        return [
            'id' => $this->id,
            'title' => $title,
            'body' => $body,
            'type' => $this->type,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'action_url' => $this->action_url,
            'destination' => $destination,
            'is_read' => (bool) $this->is_read,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function send(
        int $userId,
        string $title,
        string $body,
        string $type = 'info',
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $actionUrl = null
    ): self {
        $dedupeKey = hash('sha256', implode('|', [$type, $entityType, $entityId, $userId]));
        $notification = self::firstOrCreate(['dedupe_key' => $dedupeKey], [
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action_url' => $actionUrl,
            'is_read' => false,
        ]);
        if ($notification->wasRecentlyCreated) {
            SendPushNotification::dispatch((string) $notification->id)->afterCommit();
        }

        return $notification;
    }
}
