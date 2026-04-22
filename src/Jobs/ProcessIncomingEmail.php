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
        // AI processes the email and generates a response
        // This will trigger MessageBroadcasted event, which is caught by SendEmailResponse listener
        $orchestrator->chat($this->session, $this->messageBody);
    }
}
