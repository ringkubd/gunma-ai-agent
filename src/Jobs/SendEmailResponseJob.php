<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Jobs;

use Anwar\GunmaAgent\Models\ChatMessage;
use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Services\MarkdownService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendEmailResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $messageId
    ) {}

    public function handle(): void
    {
        $message = ChatMessage::find($this->messageId);
        if (!$message) return;

        $session = ChatSession::find($message->session_id);

        if ($session && $session->channel === 'email' && $message->role === 'assistant') {
            try {
                $customerEmail = $session->visitor_id; 
                $websiteUrl = config('gunma-agent.website_url', 'https://gunmahalalfood.com');
                $htmlContent = MarkdownService::toHtml($message->content, $websiteUrl);
                
                Log::info('[EmailJob] Sending response to: ' . $customerEmail);

                $fromEmail = config('mail.from.address', 'support@gunmahalalfood.com');

                Mail::send([], [], function ($mail) use ($customerEmail, $htmlContent, $fromEmail) {
                    $mail->to($customerEmail)
                        ->from($fromEmail, 'Gunma Halal Food Support')
                        ->subject('Re: Support Request - Gunma Halal Food')
                        ->html($htmlContent);
                });

                Log::info('[EmailJob] Response successfully sent to ' . $customerEmail);
            } catch (\Exception $e) {
                Log::error('[EmailJob] Failed to send email: ' . $e->getMessage());
            }
        }
    }
}
