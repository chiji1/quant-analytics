<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SignalRejected implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $signal;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $symbol, float $score, string $reason, string $strategyName = '')
    {
        $this->signal = [
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'symbol' => $symbol,
            'score' => $score,
            'reason' => $reason,
            'strategy' => $strategyName,
            'timestamp' => now()->timestamp * 1000,
            'status' => 'REJECTED'
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('engine-metrics')
        ];
    }

    public function broadcastAs(): string
    {
        return 'SignalRejected';
    }

    public function broadcastWith()
    {
        return $this->signal;
    }
}
