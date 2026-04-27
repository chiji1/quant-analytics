<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SentimentScored implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public float $score;

    public function __construct(float $score)
    {
        $this->score = $score;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('engine-metrics'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SentimentScored';
    }
}
