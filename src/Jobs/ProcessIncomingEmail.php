<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Jobs;

use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Services\AgentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $connection = 'database'; // Force database if that's what you're using
    public $queue = 'default';

    public function __construct(
        public int|string $sessionId,
        public string $messageBody
    ) {}

    public function handle(AgentOrchestrator $orchestrator): void
    {
        try {
            $session = ChatSession::find($this->sessionId);
            
            if (!$session) {
                \Illuminate\Support\Facades\Log::error('[EmailJob] Session not found: ' . $this->sessionId);
                return;
            }

            \Illuminate\Support\Facades\Log::info('[EmailJob] Starting AI chat for session: ' . $session->id);
            
            // AI processes the email and generates a response
            $orchestrator->chat($session, $this->messageBody);
            
            \Illuminate\Support\Facades\Log::info('[EmailJob] AI chat completed for session: ' . $session->id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[EmailJob] Job failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
