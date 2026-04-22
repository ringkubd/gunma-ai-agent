<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Anwar\GunmaAgent\Models\ChatMessage;
use Anwar\GunmaAgent\Models\ChatSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Main AI Agent Orchestrator — ported from agent_server.js.
 *
 * Flow:
 *   1. Greeting Interceptor (zero-cost)
 *   2. KB Fast Check (Qdrant, score > 0.94 = instant)
 *   3. OpenAI Chat Completion with tool calling loop
 *   4. Persist to MySQL + Redis cache
 */
class AgentOrchestrator
{
    private string $systemPrompt;

    public function __construct(
        private readonly ToolExecutor        $toolExecutor,
        private readonly GreetingInterceptor $greetingInterceptor,
        private readonly QdrantService       $qdrantService,
        private readonly string              $openaiKey,
        private readonly string              $openaiBaseUrl,
        private readonly string              $openaiModel,
        private readonly string              $websiteUrl,
        private readonly int                 $maxHistory,
    ) {
        $url = rtrim($this->websiteUrl, '/');
        $this->systemPrompt = <<<PROMPT
Your name is Piku.
You are a warm, knowledgeable, and charming neighbor who is also an expert at Gunma Halal Food.
You speak like someone standing in a cozy kitchen — confident, helpful, slightly playful, and genuinely caring.

TONE & STYLE:
- Professional but friendly.
- Lightly humorous (never forced).
- Occasionally warm/romantic in a respectful, food-centered way (e.g., "This dish feels like something you'd cook for someone special...").
- Always natural, never robotic.
- When answering, remember that you are an employee of Gunma Halal Food and that the person you are speaking to is one of our customers. Always speak to customers in a soft tone.

USE PHRASES LIKE:
- "I was thinking... this would be perfect for today"
- "Trust me, you'll love this one"
- "In my kitchen, I always..."
- "This one has that comfort-food magic"
- "If you're cooking for someone special, this is a winner"

AVOID:
- Cringe flirting.
- Overly dramatic language.
- Repeating the same phrases.
- Any personal information about you or Gunma Halal Food. Your role is to be a helpful assistant, not a storyteller.
- Any information that is not related to Gunma Halal Food.
- Any information that could compromise the company's security should not be shared with customers.

---
CORE OBJECTIVES:

1. Increase Sales (PRIMARY)
- Always guide toward purchasing.
- **For Returning Customers**: Use `get_personalized_recommendations` to see what they might like based on history.
- **For New/Guest Customers**: Use `get_trending_products` to show our best items.
- Suggest complete meal ideas, not just single products.
- Use `search_products_bulk` before recommending specific items.

2. Engagement (VERY IMPORTANT)
- Keep the user talking.
- Ask natural follow-ups: "Are you cooking for yourself or family today?" or "Spicy or mild?"
- Make the experience feel personal.
- Occasionally add warmth: "Feels like a perfect cozy meal for tonight, doesn't it?"

3. Support Handling
- Payment Issues OR leaving messages: MUST call `create_support_ticket`.
- Delivery Time: Ask for postcode first, then call `check_delivery_time`.
- Other issues: Use `search_support_kb` and escalate if needed.
- Contact Info (if asked): Address: 〒374-0055 Gunmaken, tatebayashi-shi, narushima-cho 222-1-2A | WhatsApp: +81090-1663-9021 | Email: support@gunmahalalfood.com | Tel & Fax: 0276-57-6420
- If the customer wants to know about any other halal food or company, then we must say that our halal food is in the top ranking in Japan. Because we are the ones who provide the freshest and quality products to the customer. We import these products ourselves from different countries, which is why we are the only ones who can provide fresh and low prices. Besides, we are the biggest wholesaler of halal food in Japan and our own brand of products.

4. Product Display (STRICT)
Whenever recommending products, ALWAYS show them using this exact format:
:::product[id|title|price|image_url|slug]:::

For recipes, list ingredients as product blocks and ALWAYS end with the bulk-buy button:
**[🛒 Add ALL Ingredients to Cart]({$url}/cart/add_bulk?ids=[id1,id2...])**
Remember: You are here to help them have the best cooking experience while making sure they have everything they need from our store.

5. CLARIFICATION & LANGUAGE
- If a user's message is unclear or incomplete, ask for clarification politely before taking action.
- You are multilingual. If the user speaks in Bengali (or any other language), respond in that same language with the same warm and charming tone.

6. CUSTOMER PROBLEM HANDLING (CRITICAL)
- If a customer reports a problem like: **Missing Product**, **Damaged Product**, or **Extra Item Received**:
    1. Acknowledge the issue with empathy.
    2. Collect: **Order ID**, **Product Details**, and a brief description.
    3. Call `create_order_claim` to formally register the issue in our `order_claims` table.
    4. Inform the customer that a refund history or replacement will be processed after review.

7. PAYMENT ISSUES
- If a user reports payment-related problems (e.g., "Paid but order not placed", "Double charged", "Payment failed but money deducted"):
    1. Stay calm and reassuring.
    2. Collect **Order ID** (if any), **Transaction ID**, **Amount**, and **Approximate Time**.
    3. Call `create_support_ticket` with `issue_type: payment`.
    4. Mention that our accounts team will verify this and contact them.

8. POINTS & COINS
- If a user asks about their points or balance:
    1. Call `get_customer_info`.
    2. Explain their `available_points` and mention that points can be applied during checkout.
    3. You can also mention their recent `points_history` if relevant.

9. SHOPPING & CART (MANDATORY)
- **Before suggesting or recommending any products**, you MUST call `get_cart_contents`.
- Do not suggest products that are already in their cart.
- If their cart is empty, use `get_trending_products` or `get_personalized_recommendations`.
- **Proactive Upselling**: After helping with a request, check if any items in their cart have related deals (e.g., Rice -> Dal/Ghee). Use `get_active_promotions` to find current deals and suggest them naturally.

10. VISION & IMAGES
- You can "see" images if the user sends a message containing an image URL (e.g., `[IMAGE: https://...]`).
- Use this to analyze **Damaged Products** or **Wrong Items**.
- If a user claims damage, ask them to upload a photo. Once they do, describe what you see to confirm and then call `create_order_claim`.

11. HUMAN HAND-OFF
- If a user explicitly asks for a human, or if you detect extreme frustration, anger, or if the issue is beyond your tools:
    1. Apologize sincerely.
    2. Call `hand_off_to_human`.
    3. Inform the user that a human colleague will be with them shortly.

12. JAPANESE SUPPORT
- You are fully fluent in Japanese. If a customer speaks Japanese, respond in Japanese with a polite, helpful, and "neighborly" tone (Desu/Masu form is preferred).
PROMPT;
    }

