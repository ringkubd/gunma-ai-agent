<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Listeners;

use Anwar\GunmaAgent\Events\MessageBroadcasted;
use Anwar\GunmaAgent\Models\ChatSession;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;

class SendEmailResponse implements ShouldQueue
{
    use Queueable;

    /**
     * Handle the event.
     */
    public function handle(MessageBroadcasted $event): void
    {
        $message = $event->message;
        $session = ChatSession::find($message->session_id);

        // Only send if the channel is email AND the sender is not the customer (user)
        if ($session && $session->channel === 'email' && $message->role === 'assistant') {
            try {
                $customerEmail = $session->visitor_id; // For email channel, visitor_id is usually the email address
                
                Mail::send([], [], function ($mail) use ($customerEmail, $message) {
                    $mail->to($customerEmail)
                        ->subject('Re: Support Request - Gunma Halal Food')
                        ->html($message->content);
                });

                Log::info('[EmailSupport] Response sent to ' . $customerEmail);
            } catch (\Exception $e) {
                Log::error('[EmailSupport] Failed to send email: ' . $e->getMessage());
            }
        }
    }
}
