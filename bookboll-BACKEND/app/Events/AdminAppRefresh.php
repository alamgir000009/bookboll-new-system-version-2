<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdminAppRefresh implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public array $payload = []
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('refresh.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'refresh';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
