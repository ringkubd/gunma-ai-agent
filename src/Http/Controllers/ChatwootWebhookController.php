<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Http\Controllers;

use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Services\ChatwootService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Receives Chatwoot webhook events and lets Piku auto-reply.
 *
 * POST /api/chat/webhook/chatwoot
 * Secure with a shared secret header (X-Chatwoot-Signature HMAC or X-Webhook-Secret).
 */
class ChatwootWebhookController extends Controller
{
    public function __construct(
        private readonly ChatwootService $chatwoot,
    ) {}

    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        $raw = $request->getContent();

        // Verify authenticity.
        $hmacSecret = (string) config('gunma-agent.chatwoot.webhook_secret', '');
        $signature = $request->header('X-Chatwoot-Signature');
        $sharedSecret = (string) config('gunma-agent.chatwoot.shared_secret', '');

        $authorized = false;
        if ($sharedSecret !== '' && hash_equals($sharedSecret, (string) $request->header('X-Webhook-Secret', ''))) {
            $authorized = true;
        }
        if (! $authorized && $hmacSecret !== '' && ChatwootService::verifyWebhookSignature($raw, $signature, $hmacSecret)) {
            $authorized = true;
        }
        if (! $authorized) {
            Log::warning('[Chatwoot] Rejected webhook: invalid signature');
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $event = $request->input('event');
        $data = $request->input('data', []);

        // Only react to new incoming customer messages.
        if ($event !== 'message_created') {
            return response()->json(['status' => 'ignored', 'event' => $event]);
        }

        $messageType = $data['message_type'] ?? $data['message']['message_type'] ?? '';
        $content = $data['content'] ?? $data['message']['content'] ?? '';
        $conversationId = (int) ($data['conversation']['id'] ?? $data['conversation_id'] ?? 0);
        $inboxId = (int) ($data['inbox']['id'] ?? 0);

        if ($messageType !== 'incoming' || $conversationId <= 0 || trim((string) $content) === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'not an incoming message']);
        }

        // Derive a stable visitor identity + channel from the Chatwoot conversation.
        $contact = $data['sender'] ?? $data['contact'] ?? [];
        $contactId = $contact['id'] ?? null;
        $contactName = trim(($contact['name'] ?? '') ?: 'Chatwoot Customer');
        $contactEmail = $contact['email'] ?? null;
        $channel = $this->resolveChannel($data['inbox']['channel_type'] ?? $data['channel'] ?? null);

        $visitorId = 'chatwoot:' . ($contactId ?: $conversationId);

        try {
            $session = ChatSession::firstOrCreate(
                ['visitor_id' => $visitorId, 'channel' => $channel],
                [
                    'status'        => 'active',
                    'is_ai_enabled' => true,
                    'customer_name' => $contactName,
                    'customer_email'=> $contactEmail,
                    'metadata'      => ['chatwoot_conversation_id' => $conversationId, 'chatwoot_inbox_id' => $inboxId],
                ]
            );

            if (! $session->is_ai_enabled) {
                return response()->json(['status' => 'ignored', 'reason' => 'ai disabled for session']);
            }

            // Generate the reply on the queue so the webhook returns immediately.
            \Anwar\GunmaAgent\Jobs\ProcessChatwootMessage::dispatch(
                $session->id,
                $conversationId,
                (string) $content
            );

            return response()->json(['status' => 'success', 'session_id' => $session->id]);
        } catch (\Exception $e) {
            Log::error('[Chatwoot] webhook processing error', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Internal server error'], 500);
        }
    }

    private function resolveChannel(?string $channelType): string
    {
        return match (strtolower((string) $channelType)) {
            'whatsapp'   => 'whatsapp',
            'facebook', 'messenger' => 'facebook',
            'instagram'  => 'instagram',
            'telegram'   => 'telegram',
            'email'      => 'email',
            'web_widget' => 'web',
            default      => 'chatwoot',
        };
    }
}
