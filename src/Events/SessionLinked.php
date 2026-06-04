<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SessionLinked implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $sessionId,
        public ?string $customerName,
        public ?string $customerEmail,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('gunma-admin.chats'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'session_id'     => $this->sessionId,
            'customer_name'  => $this->customerName,
            'customer_email' => $this->customerEmail,
        ];
    }

    public function broadcastAs(): string
    {
        return 'session.linked';
    }
}
