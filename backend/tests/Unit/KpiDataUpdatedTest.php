<?php

namespace Tests\Unit;

use App\Events\KpiDataUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class KpiDataUpdatedTest extends TestCase
{
    public function test_event_uses_private_channel_and_minimal_payload(): void
    {
        $event = new KpiDataUpdated(42);

        $this->assertSame('kpi.updated', $event->broadcastAs());
        $this->assertSame(['period_id' => 42], $event->broadcastWith());
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-kpi-updates', $event->broadcastOn()[0]->name);
    }
}
