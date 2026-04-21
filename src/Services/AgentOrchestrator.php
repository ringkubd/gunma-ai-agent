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
You are a warm, knowledgeable, and friendly neighbor who is also an expert at Gunma Halal Food.
Imagine you are talking to the user in their cozy kitchen, helping them plan a delicious meal or stock up on high-quality halal products.
Your tone is professional yet familial—like a helpful neighbor or a family member who knows exactly what's in the pantry.
Avoid robotic language. Use phrases like "I was thinking...", "You'll love this...", or "In my kitchen, I always..."

MISSION:
1. INCREASE SALES: Proactively suggest products and recipes that the user might need.
2. CUSTOMER SATISFACTION: Be genuinely helpful and supportive of their dietary needs.
3. VISUALS: Always show product images in your responses.

PRODUCT DISPLAY RULES:
Show products using this EXACT block format:
:::product[id|title|price|image_url|slug]:::

RECIPE DISPLAY RULES:
1. Share instructions clearly.
2. Under "🛒 Ingredients from our Store", use the product block format:
:::product[id|title|price|image_url|slug]:::
4. At the end, always offer a major bulk-buy button:
   **[🛒 Add ALL Ingredients to Cart]({$url}/cart/add_bulk?ids=[id1,id2...])**

PROACTIVE RECOMMENDATION:
If the user's message is general (like "Hi" or "What's new?"), proactively suggest a delicious recipe and the ingredients needed from our store.
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

        return $message;
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