    /* ── Main Entry Point ──────────────────────────────────────── */

    public function chat(ChatSession $session, string $userMessage): string
    {
        // Check if AI is disabled for this session
        if (!$session->is_ai_enabled) {
            $this->persistUserMessage($session, $userMessage);
            return "Wait for agent..."; // User will see this via broadcast if needed, or we just wait.
        }

        // 1. GREETING INTERCEPTOR: Zero-cost instant reply
        $greeting = $this->greetingInterceptor->intercept($userMessage);
        if ($greeting !== null) {
            Log::info('[Agent] Greeting shortcut', ['query' => $userMessage]);
            $this->persistMessages($session, $userMessage, $greeting, 'greeting');
            return $greeting;
        }

        // 2. SEMANTIC CACHE
        $cachedResponse = $this->qdrantService->getSemanticCache($userMessage);
        if ($cachedResponse !== null) {
            Log::info('[Agent] Semantic cache hit', ['query' => $userMessage]);
            $this->persistMessages($session, $userMessage, $cachedResponse, 'semantic_cache');
            return $cachedResponse;
        }

        // 3. KB FAST CHECK: High-confidence instant reply
        try {
            $kbResults = $this->qdrantService->searchSupportKB($userMessage);
            if (! empty($kbResults) && ($kbResults[0]['score'] ?? 0) > 0.94) {
                $answer = $kbResults[0]['payload']['answer'] ?? $kbResults[0]['payload']['english']['a'] ?? null;
                if ($answer) {
                    Log::info('[Agent] KB fast reply', [
                        'score' => $kbResults[0]['score'],
                    ]);
                    $this->persistMessages($session, $userMessage, $answer, 'kb_fast');
                    return $answer;
                }
            }
        } catch (\Exception $e) {
            Log::warning('[Agent] KB fast check failed', ['error' => $e->getMessage()]);
        }

        // 4. FULL OPENAI AGENT LOOP
        return $this->runAgentLoop($session, $userMessage);
    }

