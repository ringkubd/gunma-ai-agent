<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Anwar\GunmaAgent\Models\ChatMessage;
use Anwar\GunmaAgent\Models\ChatSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class AgentOrchestrator
{
    private string $baseSystemPrompt;

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
        private readonly string              $ollamaChatModel = 'gunma-halal-ai:latest',
    ) {
        $dbPrompt = $this->promptService->getSystemPrompt();
        $styleInstruction = $this->promptService->getStyleInstruction();
        $url = rtrim($this->websiteUrl, '/');

        $this->baseSystemPrompt = $dbPrompt . "\n\n---\nSTYLE: {$styleInstruction}\n\nWEBSITE: {$url}";
    }

    /* ── Build context-aware system prompt with user context ───── */

    private function buildSystemPrompt(ChatSession $session): string
    {
        $ctx = $this->getUserContext($session);
        $parts = [$this->baseSystemPrompt];

        // Time/season context
        $triggers = app(\Anwar\GunmaAgent\Services\ProactiveTriggerService::class)->getTriggers($ctx['insight']);
        $nowLines = [];
        $nowLines[] = "## NOW";
        $nowLines[] = "- Time: " . date('l, H:i');
        $nowLines[] = "- Period: " . $triggers['time_period'];
        $nowLines[] = "- Season: " . $triggers['season'];
        if (!empty($triggers['seasonal_suggestions'])) $nowLines[] = "- Seasonal items in demand: " . implode(', ', array_slice($triggers['seasonal_suggestions'], 0, 5));
        if ($triggers['ramadan_coming']) $nowLines[] = "- RAMADAN COMING: Be proactive about dates, semai, chola, haleem ingredients.";
        if ($triggers['eid_coming']) $nowLines[] = "- EID COMING: Suggest premium cuts, sweets, cooking essentials.";
        $parts[] = implode("\n", $nowLines);

        // User context
        if ($ctx) {
            $lines = [];
            $lines[] = "## CURRENT USER CONTEXT";
            if ($ctx['name']) $lines[] = "- Name: {$ctx['name']}";
            if ($ctx['email']) $lines[] = "- Email: {$ctx['email']}";
            if ($ctx['is_guest'] === false) $lines[] = "- Logged in: yes";
            else $lines[] = "- Logged in: no (guest)";
            if ($ctx['previous_orders'] > 0) $lines[] = "- Previous orders: {$ctx['previous_orders']}";
            if ($ctx['points'] > 0) $lines[] = "- Loyalty points: {$ctx['points']}";
            if ($ctx['cart_count'] > 0) $lines[] = "- Items in cart: {$ctx['cart_count']} (check cart before suggesting products)";

            // Purchase insight
            $insight = $ctx['insight'];
            if ($insight && ($insight['total_orders'] ?? 0) > 0) {
                $lines[] = "- Avg order value: ¥" . number_format($insight['avg_order_value'] ?? 0, 2);
                $lines[] = "- Days since last order: {$insight['days_since_last']}";
                if (!empty($insight['top_categories'])) $lines[] = "- Favorite categories: " . implode(', ', $insight['top_categories']);
                if (!empty($insight['frequent_items'])) {
                    $lines[] = "- Frequently buys:";
                    foreach (array_slice($insight['frequent_items'], 0, 5) as $item) {
                        $lines[] = "  * {$item['title']} ({$item['purchase_count']}x, last {$item['days_since']}d ago)";
                    }
                }
                if (!empty($insight['suggested_reorders'])) {
                    $lines[] = "- Likely needs to reorder:";
                    foreach (array_slice($insight['suggested_reorders'], 0, 3) as $item) {
                        $lines[] = "  * {$item['title']} ({$item['days_since']}d since last — suggest restock)";
                    }
                }
            }
            $parts[] = implode("\n", $lines);
        }

        $parts[] = "## PRODUCT FORMAT\nWhen listing products, use a simple text list like:\n1. Product Name - ¥Price (Stock: X)\n2. Product Name - ¥Price (Stock: X)\n\nFor recipe suggestions, end with:\n**[🛒 Add ALL Ingredients to Cart]({$this->websiteUrl}/cart/add_bulk?ids=[id1,id2...])**\n\nIMPORTANT: Do NOT use product card format (:::product[...]:::) — use plain text lists only.";

        return implode("\n\n", $parts);
    }

    private function getUserContext(ChatSession $session): array
    {
        $ctx = [
            'name' => $session->resolved_name,
            'email' => $session->resolved_email,
            'is_guest' => true,
            'previous_orders' => 0,
            'points' => 0,
            'cart_count' => 0,
            'insight' => null,
        ];

        if (!$session->customer_id) return $ctx;

        $ctx['is_guest'] = false;

        try {
            $customerModel = config('gunma-agent.models.customer');
            if ($customerModel && class_exists($customerModel)) {
                $customer = $customerModel::find($session->customer_id);
                if ($customer) {
                    $ctx['name'] = $ctx['name'] ?? $customer->name;
                    $ctx['email'] = $ctx['email'] ?? $customer->email;
                    $ctx['points'] = (int) ($customer->available_point ?? 0);

                    $orderModel = config('gunma-agent.models.order');
                    if ($orderModel && class_exists($orderModel)) {
                        $ctx['previous_orders'] = $orderModel::where('customer_id', $customer->id)->count();
                    }

                    $cartModel = config('gunma-agent.models.cart');
                    if ($cartModel && class_exists($cartModel)) {
                        $ctx['cart_count'] = $cartModel::where('customer_id', $customer->id)->count();
                    }

                    // Purchase insight
                    $insightService = app(\Anwar\GunmaAgent\Services\CustomerInsightService::class);
                    $ctx['insight'] = $insightService->analyzeCustomer($customer->id);
                }
            }
        } catch (\Exception $e) {
            Log::warning('[Agent] User context fetch failed', ['error' => $e->getMessage()]);
        }

        return $ctx;
    }

    /* ── Main Entry Point ──────────────────────────────────────── */

    public function chat(ChatSession $session, string $userMessage): string
    {
        $this->persistUserMessage($session, $userMessage);

        if (!$session->is_ai_enabled) return "Wait for agent...";

        // 1. Smart greeting interceptor (with user context)
        $ctx = $this->getUserContext($session);
        $greeting = $this->greetingInterceptor->intercept($userMessage, $ctx);
        if ($greeting !== null) {
            Log::info('[Agent] Greeting shortcut', ['query' => $userMessage]);
            $this->persistMessages($session, $userMessage, $greeting, 'greeting');
            return $greeting;
        }

        // 2. Semantic cache
        $cachedResponse = $this->qdrantService->getSemanticCache($userMessage);
        if ($cachedResponse !== null) {
            Log::info('[Agent] Semantic cache hit');
            $this->persistMessages($session, $userMessage, $cachedResponse, 'semantic_cache');
            return $cachedResponse;
        }

        // 3. KB fast check
        try {
            $kbResults = $this->qdrantService->searchSupportKB($userMessage);
            if (!empty($kbResults) && ($kbResults[0]['score'] ?? 0) > 0.94) {
                $answer = $kbResults[0]['payload']['answer'] ?? $kbResults[0]['payload']['english']['a'] ?? null;
                if ($answer) {
                    Log::info('[Agent] KB fast reply', ['score' => $kbResults[0]['score']]);
                    $this->persistMessages($session, $userMessage, $answer, 'kb_fast');
                    return $answer;
                }
            }
        } catch (\Exception $e) {
            Log::warning('[Agent] KB fast check failed', ['error' => $e->getMessage()]);
        }

        // 4. Memory retrieval: find similar past Q&A to improve this response
        try {
            $similarMemories = $this->qdrantService->searchMemories($userMessage);
            if (!empty($similarMemories)) {
                Log::info('[Agent] Found ' . count($similarMemories) . ' similar past conversations');
                // Memories are injected into context by buildContextWindow below
            }
        } catch (\Exception $e) {
            Log::warning('[Agent] Memory retrieval failed', ['error' => $e->getMessage()]);
        }

        // 5. Full agent loop
        $result = $this->runAgentLoop($session, $userMessage);

        // Quality check: if response is empty, retry once
        if (empty(trim($result)) || strlen(trim($result)) < 5) {
            Log::warning('[Agent] Empty response detected, retrying');
            $result = $this->runAgentLoop($session, $userMessage);
        }

        return $result;
    }

    public function chatStream(ChatSession $session, string $userMessage): \Generator
    {
        $this->persistUserMessage($session, $userMessage);

        if (!$session->is_ai_enabled) {
            yield $this->sseEvent('status', ['message' => 'Waiting for human agent...']);
            yield $this->sseEvent('done', []);
            return;
        }

        // 1. Smart greeting
        $ctx = $this->getUserContext($session);
        $greeting = $this->greetingInterceptor->intercept($userMessage, $ctx);
        if ($greeting !== null) {
            $msg = $this->persistMessages($session, $userMessage, $greeting, 'greeting');
            yield $this->sseEvent('message', ['id' => $msg->id, 'content' => $greeting]);
            yield $this->sseEvent('done', []);
            return;
        }

        // 2. Semantic cache
        $cachedResponse = $this->qdrantService->getSemanticCache($userMessage);
        if ($cachedResponse !== null) {
            $msg = $this->persistMessages($session, $userMessage, $cachedResponse, 'semantic_cache');
            yield $this->sseEvent('message', ['id' => $msg->id, 'content' => $cachedResponse]);
            yield $this->sseEvent('done', []);
            return;
        }

        // 3. KB fast check
        try {
            $kbResults = $this->qdrantService->searchSupportKB($userMessage);
            if (!empty($kbResults) && ($kbResults[0]['score'] ?? 0) > 0.94) {
                $answer = $kbResults[0]['payload']['answer'] ?? $kbResults[0]['payload']['english']['a'] ?? null;
                if ($answer) {
                    $msg = $this->persistMessages($session, $userMessage, $answer, 'kb_fast');
                    yield $this->sseEvent('message', ['id' => $msg->id, 'content' => $answer]);
                    yield $this->sseEvent('done', []);
                    return;
                }
            }
        } catch (\Exception $e) {
            Log::warning('[Agent] KB fast check failed', ['error' => $e->getMessage()]);
        }

        // 4. Full agent loop
        yield from $this->runAgentLoopStream($session, $userMessage);
    }

    /* ── Sync Agent Loop ───────────────────────────────────────── */

    private function runAgentLoop(ChatSession $session, string $userMessage): string
    {
        $messages = $this->buildContextWindow($session, $userMessage);
        $url = rtrim($this->openaiBaseUrl, '/') . '/chat/completions';
        $finalContent = '';
        $totalTokens = 0;
        $iterations = 0;
        $maxIterations = (int) config('gunma-agent.max_tool_iterations', 5);
        $usedFallback = false;

        while ($iterations < $maxIterations) {
            $iterations++;
            try {
                if ($usedFallback) {
                    $reply = $this->callOllama($messages);
                    $finalContent = $reply ?? "I'm sorry, I couldn't process that. Would you like to speak with a human agent?";
                    break;
                }

                $response = Http::withToken($this->openaiKey)
                    ->timeout(60)
                    ->post($url, [
                        'model'       => trim($this->openaiModel),
                        'messages'    => $messages,
                        'tools'       => ToolExecutor::getToolDefinitions(),
                        'tool_choice' => 'auto',
                    ]);

                if (!$response->ok()) {
                    Log::warning('[Agent] OpenAI error, fallback to Ollama', ['status' => $response->status()]);
                    $usedFallback = true;
                    continue;
                }

                $data = $response->json();
                $message = $data['choices'][0]['message'] ?? [];
                $totalTokens += ($data['usage']['total_tokens'] ?? 0);
                $messages[] = $message;

                if (!empty($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $toolCall) {
                        $fnName = $toolCall['function']['name'] ?? '';
                        $fnArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true);
                        $result = $this->toolExecutor->execute($fnName, $fnArgs);

                        $messages[] = [
                            'tool_call_id' => $toolCall['id'],
                            'role' => 'tool',
                            'name' => $fnName,
                            'content' => json_encode($result),
                        ];
                    }
                } else {
                    $finalContent = $message['content'] ?? '';
                    break;
                }
            } catch (\Exception $e) {
                Log::error('[Agent] Loop error, fallback to Ollama', ['error' => $e->getMessage()]);
                if (!$usedFallback) { $usedFallback = true; continue; }
                $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                break;
            }
        }

        $this->persistMessages($session, $userMessage, $finalContent, $usedFallback ? $this->ollamaChatModel : $this->openaiModel, $totalTokens);
        $this->qdrantService->indexMemory($session->id, $userMessage, $finalContent);
        $this->storeConversationSummary($session, $userMessage, $finalContent);
        if ($finalContent !== "I'm sorry, I encountered an error. How else can I help you?") {
            $this->qdrantService->setSemanticCache($userMessage, $finalContent);
        }

        return $finalContent;
    }

    /* ── Stream Agent Loop ─────────────────────────────────────── */

    private function runAgentLoopStream(ChatSession $session, string $userMessage): \Generator
    {
        $messages = $this->buildContextWindow($session, $userMessage);
        $url = rtrim($this->openaiBaseUrl, '/') . '/chat/completions';

        yield $this->sseEvent('thinking', ['status' => 'Processing your request...']);

        $finalContent = '';
        $totalTokens = 0;
        $iterations = 0;
        $maxIterations = (int) config('gunma-agent.max_tool_iterations', 5);
        $usedFallback = false;

        while ($iterations < $maxIterations) {
            $iterations++;
            try {
                if ($usedFallback) {
                    yield $this->sseEvent('tool_call', ['name' => 'ollama_fallback', 'args' => ['model' => $this->ollamaChatModel]]);
                    $reply = $this->callOllama($messages);
                    $finalContent = $reply ?? "I'm sorry, I couldn't process that. Would you like to speak with a human agent?";
                    yield $this->sseEvent('message', ['content' => $finalContent]);
                    break;
                }

                $response = Http::withToken($this->openaiKey)
                    ->timeout(60)
                    ->post($url, [
                        'model'       => trim($this->openaiModel),
                        'messages'    => $messages,
                        'tools'       => ToolExecutor::getToolDefinitions(),
                        'tool_choice' => 'auto',
                    ]);

                if (!$response->ok()) {
                    yield $this->sseEvent('status', ['message' => 'OpenAI unavailable, switching to local AI...']);
                    $usedFallback = true;
                    continue;
                }

                $data = $response->json();
                $message = $data['choices'][0]['message'] ?? [];
                $totalTokens += ($data['usage']['total_tokens'] ?? 0);
                $messages[] = $message;

                if (!empty($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $toolCall) {
                        $fnName = $toolCall['function']['name'] ?? '';
                        $fnArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true);

                        yield $this->sseEvent('tool_call', ['name' => $fnName, 'args' => $fnArgs]);
                        event(new \Anwar\GunmaAgent\Events\ToolExecuting($session->id, "Executing tool: {$fnName}"));

                        $result = $this->toolExecutor->execute($fnName, $fnArgs);

                        $messages[] = [
                            'tool_call_id' => $toolCall['id'],
                            'role' => 'tool',
                            'name' => $fnName,
                            'content' => json_encode($result),
                        ];

                        yield $this->sseEvent('tool_result', ['name' => $fnName, 'status' => 'completed', 'result' => $result]);
                    }
                } else {
                    $finalContent = $message['content'] ?? '';
                    break;
                }
            } catch (\Exception $e) {
                Log::error('[Agent] Stream loop error', ['error' => $e->getMessage()]);
                if (!$usedFallback) {
                    yield $this->sseEvent('status', ['message' => 'OpenAI error, switching to local AI...']);
                    $usedFallback = true;
                    continue;
                }
                $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                yield $this->sseEvent('message', ['content' => $finalContent]);
                break;
            }
        }

        if (empty(trim($finalContent)) || strlen(trim($finalContent)) < 5) {
            $finalContent = 'I apologize, but I wasn\'t able to generate a proper response. Could you rephrase your question or would you like me to connect you with a human agent?';
        }

        $savedMessage = $this->persistMessages($session, $userMessage, $finalContent, $usedFallback ? $this->ollamaChatModel : $this->openaiModel, $totalTokens);
        yield $this->sseEvent('message', ['id' => $savedMessage->id, 'content' => $finalContent]);

        $this->qdrantService->indexMemory($session->id, $userMessage, $finalContent);
        $this->storeConversationSummary($session, $userMessage, $finalContent);
        if ($finalContent !== "I'm sorry, I encountered an error. How else can I help you?") {
            $this->qdrantService->setSemanticCache($userMessage, $finalContent);
        }

        yield $this->sseEvent('done', ['tokens' => $totalTokens]);
    }

    /* ── Ollama Fallback ───────────────────────────────────────── */

    private function callOllama(array $messages): ?string
    {
        try {
            $url = rtrim($this->ollamaUrl, '/') . '/api/chat';
            $ollamaMessages = [];
            foreach ($messages as $msg) {
                $role = $msg['role'] ?? '';
                if (in_array($role, ['system', 'user', 'assistant'])) {
                    $ollamaMessages[] = ['role' => $role, 'content' => $msg['content'] ?? ''];
                }
            }

            $response = Http::timeout(120)->post($url, [
                'model' => $this->ollamaChatModel,
                'messages' => $ollamaMessages,
                'stream' => false,
                'options' => ['temperature' => 0.7, 'num_predict' => 1024],
            ]);

            if (!$response->ok()) {
                Log::error('[Agent] Ollama fallback error', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();
            $message = $data['message'] ?? [];
            $content = $message['content'] ?? '';

            if (empty($content) && !empty($message['thinking'])) {
                $content = $message['thinking'];
            }

            return $content ?: null;
        } catch (\Exception $e) {
            Log::error('[Agent] Ollama fallback exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /* ── Inject similar past conversations as context ─────────── */

    private function injectMemoryContext(ChatSession $session, string $userMessage, array &$messages): void
    {
        try {
            // Search conversation memories
            $memories = $this->qdrantService->searchMemories($userMessage, 3);
            if (!empty($memories)) {
                $lines = ["\n## SIMILAR PAST CONVERSATIONS (for reference)"];
                foreach ($memories as $m) {
                    $q = $m['query'] ?? '';
                    $a = $m['answer'] ?? '';
                    if ($q && $a) {
                        $a = mb_strlen($a) > 300 ? mb_substr($a, 0, 300) . '...' : $a;
                        $lines[] = "- Q: {$q}";
                        $lines[] = "  A: {$a}";
                    }
                }
                $messages[] = ['role' => 'system', 'content' => implode("\n", $lines)];
            }

            // Add previous session summary if available
            if ($session->customer_id) {
                $lastSummary = DB::table('conversation_summaries')
                    ->where('customer_id', $session->customer_id)
                    ->latest()
                    ->first();
                if ($lastSummary && !empty($lastSummary->summary)) {
                    $topics = json_decode($lastSummary->key_topics ?? '[]', true);
                    $extra = '';
                    if (!empty($topics)) $extra = "\nTopics: " . implode(', ', $topics);
                    if ($lastSummary->follow_up_needed) $extra .= "\nFOLLOW-UP NEEDED: Customer had an unresolved issue — ask if it was resolved.";
                    $messages[] = ['role' => 'system', 'content' => "## LAST CONVERSATION ({$lastSummary->sentiment} mood)\n{$lastSummary->summary}{$extra}"];
                }
            }
        } catch (\Exception $e) {
            Log::warning('[Agent] Memory injection failed', ['error' => $e->getMessage()]);
        }
    }

    /* ── Build Context Window ──────────────────────────────────── */

    private function buildContextWindow(ChatSession $session, string $userMessage): array
    {
        $systemPrompt = $this->buildSystemPrompt($session);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Inject similar past conversations before history
        $this->injectMemoryContext($session, $userMessage, $messages);

        $history = $this->getRecentHistory($session);

        // Summarize old context if conversation is long
        if (count($history) > ($this->maxHistory * 2)) {
            $recentCount = $this->maxHistory;
            $oldMessages = array_slice($history, 0, count($history) - $recentCount);
            $recentMessages = array_slice($history, -$recentCount);

            $summary = $this->summarizeContext($oldMessages);
            if ($summary) {
                $messages[] = ['role' => 'system', 'content' => "[Earlier conversation summary]: {$summary}"];
            }
            $messages = array_merge($messages, $recentMessages);
        } else {
            $messages = array_merge($messages, $history);
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $messages;
    }

    private function summarizeContext(array $messages): string
    {
        $topics = [];
        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            if (strlen($content) > 150) {
                $content = substr($content, 0, 150) . '...';
            }
            $topics[] = "{$msg['role']}: {$content}";
        }
        return implode(' | ', array_slice($topics, -6));
    }

    private function getRecentHistory(ChatSession $session): array
    {
        $redisKey = "gunma:chat:{$session->id}:messages";

        try {
            $cached = Redis::lrange($redisKey, -$this->maxHistory, -1);
            if (!empty($cached)) {
                return array_map(fn($json) => json_decode($json, true), $cached);
            }
        } catch (\Exception $e) {
            // Redis unavailable, fallback to MySQL
        }

        return $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest()
            ->take($this->maxHistory)
            ->get()
            ->reverse()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    /* ── Store Conversation Summary ────────────────────────────── */

    private function storeConversationSummary(ChatSession $session, string $userMessage, string $assistantMessage): void
    {
        try {
            $q = mb_substr($userMessage, 0, 200);
            $a = mb_substr($assistantMessage, 0, 300);
            $summary = "Q: {$q} → A: {$a}";

            // Simple keyword extraction for topics
            $keywords = $this->extractKeywords($userMessage . ' ' . $assistantMessage);
            $sentiment = $this->detectSentiment($userMessage);

            DB::table('conversation_summaries')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'session_id' => $session->id,
                'customer_id' => $session->customer_id,
                'summary' => $summary,
                'key_topics' => json_encode(array_slice($keywords, 0, 5)),
                'sentiment' => $sentiment,
                'follow_up_needed' => $sentiment === 'negative' || str_contains(strtolower($assistantMessage), 'team will'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('[Agent] Summary storage failed', ['error' => $e->getMessage()]);
        }
    }

    private function extractKeywords(string $text): array
    {
        $topicWords = [
            'order', 'delivery', 'tracking', 'payment', 'refund', 'cancel',
            'rice', 'oil', 'meat', 'chicken', 'beef', 'fish', 'vegetable',
            'spice', 'lentil', 'dal', 'masala', 'biryani', 'curry', 'halal',
            'price', 'discount', 'coupon', 'promotion', 'cart', 'checkout',
            'address', 'post code', 'ramadan', 'eid', 'iftaar', 'complaint',
            'recipe', 'cooking', 'bazar', 'shopping', 'restock', 'monthly',
            'stock', 'available', 'missing', 'damaged', 'wrong', 'quality',
        ];
        $found = [];
        $lower = strtolower($text);
        foreach ($topicWords as $word) {
            if (str_contains($lower, $word)) {
                $found[] = $word;
            }
        }
        return $found;
    }

    private function detectSentiment(string $text): string
    {
        $positive = ['thanks', 'thank', 'great', 'good', 'excellent', 'love', 'wonderful', 'helpful', 'best'];
        $negative = ['bad', 'worst', 'angry', 'terrible', 'scam', 'fraud', 'useless', 'horrible', 'kharap', 'problem', 'complain', 'never', 'waste', 'disappointed'];

        $lower = strtolower($text);
        $posScore = 0;
        $negScore = 0;
        foreach ($positive as $w) if (str_contains($lower, $w)) $posScore++;
        foreach ($negative as $w) if (str_contains($lower, $w)) $negScore++;

        if ($negScore > $posScore) return 'negative';
        if ($posScore > 0) return 'positive';
        return 'neutral';
    }

    /* ── Persist Messages ──────────────────────────────────────── */

    public function persistUserMessage(ChatSession $session, string $userMessage): ChatMessage
    {
        $message = ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        event(new \Anwar\GunmaAgent\Events\MessageBroadcasted($message));
        $this->cacheMessageInRedis($session->id, 'user', $userMessage);
        $this->updateSessionPriority($session, $userMessage);

        return $message;
    }

    private function updateSessionPriority(ChatSession $session, string $message): void
    {
        $angryWords = ['bad', 'worst', 'angry', 'terrible', 'scam', 'fraud', 'useless', 'horrible', 'kharap', 'faltu', 'baje', 'rag', 'problem', 'complain'];
        $priority = 0;
        foreach ($angryWords as $word) {
            if (stripos($message, $word) !== false) $priority += 20;
        }

        if ($priority > 0) {
            $newScore = min(100, ($session->metadata['priority_score'] ?? 0) + $priority);
            $metadata = $session->metadata ?? [];
            $metadata['priority_score'] = $newScore;
            $session->update(['metadata' => $metadata]);
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
        $message = ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $assistantMessage,
            'model' => $model,
            'tokens_used' => $tokensUsed,
        ]);

        event(new \Anwar\GunmaAgent\Events\MessageBroadcasted($message));
        $this->cacheMessageInRedis($session->id, 'assistant', $assistantMessage);

        return $message;
    }

    private function cacheMessageInRedis(string $sessionId, string $role, string $content): void
    {
        $redisKey = "gunma:chat:{$sessionId}:messages";
        $ttl = config('gunma-agent.session_ttl', 86400);

        try {
            Redis::rpush($redisKey, json_encode(['role' => $role, 'content' => $content]));
            Redis::ltrim($redisKey, -($this->maxHistory * 2), -1);
            Redis::expire($redisKey, $ttl);
        } catch (\Exception $e) {
            Log::warning('[Agent] Redis cache failed', ['error' => $e->getMessage()]);
        }
    }

    private function sseEvent(string $event, array $data): string
    {
        return "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    }
}
