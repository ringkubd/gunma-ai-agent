<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chatwoot REST API client.
 *
 * Bridges external channels (WhatsApp, Facebook, Instagram, Email, Website)
 * unified in Chatwoot with the Piku agent. Configure via config/gunma-agent.php
 * `chatwoot` block / env vars.
 */
class ChatwootService
{
    private string $baseUrl;
    private string $apiKey;
    private int $accountId;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('gunma-agent.chatwoot.base_url', ''), '/');
        $this->apiKey = (string) config('gunma-agent.chatwoot.api_key', '');
        $this->accountId = (int) config('gunma-agent.chatwoot.account_id', 1);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    private function headers(): array
    {
        return [
            'api_access_token' => $this->apiKey,
            'Content-Type'     => 'application/json',
        ];
    }

    /**
     * Create or update a contact. Returns the Chatwoot contact id.
     */
    public function syncContact(array $attributes): ?int
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(20)
                ->post("{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts", $attributes);

            if ($response->successful()) {
                return $response->json('payload.contact.id');
            }

            Log::warning('[Chatwoot] contact sync failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
        } catch (\Exception $e) {
            Log::error('[Chatwoot] contact sync error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Send a message into a conversation (agent/Piku reply).
     */
    public function sendMessage(int $conversationId, string $content, string $type = 'outgoing'): bool
    {
        if (! $this->isConfigured() || $conversationId <= 0) {
            return false;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(20)
                ->post("{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages", [
                    'content'      => $content,
                    'message_type' => $type,
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('[Chatwoot] send message error', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Toggle a conversation's status (e.g. open / pending / resolved).
     */
    public function setConversationStatus(int $conversationId, string $status): bool
    {
        if (! $this->isConfigured() || $conversationId <= 0) {
            return false;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(20)
                ->post("{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/toggle_status", [
                    'status' => $status,
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('[Chatwoot] status update error', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get a single conversation (includes contact metadata).
     */
    public function getConversation(int $conversationId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(20)
                ->get("{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}");

            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::error('[Chatwoot] get conversation error', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Verify the inbound webhook HMAC signature (Chatwoot signs the raw body).
     */
    public static function verifyWebhookSignature(string $payload, ?string $signature, string $secret): bool
    {
        if (! $signature || $secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}
