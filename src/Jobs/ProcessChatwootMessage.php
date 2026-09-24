<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Jobs;

use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Services\AgentOrchestrator;
use Anwar\GunmaAgent\Services\ChatwootService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate a Piku reply for an inbound Chatwoot message and post it back.
 * Runs on the queue so the webhook can return 200 immediately.
 */
class ProcessChatwootMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public string $sessionId,
        public int $conversationId,
        public string $message,
    ) {}

    public function handle(AgentOrchestrator $orchestrator, ChatwootService $chatwoot): void
    {
        $session = ChatSession::find($this->sessionId);
        if (! $session || ! $session->is_ai_enabled) {
            return;
        }

        try {
            $reply = $orchestrator->chat($session, $this->message);
            if (trim($reply) !== '') {
                $chatwoot->sendMessage($this->conversationId, $reply, 'outgoing');
            }
        } catch (\Exception $e) {
            Log::error('[Chatwoot] reply job failed', [
                'session_id' => $this->sessionId,
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
