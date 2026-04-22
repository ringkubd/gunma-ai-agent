<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Http\Controllers;

use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Services\AgentOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class EmailWebhookController extends Controller
{
    public function __construct(
        private readonly AgentOrchestrator $orchestrator
    ) {}

    /**
     * Handle incoming email from a webhook (e.g., Mailgun, SendGrid).
     */
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        // Extract data from Python bridge or other providers
        $rawSender = $request->input('sender'); // e.g., "John Doe <customer@example.com>"
        $subject   = $request->input('subject');
        $body      = $request->input('body-plain') ?? $request->input('stripped-text') ?? $request->input('body') ?? '';
        
        // Extract only email using regex if needed
        $sender = $rawSender;
        if (preg_match('/<([^>]+)>/', $rawSender, $matches)) {
            $sender = $matches[1];
        }

        if (!$sender || !$body) {
            return response()->json(['status' => 'error', 'message' => 'Missing data'], 400);
        }

        Log::info('[EmailSupport] Processing email from ' . $sender . ' with subject: ' . $subject);

        try {
            // 1. Find or create session for this email
            $session = ChatSession::firstOrCreate(
                ['visitor_id' => $sender, 'channel' => 'email'],
                ['status' => 'active', 'is_ai_enabled' => true, 'customer_name' => $rawSender]
            );

            // Ensure AI is active
            $session->update(['is_ai_enabled' => true]);

            Log::info('[EmailSupport] Session ready: ' . $session->id);

            // 2. Dispatch SYNC for testing (Running immediately in this process)
            \Anwar\GunmaAgent\Jobs\ProcessIncomingEmail::dispatchSync($session->id, $body);

            Log::info('[EmailSupport] AI Processing completed synchronously for session ID ' . $session->id);
            
            return response()->json(['status' => 'success', 'session_id' => $session->id]);
        } catch (\Exception $e) {
            Log::error('[EmailSupport] Error processing email webhook: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Internal server error'], 500);
        }
    }
}
