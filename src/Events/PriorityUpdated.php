<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Events;

use Anwar\GunmaAgent\Models\ChatSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PriorityUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatSession $session,
        public int $priorityScore
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
            'session_id'     => $this->session->id,
            'priority_score' => $this->priorityScore,
        ];
    }

    public function broadcastAs(): string
    {
        return 'priority.updated';
    }
}