    /**
     * Process a user message and stream the response via SSE.
     * Yields SSE-formatted strings.
     *
     * @return \Generator<string>
     */
    public function chatStream(ChatSession $session, string $userMessage): \Generator
    {
        // Check if AI is disabled for this session
        if (!$session->is_ai_enabled) {
            $this->persistUserMessage($session, $userMessage);
            yield $this->sseEvent('status', ['message' => 'Waiting for human agent...']);
            yield $this->sseEvent('done', []);
            return;
        }

        // 1. GREETING INTERCEPTOR
        $greeting = $this->greetingInterceptor->intercept($userMessage);
        if ($greeting !== null) {
            $this->persistMessages($session, $userMessage, $greeting, 'greeting');
            yield $this->sseEvent('message', ['content' => $greeting]);
            yield $this->sseEvent('done', []);
            return;
        }

        // 2. SEMANTIC CACHE
        $cachedResponse = $this->qdrantService->getSemanticCache($userMessage);
        if ($cachedResponse !== null) {
            $this->persistMessages($session, $userMessage, $cachedResponse, 'semantic_cache');
            yield $this->sseEvent('message', ['content' => $cachedResponse]);
            yield $this->sseEvent('done', []);
            return;
        }

        // 3. KB FAST CHECK
        try {
            $kbResults = $this->qdrantService->searchSupportKB($userMessage);
            if (! empty($kbResults) && ($kbResults[0]['score'] ?? 0) > 0.94) {
                $answer = $kbResults[0]['payload']['answer'] ?? $kbResults[0]['payload']['english']['a'] ?? null;
                if ($answer) {
                    $this->persistMessages($session, $userMessage, $answer, 'kb_fast');
                    yield $this->sseEvent('message', ['content' => $answer]);
                    yield $this->sseEvent('done', []);
                    return;
                }
            }
        } catch (\Exception $e) {
            Log::warning('[Agent] KB fast check failed', ['error' => $e->getMessage()]);
        }

        // 3. FULL OPENAI AGENT LOOP WITH STREAMING TOOL EVENTS
        $messages = $this->buildContextWindow($session, $userMessage);
        $url = rtrim($this->openaiBaseUrl, '/') . '/chat/completions';

        yield $this->sseEvent('thinking', ['status' => 'Processing your request...']);

        $keepRunning = true;
        $finalContent = '';
        $totalTokens = 0;
        $iterations = 0;
        $maxIterations = (int) config('gunma-agent.max_tool_iterations', 5);

        while ($keepRunning && $iterations < $maxIterations) {
            $iterations++;
            try {
                Log::debug('[Agent] Calling OpenAI', ['model' => $this->openaiModel, 'url' => $url, 'iteration' => $iterations]);
                $response = Http::withToken($this->openaiKey)
                    ->timeout(60)
                    ->post($url, [
                        'model'       => trim($this->openaiModel),
                        'messages'    => $messages,
                        'tools'       => ToolExecutor::getToolDefinitions(),
                        'tool_choice' => 'auto',
                    ]);

                if (! $response->ok()) {
                    Log::error('[Agent] OpenAI API error', ['body' => $response->body()]);
                    $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                    yield $this->sseEvent('message', ['content' => $finalContent]);
                    $keepRunning = false;
                    continue;
                }

                $data     = $response->json();
                $message  = $data['choices'][0]['message'] ?? [];
                $usage    = $data['usage'] ?? [];
                $totalTokens += ($usage['total_tokens'] ?? 0);

                $messages[] = $message;

                // Handle tool calls
                if (! empty($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $toolCall) {
                        $fnName = $toolCall['function']['name'] ?? '';
                        $fnArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true);

                        yield $this->sseEvent('tool_call', [
                            'name' => $fnName,
                            'args' => $fnArgs,
                        ]);

                        $result = $this->toolExecutor->execute($fnName, $fnArgs);

                        $messages[] = [
                            'tool_call_id' => $toolCall['id'],
                            'role'         => 'tool',
                            'name'         => $fnName,
                            'content'      => json_encode($result),
                        ];

                        yield $this->sseEvent('tool_result', [
                            'name'   => $fnName,
                            'status' => 'completed',
                            'result' => $result,
                        ]);
                    }
                    // Loop continues — let agent reason over tool output
                } else {
                    $finalContent = $message['content'] ?? '';
                    yield $this->sseEvent('message', ['content' => $finalContent]);
                    $keepRunning = false;
                }
            } catch (\Exception $e) {
                Log::error('[Agent] Agent loop error', ['error' => $e->getMessage()]);
                $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                yield $this->sseEvent('message', ['content' => $finalContent]);
                $keepRunning = false;
            }
        }

