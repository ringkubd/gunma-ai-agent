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
        private readonly PromptService       $promptService,
        private readonly string              $openaiKey,
        private readonly string              $openaiBaseUrl,
        private readonly string              $openaiModel,
        private readonly string              $websiteUrl,
        private readonly int                 $maxHistory,
        private readonly string              $ollamaUrl = 'http://localhost:11434',
        private readonly string              $ollamaChatModel = 'gemma4:latest',
    ) {
        $dbPrompt = $this->promptService->getSystemPrompt();
        $styleInstruction = $this->promptService->getStyleInstruction();
        $url = rtrim($this->websiteUrl, '/');

        $this->systemPrompt = $dbPrompt . "\n\n---\nSTYLE: {$styleInstruction}\n\nWEBSITE: {$url}\n\nUse this format for product recommendations:\n:::product[id|title|price|image_url|slug]:::\nFor recipes, end with:\n**[🛒 Add ALL Ingredients to Cart]({$url}/cart/add_bulk?ids=[id1,id2...])**";
    }

    /* ── Main Entry Point ──────────────────────────────────────── */

    public function chat(ChatSession $session, string $userMessage): string
    {
        // 0. Persist User Message immediately for real-time visibility
        $this->persistUserMessage($session, $userMessage);

        // Check if AI is disabled for this session
        if (!$session->is_ai_enabled) {
            return "Wait for agent..."; 
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
        // 0. Persist User Message immediately for real-time visibility
        $this->persistUserMessage($session, $userMessage);

        // Check if AI is disabled for this session
        if (!$session->is_ai_enabled) {
            yield $this->sseEvent('status', ['message' => 'Waiting for human agent...']);
            yield $this->sseEvent('done', []);
            return;
        }

        // 1. GREETING INTERCEPTOR
        $greeting = $this->greetingInterceptor->intercept($userMessage);
        if ($greeting !== null) {
            $msg = $this->persistMessages($session, $userMessage, $greeting, 'greeting');
            yield $this->sseEvent('message', [
                'id'      => $msg->id,
                'content' => $greeting
            ]);
            yield $this->sseEvent('done', []);
            return;
        }

        // 2. SEMANTIC CACHE
        $cachedResponse = $this->qdrantService->getSemanticCache($userMessage);
        if ($cachedResponse !== null) {
            $msg = $this->persistMessages($session, $userMessage, $cachedResponse, 'semantic_cache');
            yield $this->sseEvent('message', [
                'id'      => $msg->id,
                'content' => $cachedResponse
            ]);
            yield $this->sseEvent('done', []);
            return;
        }

        // 3. KB FAST CHECK
        try {
            $kbResults = $this->qdrantService->searchSupportKB($userMessage);
            if (! empty($kbResults) && ($kbResults[0]['score'] ?? 0) > 0.94) {
                $answer = $kbResults[0]['payload']['answer'] ?? $kbResults[0]['payload']['english']['a'] ?? null;
                if ($answer) {
                    $msg = $this->persistMessages($session, $userMessage, $answer, 'kb_fast');
                    yield $this->sseEvent('message', [
                        'id'      => $msg->id,
                        'content' => $answer
                    ]);
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
        $usedFallback = false;

        while ($keepRunning && $iterations < $maxIterations) {
            $iterations++;
            try {
                if ($usedFallback) {
                    yield $this->sseEvent('tool_call', [
                        'name' => 'ollama_fallback',
                        'args' => ['model' => $this->ollamaChatModel],
                    ]);

                    $reply = $this->callOllama($messages);
                    if ($reply) {
                        $finalContent = $reply;
                        yield $this->sseEvent('message', ['content' => $finalContent]);
                    } else {
                        $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                        yield $this->sseEvent('message', ['content' => $finalContent]);
                    }
                    $keepRunning = false;
                    continue;
                }

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
                    Log::warning('[Agent] OpenAI API error, falling back to Ollama', ['status' => $response->status(), 'body' => $response->body()]);
                    yield $this->sseEvent('status', ['message' => 'OpenAI unavailable, switching to local AI...']);
                    $usedFallback = true;
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

                        // Broadcast to admin dashboard
                        event(new \Anwar\GunmaAgent\Events\ToolExecuting($session->id, "Executing tool: {$fnName}"));

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
                    $keepRunning = false;
                }
            } catch (\Exception $e) {
                Log::error('[Agent] Agent loop error, falling back to Ollama', ['error' => $e->getMessage()]);
                if (! $usedFallback) {
                    yield $this->sseEvent('status', ['message' => 'OpenAI error, switching to local AI...']);
                    $usedFallback = true;
                    continue;
                }
                $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                yield $this->sseEvent('message', ['content' => $finalContent]);
                $keepRunning = false;
            }
        }

        // Persist first to get the real ID
        $savedMessage = $this->persistMessages($session, $userMessage, $finalContent, $this->openaiModel, $totalTokens);

        // Yield final message with the real ID
        yield $this->sseEvent('message', [
            'id'      => $savedMessage->id,
            'content' => $finalContent
        ]);

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
        $usedFallback = false;

        while ($keepRunning && $iterations < $maxIterations) {
            $iterations++;
            try {
                if ($usedFallback) {
                    // Already fell back to Ollama — no tools available, just generate
                    $reply = $this->callOllama($messages);
                    if ($reply) {
                        $finalContent = $reply;
                    } else {
                        $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                    }
                    $keepRunning = false;
                    continue;
                }

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
                    Log::warning('[Agent] OpenAI error, falling back to Ollama', ['status' => $response->status(), 'body' => $response->body()]);
                    $usedFallback = true;
                    continue;
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
                Log::error('[Agent] Loop error, falling back to Ollama', ['error' => $e->getMessage()]);
                if (! $usedFallback) {
                    $usedFallback = true;
                    continue;
                }
                $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                $keepRunning  = false;
            }
        }

        $this->persistMessages($session, $userMessage, $finalContent, $usedFallback ? $this->ollamaChatModel : $this->openaiModel, $totalTokens);

        // Index memory for future RAG
        $this->qdrantService->indexMemory($session->id, $userMessage, $finalContent);

        // Update Semantic Cache (only if successful)
        if ($finalContent !== "I'm sorry, I encountered an error. How else can I help you?") {
            $this->qdrantService->setSemanticCache($userMessage, $finalContent);
        }

        return $finalContent;
    }

    /* ── Private: Ollama Fallback ───────────────────────────────── */

    private function callOllama(array $messages): ?string
    {
        try {
            $url = rtrim($this->ollamaUrl, '/') . '/api/chat';

            $ollamaMessages = [];
            foreach ($messages as $msg) {
                if ($msg['role'] === 'system') {
                    $ollamaMessages[] = ['role' => 'system', 'content' => $msg['content']];
                } elseif (in_array($msg['role'], ['user', 'assistant'])) {
                    $ollamaMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
                }
            }

            $response = Http::timeout(120)
                ->post($url, [
                    'model'    => $this->ollamaChatModel,
                    'messages' => $ollamaMessages,
                    'stream'   => false,
                    'options'  => [
                        'temperature' => 0.7,
                        'num_predict' => 2048,
                    ],
                ]);

            if (! $response->ok()) {
                Log::error('[Agent] Ollama fallback error', ['body' => $response->body()]);
                return null;
            }

            $data = $response->json();
            $content = $data['message']['content'] ?? null;

            if ($content) {
                Log::info('[Agent] Ollama fallback succeeded', ['model' => $this->ollamaChatModel]);
            }

            return $content;
        } catch (\Exception $e) {
            Log::error('[Agent] Ollama fallback exception', ['error' => $e->getMessage()]);
            return null;
        }
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
        // Prevent double persistence in same request life-cycle if needed, 
        // though typically these entry points are called once.
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
    ): ChatMessage {
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

        return $message;
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
