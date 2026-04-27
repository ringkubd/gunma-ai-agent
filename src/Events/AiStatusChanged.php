<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Events;

use Anwar\GunmaAgent\Models\ChatSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AiStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatSession $session
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('gunma-chat.' . $this->session->id),
            new PrivateChannel('gunma-admin.chats'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'session_id'    => $this->session->id,
            'is_ai_enabled' => $this->session->is_ai_enabled,
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.status_changed';
    }
}
