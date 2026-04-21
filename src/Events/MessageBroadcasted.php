<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Events;

use Anwar\GunmaAgent\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageBroadcasted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('gunma-chat.' . $this->message->session_id),
            new PrivateChannel('gunma-admin.chats'), // For the dashboard monitor
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->message->id,
            'session_id' => $this->message->session_id,
            'role'       => $this->message->role,
            'content'    => $this->message->content,
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.new';
    }
}
