<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Events;

use Anwar\GunmaAgent\Models\ChatSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a chat session is ended (by an admin, the customer, or the
 * system). The widget listens and shows a thank-you message, then locks input.
 */
class SessionEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatSession $session,
        public ?string $endedBy = null,
        public ?string $message = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('gunma-chat.' . $this->session->id),
            new PrivateChannel('gunma-admin.chats'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'ended_by'   => $this->endedBy,
            'message'    => $this->message,
            'ended_at'   => optional($this->session->updated_at)->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'session.ended';
    }
}
