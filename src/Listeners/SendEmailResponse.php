<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Listeners;

use Anwar\GunmaAgent\Events\MessageBroadcasted;
use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Services\MarkdownService;
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
        \Illuminate\Support\Facades\Log::info('[EmailSupport] Listener hit for message: ' . $message->id . ' Role: ' . $message->role);

        $session = ChatSession::find($message->session_id);

        // Only send if the channel is email AND the sender is not the customer (user)
        if ($session && $session->channel === 'email' && $message->role === 'assistant') {
            try {
                $customerEmail = $session->visitor_id; 
                $websiteUrl = config('gunma-agent.website_url', 'https://gunmahalalfood.com');
                $htmlContent = MarkdownService::toHtml($message->content, $websiteUrl);
                
                \Illuminate\Support\Facades\Log::info('[EmailSupport] Attempting to send email to: ' . $customerEmail);

                $fromEmail = config('mail.from.address', 'support@gunmahalalfood.com');

                Mail::send([], [], function ($mail) use ($customerEmail, $htmlContent, $fromEmail) {
                    $mail->to($customerEmail)
                        ->from($fromEmail, 'Gunma Halal Food Support')
                        ->subject('Re: Support Request - Gunma Halal Food')
                        ->html($htmlContent);
                });

                \Illuminate\Support\Facades\Log::info('[EmailSupport] Response successfully sent to ' . $customerEmail);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('[EmailSupport] Failed to send email: ' . $e->getMessage());
            }
        } else {
            \Illuminate\Support\Facades\Log::info('[EmailSupport] Skipping email send. Channel: ' . ($session->channel ?? 'N/A'));
        }
    }
}
