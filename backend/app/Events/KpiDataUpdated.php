<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class KpiDataUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $periodId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('kpi-updates')];
    }

    public function broadcastAs(): string
    {
        return 'kpi.updated';
    }

    public function broadcastWith(): array
    {
        return ['period_id' => $this->periodId];
    }
}
