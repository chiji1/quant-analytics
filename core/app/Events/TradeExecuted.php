<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TradeExecuted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $tradeDetails;

    public function __construct(array $tradeDetails)
    {
        $this->tradeDetails = $tradeDetails;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('engine-metrics'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TradeExecuted';
    }
}
