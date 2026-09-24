<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Observers;

use Anwar\GunmaAgent\Events\SessionEnded;
use Anwar\GunmaAgent\Models\ChatSession;

/**
 * Broadcasts SessionEnded whenever a session's status transitions to "ended",
 * regardless of who ended it (customer, admin dashboard, or system).
 */
class ChatSessionObserver
{
    public function updated(ChatSession $session): void
    {
        if (! $session->wasChanged('status')) {
            return;
        }

        if ($session->status !== 'ended') {
            return;
        }

        $endedBy = null;
        if (auth()->check()) {
            $endedBy = 'admin';
        } elseif (auth('customer')->check()) {
            $endedBy = 'customer';
        } else {
            $endedBy = 'system';
        }

        event(new SessionEnded($session, $endedBy));
    }
}
