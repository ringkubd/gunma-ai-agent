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

    public function __construct(
        public ChatSession $session,
        public string $messageBody
    ) {}

    public function handle(AgentOrchestrator $orchestrator): void
    {
        try {
            \Illuminate\Support\Facades\Log::info('[EmailJob] Starting AI chat for session: ' . $this->session->id);
            
            // AI processes the email and generates a response
            $orchestrator->chat($this->session, $this->messageBody);
            
            \Illuminate\Support\Facades\Log::info('[EmailJob] AI chat completed for session: ' . $this->session->id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[EmailJob] Job failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Re-throw to let queue handle retries
        }
    }
}