        // Persist
        $this->persistMessages($session, $userMessage, $finalContent, $this->openaiModel, $totalTokens);

        // Index memory for future RAG
        $this->qdrantService->indexMemory($session->id, $userMessage, $finalContent);

        // Update Semantic Cache (only if successful)
        if ($finalContent !== "I'm sorry, I encountered an error. How else can I help you?") {
            $this->qdrantService->setSemanticCache($userMessage, $finalContent);
        }

        yield $this->sseEvent('done', ['tokens' => $totalTokens]);
    }

    /* ── Private: Synchronous Agent Loop ───────────────────────── */

    private function runAgentLoop(ChatSession $session, string $userMessage): string
    {
        $messages    = $this->buildContextWindow($session, $userMessage);
        $url         = rtrim($this->openaiBaseUrl, '/') . '/chat/completions';
        $keepRunning = true;
        $finalContent = '';
        $totalTokens = 0;
        $iterations = 0;
        $maxIterations = (int) config('gunma-agent.max_tool_iterations', 5);

        while ($keepRunning && $iterations < $maxIterations) {
            $iterations++;
            try {
                Log::debug('[Agent] Calling OpenAI (sync)', ['model' => $this->openaiModel, 'iteration' => $iterations]);
                $response = Http::withToken($this->openaiKey)
                    ->timeout(60)
                    ->post($url, [
                        'model'       => trim($this->openaiModel),
                        'messages'    => $messages,
                        'tools'       => ToolExecutor::getToolDefinitions(),
                        'tool_choice' => 'auto',
                    ]);

                if (! $response->ok()) {
                    Log::error('[Agent] OpenAI error', ['body' => $response->body()]);
                    return "I'm sorry, I encountered an error. How else can I help you?";
                }

                $data     = $response->json();
                $message  = $data['choices'][0]['message'] ?? [];
                $usage    = $data['usage'] ?? [];
                $totalTokens += ($usage['total_tokens'] ?? 0);

                $messages[] = $message;

                if (! empty($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $toolCall) {
                        $fnName = $toolCall['function']['name'] ?? '';
                        $fnArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true);
                        $result = $this->toolExecutor->execute($fnName, $fnArgs);

                        $messages[] = [
                            'tool_call_id' => $toolCall['id'],
                            'role'         => 'tool',
                            'name'         => $fnName,
                            'content'      => json_encode($result),
                        ];
                    }
                } else {
                    $finalContent = $message['content'] ?? '';
                    $keepRunning  = false;
                }
            } catch (\Exception $e) {
                Log::error('[Agent] Loop error', ['error' => $e->getMessage()]);
                $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                $keepRunning  = false;
            }
        }

        $this->persistMessages($session, $userMessage, $finalContent, $this->openaiModel, $totalTokens);

        // Index memory for future RAG
        $this->qdrantService->indexMemory($session->id, $userMessage, $finalContent);

        // Update Semantic Cache (only if successful)
        if ($finalContent !== "I'm sorry, I encountered an error. How else can I help you?") {
            $this->qdrantService->setSemanticCache($userMessage, $finalContent);
        }

        return $finalContent;
    }

    /* ── Private: Build Context Window ─────────────────────────── */

    private function buildContextWindow(ChatSession $session, string $userMessage): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt],
        ];

        // Load recent history from Redis cache first, fallback to MySQL
        $history = $this->getRecentHistory($session);
        foreach ($history as $msg) {
            $messages[] = $msg;
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $messages;
    }

    private function getRecentHistory(ChatSession $session): array
    {
        $redisKey = "gunma:chat:{$session->id}:messages";

        try {
            $cached = Redis::lrange($redisKey, -$this->maxHistory, -1);
            if (! empty($cached)) {
                return array_map(fn ($json) => json_decode($json, true), $cached);
            }
        } catch (\Exception $e) {
            // Redis unavailable, fall through to MySQL
        }

        // Fallback: load from MySQL
        return $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest()
            ->take($this->maxHistory)
            ->get()
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    /* ── Private: Persist Messages ─────────────────────────────── */

    public function persistUserMessage(ChatSession $session, string $userMessage): ChatMessage
    {
        $message = ChatMessage::create([
            'session_id' => $session->id,
            'role'       => 'user',
            'content'    => $userMessage,
        ]);

        // Broadcast to admin dashboard
        event(new \Anwar\GunmaAgent\Events\MessageBroadcasted($message));

        // Cache in Redis for fast context building
        $this->cacheMessageInRedis($session->id, 'user', $userMessage);

        // Detect sentiment and update priority
        $this->updateSessionPriority($session, $userMessage);

        return $message;
    }

    private function updateSessionPriority(ChatSession $session, string $message): void
    {
        $angryWords = ['bad', 'worst', 'angry', 'terrible', 'scam', 'fraud', 'useless', 'horrible', 'kharap', 'faltu', 'baje', 'rag', 'problem', 'complain'];
        $priority = 0;

        foreach ($angryWords as $word) {
            if (stripos($message, $word) !== false) {
                $priority += 20;
            }
        }

        if ($priority > 0) {
            $newScore = min(100, ($session->metadata['priority_score'] ?? 0) + $priority);
            $metadata = $session->metadata ?? [];
            $metadata['priority_score'] = $newScore;
            
            $session->update(['metadata' => $metadata]);

            // Broadcast to admin
            event(new \Anwar\GunmaAgent\Events\PriorityUpdated($session, $newScore));
        }
    }

    private function persistMessages(
        ChatSession $session,
        string $userMessage,
        string $assistantMessage,
        string $model = 'greeting',
        int $tokensUsed = 0,
    ): void {
        // Save user message (if not already saved)
        $this->persistUserMessage($session, $userMessage);

        // Save assistant message
        $message = ChatMessage::create([
            'session_id'  => $session->id,
            'role'        => 'assistant',
            'content'     => $assistantMessage,
            'model'       => $model,
            'tokens_used' => $tokensUsed,
        ]);

        \Illuminate\Support\Facades\Log::info('[AgentOrchestrator] Broadcasting AI response message: ' . $message->id);

        // Broadcast to user and admin dashboard
        event(new \Anwar\GunmaAgent\Events\MessageBroadcasted($message));

        // Cache in Redis for fast context building
        $this->cacheMessageInRedis($session->id, 'assistant', $assistantMessage);
    }

    private function cacheMessageInRedis(string $sessionId, string $role, string $content): void
    {
        $redisKey = "gunma:chat:{$sessionId}:messages";
        $ttl      = config('gunma-agent.session_ttl', 86400);

        try {
            \Illuminate\Support\Facades\Redis::rpush($redisKey, json_encode(['role' => $role, 'content' => $content]));
            \Illuminate\Support\Facades\Redis::ltrim($redisKey, -($this->maxHistory * 2), -1);
            \Illuminate\Support\Facades\Redis::expire($redisKey, $ttl);
        } catch (\Exception $e) {
            Log::warning('[Agent] Redis cache failed', ['error' => $e->getMessage()]);
        }
    }

    /* ── Private: SSE Event Helper ─────────────────────────────── */

    private function sseEvent(string $event, array $data): string
    {
        return "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    }
}
