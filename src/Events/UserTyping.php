<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $role,
        public bool $isTyping
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('gunma-chat.' . $this->sessionId),
            new PrivateChannel('gunma-admin.chats'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'role'       => $this->role,
            'is_typing'  => $this->isTyping,
        ];
    }
}
