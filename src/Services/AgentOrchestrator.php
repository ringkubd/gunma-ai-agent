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
        private readonly AgentSettingsService $settingsService,
        private readonly string              $websiteUrl,
        private readonly int                 $maxHistory,
    ) {
        $dbPrompt = $this->promptService->getSystemPrompt();
        $styleInstruction = $this->promptService->getStyleInstruction();
        $url = rtrim($this->websiteUrl, '/');

        $this->baseSystemPrompt = $dbPrompt . "\n\n---\nSTYLE: {$styleInstruction}\n\nWEBSITE: {$url}";
    }

    /* ── Active LLM configuration (runtime-switchable) ─────────── */

    private function llm(): array
    {
        return [
            'base_url' => rtrim((string) $this->settingsService->get('llm_base_url', config('gunma-agent.llm.base_url')), '/'),
            'api_key'  => (string) $this->settingsService->get('llm_api_key', config('gunma-agent.llm.api_key')),
            'model'    => (string) $this->settingsService->get('llm_model', config('gunma-agent.llm.model')),
        ];
    }

    private function fallback(): ?array
    {
        if (! $this->settingsService->bool('llm_fallback_enabled', (bool) config('gunma-agent.llm.fallback_enabled', true))) {
            return null;
        }

        $baseUrl = (string) $this->settingsService->get('llm_fallback_base_url', config('gunma-agent.llm.fallback_base_url'));
        $model   = (string) $this->settingsService->get('llm_fallback_model', config('gunma-agent.llm.fallback_model'));

        if ($baseUrl === '' || $model === '') {
            return null;
        }

        return [
            'base_url' => rtrim($baseUrl, '/'),
            'api_key'  => (string) $this->settingsService->get('llm_fallback_api_key', config('gunma-agent.llm.fallback_api_key')),
            'model'    => $model,
        ];
    }

    /**
     * Vision (multimodal) model config. Used when a message contains an image.
     * Defaults to the main LLM (DeepSeek v4.1 flash supports vision + tools).
     */
    private function vision(): ?array
    {
        if (! config('gunma-agent.vision.enabled', true)) {
            return null;
        }

        $baseUrl = (string) config('gunma-agent.vision.base_url', config('gunma-agent.llm.base_url'));
        $model   = (string) config('gunma-agent.vision.model', '');
        $apiKey  = (string) config('gunma-agent.vision.api_key', config('gunma-agent.llm.api_key'));

        // If no dedicated vision model is set, fall back to the main LLM.
        if ($model === '') {
            return $this->llm();
        }

        if ($baseUrl === '') {
            return null;
        }

        return [
            'base_url' => rtrim($baseUrl, '/'),
            'api_key'  => $apiKey,
            'model'    => $model,
        ];
    }

    /**
     * Tools that perform a UI action in the widget (open a panel). Their output
     * must never be served/stored from the semantic cache, otherwise the action
     * is skipped on repeat queries.
     */
    private const ACTION_TOOLS = ['open_checkout', 'open_login', 'add_item_to_cart', 'bulk_add_to_cart'];

    /**
     * Does the message look like an action request (checkout/order/pay/login/
     * add-to-cart)? These must always run the agent loop so the corresponding
     * tool fires — never a cached text reply.
     */
    private function isActionIntent(string $message): bool
    {
        $m = mb_strtolower(trim($message));
        if ($m === '') {
            return false;
        }

        $patterns = [
            // Checkout / order / pay
            '/\b(checkout|check[\s-]?out|order\s*(koro|korte|korbo|place|now)|place\s*order|kacchi|order\s*kor)\b/u',
            '/\b(pay|payment|stripe|card\s*payment|pay\s*kor)\b/u',
            '/চেকআউট|অর্ডার\s*(কর|কোর)|পেমেন্ট|টাকা\s*দি/u',
            // Login / register
            '/\b(log[\s-]?in|sign[\s-]?in|login|register|sign[\s-]?up|account\s*(khol|koro|create|bana)\w*)\b/u',
            '/লগইন|সাইন\s*ইন|রেজিস্টার|অ্যাকাউন্ট/u',
            // Add to cart
            '/\b(add\s*(to\s*)?cart|cart\s*e\s*(add|dao)|cart\s*(koro|kor)|add\s*kor)/u',
            '/কার্টে\s*(add|যোগ|দাও)/u',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $m)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract image URLs from a message (supports markdown ![](url) and the
     * widget's [IMAGE: url] marker).
     *
     * @return string[]
     */
    private function extractImageUrls(string $message): array
    {
        $urls = [];
        if (preg_match_all('/\[IMAGE:\s*(https?:\/\/[^\]]+)\]/i', $message, $m)) {
            $urls = array_merge($urls, $m[1]);
        }
        if (preg_match_all('/!\[[^\]]*\]\((https?:\/\/[^)]+)\)/i', $message, $m)) {
            $urls = array_merge($urls, $m[1]);
        }
        if (preg_match_all('/https?:\/\/[^\s"\']+\.(?:jpg|jpeg|png|gif|webp)/i', $message, $m)) {
            $urls = array_merge($urls, $m[0]);
        }
        return array_values(array_unique($urls));
    }

    /**
     * Build multimodal content parts from text + image URLs (OpenAI format).
     * Ollama Cloud does not accept image URLs, so images are inlined as base64
     * data URIs (read from local storage or fetched over HTTP).
     */
    private function withImages(string $text, array $imageUrls): array
    {
        $parts = [['type' => 'text', 'text' => $text]];
        foreach ($imageUrls as $url) {
            $dataUri = $this->toDataUri($url);
            if ($dataUri) {
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUri]];
            }
        }
        return $parts;
    }

    /**
     * Convert an image URL/path to a base64 data URI, or null on failure.
     */
    private function toDataUri(string $url): ?string
    {
        // Already a data URI.
        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        try {
            $binary = null;
            $mime = 'image/jpeg';

            // 1) Local file for our own uploads (/storage/... or /chat_uploads/...).
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $localCandidates = [];
            if (str_contains($path, '/storage/')) {
                $rel = explode('/storage/', $path, 2)[1];
                $localCandidates[] = storage_path('app/public/' . $rel);
                $localCandidates[] = public_path('storage/' . $rel);
            }
            if (str_contains($path, '/chat_uploads/')) {
                $rel = explode('/chat_uploads/', $path, 2)[1];
                $localCandidates[] = public_path('chat_uploads/' . $rel);
            }
            foreach ($localCandidates as $c) {
                if (is_file($c)) {
                    $binary = @file_get_contents($c);
                    $mime = @mime_content_type($c) ?: $mime;
                    break;
                }
            }

            // 2) Fallback: fetch over HTTP (only for public URLs).
            if ($binary === null && preg_match('#^https?://#i', $url)) {
                $resp = Http::timeout(15)->get($url);
                if ($resp->ok()) {
                    $binary = $resp->body();
                    $mime = $resp->header('Content-Type') ?: $mime;
                }
            }

            if ($binary === null || $binary === '') {
                Log::warning('[Agent] Could not inline image for vision', ['url' => $url]);
                return null;
            }

            // Cap ~8MB to stay within provider limits.
            if (strlen($binary) > 8 * 1024 * 1024) {
                Log::warning('[Agent] Image too large for vision', ['url' => $url, 'bytes' => strlen($binary)]);
                return null;
            }

            return 'data:' . $mime . ';base64,' . base64_encode($binary);
        } catch (\Exception $e) {
            Log::warning('[Agent] Image inline failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * POST an OpenAI-compatible chat completion request. Returns null on failure.
     * When the response is a thinking model (content empty but reasoning present),
     * the reasoning text is promoted to content so callers always have text.
     */
    private function chatCompletion(array $cfg, array $messages): ?array
    {
        $limiter = app(\Anwar\GunmaAgent\Services\ConcurrencyLimiter::class);

        try {
            $response = $limiter->run(function () use ($cfg, $messages) {
                $request = Http::timeout(120)->acceptJson();
                if (($cfg['api_key'] ?? '') !== '') {
                    $request = $request->withToken($cfg['api_key']);
                }

                return $request->post($cfg['base_url'] . '/chat/completions', [
                    'model'       => trim($cfg['model']),
                    'messages'    => $messages,
                    'tools'       => ToolExecutor::getToolDefinitions(),
                    'tool_choice' => 'auto',
                ]);
            });
        } catch (\Exception $e) {
            Log::warning('[Agent] LLM request exception', ['error' => $e->getMessage()]);
            return null;
        }

        if (! $response->ok()) {
            Log::warning('[Agent] LLM error response', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
            ]);
            return null;
        }

        $data = $response->json();

        // Thinking models (e.g. DeepSeek) may leave content empty on truncation
        // and put the answer in `reasoning`. Promote it so the turn isn't blank.
        $choice = $data['choices'][0] ?? null;
        if (is_array($choice)) {
            $msg = $choice['message'] ?? [];
            $hasToolCalls = !empty($msg['tool_calls']);
            $content = trim((string) ($msg['content'] ?? ''));
            if (! $hasToolCalls && $content === '' && !empty($msg['reasoning'])) {
                $data['choices'][0]['message']['content'] = trim((string) $msg['reasoning']);
            }
        }

        return $data;
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

        // Weather (best-effort, cached) for weather-aware suggestions.
        try {
            $weather = app(\Anwar\GunmaAgent\Services\WeatherService::class)
                ->forLocation($ctx['prefecture'] ?? null);
            if (!empty($weather)) {
                $parts[] = "## WEATHER NOW\n- Location: " . ($ctx['prefecture'] ?? 'Japan')
                    . "\n- Current: {$weather['summary']}"
                    . "\n- Use this for weather-aware food suggestions (e.g. khichuri on a rainy/cold day, cold drinks on a hot day).";
            }
        } catch (\Exception $e) {
            // ignore
        }

        // User context
        if ($ctx) {
            $lines = [];
            $lines[] = "## CURRENT USER CONTEXT";
            if ($ctx['name']) $lines[] = "- Name: {$ctx['name']}";
            if ($ctx['email']) $lines[] = "- Email: {$ctx['email']}";
            if (!empty($ctx['language'])) {
                $script = $ctx['language_script'] ? " {$ctx['language_script']}" : '';
                $rtlNote = !empty($ctx['language_rtl']) ? ' (right-to-left)' : '';
                $lines[] = "- Preferred language: {$ctx['language']} ({$ctx['language_code']}){$rtlNote} — reply in this language{$script}";
            }
            if (!empty($ctx['prefecture'])) $lines[] = "- Location/prefecture: {$ctx['prefecture']}";
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

        $parts[] = "## PRODUCT FORMAT\nWhen listing products, use numbered list with clickable product name links:\n1. [Product Name]({$this->websiteUrl}/slug) - ¥Price\n2. [Product Name]({$this->websiteUrl}/slug) - ¥Price\n\nAfter every product list, ALWAYS add:\nJust reply with the number to add to cart, say **add all** for everything, or I can add items for you!\n\nFor recipe ideas, end with:\n**[🛒 Add ALL Ingredients to Cart]({$this->websiteUrl}/cart/add_bulk?ids=[id1,id2...])**\n\nIMPORTANT: Never show stock quantity unless the user specifically asks.";

        $parts[] = $this->conversationGuidance($ctx);

        return implode("\n\n", $parts);
    }

    /**
     * Built-in conversational guidance: shopkeeper-narrative style, step-by-step
     * ordering, image handling, and automatic tool use. Applied on top of the
     * editable DB prompt so these behaviours always hold.
     */
    private function conversationGuidance(array $ctx): string
    {
        $loggedIn = ($ctx['is_guest'] ?? true) === false ? 'yes' : 'no';
        $checkoutUrl = rtrim($this->websiteUrl, '/') . '/checkout';

        $language = $ctx['language'] ?? 'Bengali';
        $languageLine = $language;
        if (!empty($ctx['language_script'])) {
            $languageLine .= ' ' . $ctx['language_script'];
        }
        return <<<TXT
## HOW TO TALK (VERY IMPORTANT)
Talk like a friendly shopkeeper at the next door dokan — warm, natural, casual.
- Do NOT sound like a form or a robot. Use everyday words, short friendly sentences.
- **LANGUAGE (dominant rule):** Always reply in the customer's preferred language: {$languageLine}.
  This applies even when the customer writes their message in English or a short greeting —
  do NOT default to English. Most customers are South Asian living in Japan and expect their
  home language with the correct script (Bengali, Devanagari/Hindi, Urdu right-to-left,
  Gurmukhi, Tamil, Telugu, Kannada, Malayalam, Sinhala, Nepali, etc.).
- **Only exception:** If the customer writes a FULL sentence in a different language
  (e.g. a complete Japanese sentence), you may mirror that language for that reply.
  A short English word like "hello" or "ok" is NOT a reason to switch to English.
- Never reply in a language the customer cannot understand.
- Tell a small "golpo kotha" (friendly chit-chat) while you work: e.g. "Aaj brishti, garam garam khichuri bhalo lage — chal ar dal ache, lagbe?"
- Ask ONE natural follow-up question at a time instead of dumping everything.

## AUTOMATIC TOOL USE (do it yourself, don't ask permission)
When the customer speaks naturally, YOU decide and call the right tools automatically:
- "amar X lagbe / X dao / X lagbe bhai" → search_products_bulk (or filter_products) → present the match → add_item_to_cart when they confirm.
- "cart e add koro / add this" → add_item_to_cart / bulk_add_to_cart.
- "order korte chai / I want to order" → get_cart_contents first, confirm delivery address/date naturally, then guide to checkout.
- "order kothay / amar order" → get_order_status (use order id/tracking, or the logged-in customer's latest).
- "delivery kobe / koto din" → check_delivery_time / check_stock_availability with post code.
- "kichu jante chai / info" → search_support_kb, then answer conversationally.
- "problem / complaint / payment issue" → create_support_ticket; missing/damaged → create_order_claim.
- "recipe / ranna" → search_recipes then search_products_bulk for the ingredients ({{BULK_BUTTON}} list).
Never ask "should I use a tool?" — just use it and reply naturally with the result.

## STEP-BY-STEP ORDERING (story/narrative flow)
Help the customer order through friendly conversation, step by step:
1. Understand what they want; suggest products (use cart contents to avoid duplicates).
2. Confirm the items in a natural sentence ("Tahole 2kg chal ar 1L tel nicchi — thik ache?").
3. Add to cart with the cart tools.
4. When the customer wants to checkout / order / pay ("checkout koro", "order korte chai",
   "pay korte chai", "confirm koro") you MUST call the `open_checkout` tool. It opens the
   checkout panel INSIDE the chat (cart → address → delivery date/time → coins → payment).
   NEVER just paste a link and NEVER say you cannot do it — call the tool.
5. If the customer is not logged in and they need to log in/register, call the `open_login`
   tool — it opens the login/registration form INSIDE the chat. Never ask them to go to a website.
6. The checkout panel handles address, delivery slot, coins, and payment (Cash or card via
   Stripe) — all inside chat. After they finish, wish them well and ask if anything else is needed.
Customer logged in: {$loggedIn}

## STOCK BEFORE CHECKOUT (proactive, mandatory)
Before telling a customer to checkout/pay, call `get_cart_contents`. If any cart item
is out of stock or the requested quantity exceeds available stock:
- Tell them politely and specifically ("X ekhon stock e nei" / "X er matro N ta ache").
- Offer a fix instead of just an error: suggest reducing the quantity to what's available,
  or removing that item. Then call `open_checkout` again.
Never let the customer reach the payment step with an item that cannot be ordered.

## IMAGES (multimodal)
If the customer sends a photo (product, recipe, receipt, damaged item, screenshot):
- Look at it and respond helpfully. Identify the product/issue from the image.
- Product photo → search for a matching product and offer to add it.
- Recipe photo → read it and offer the ingredients as a shopping list.
- Damaged/wrong item or receipt → create_order_claim / create_support_ticket and reassure them.
- Never say you cannot see images.
TXT;
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
            'language' => null,
            'language_code' => null,
            'language_script' => null,
            'language_rtl' => false,
            'country' => null,
            'prefecture' => null,
        ];

        $customer = null;
        if ($session->customer_id) {
            $ctx['is_guest'] = false;
            try {
                $customerModel = config('gunma-agent.models.customer');
                if ($customerModel && class_exists($customerModel)) {
                    $customer = $customerModel::find($session->customer_id);
                    if ($customer) {
                        $ctx['name'] = $ctx['name'] ?? $customer->name;
                        $ctx['email'] = $ctx->email ?? $customer->email;
                        $ctx['points'] = (int) ($customer->available_point ?? 0);
                        $ctx['country'] = $customer->country ?? null;

                        $orderModel = config('gunma-agent.models.order');
                        if ($orderModel && class_exists($orderModel)) {
                            $ctx['previous_orders'] = $orderModel::where('customer_id', $customer->id)->count();
                        }

                        $cartModel = config('gunma-agent.models.cart');
                        if ($cartModel && class_exists($cartModel)) {
                            $ctx['cart_count'] = $cartModel::where('customer_id', $customer->id)->count();
                        }

                        $insightService = app(\Anwar\GunmaAgent\Services\CustomerInsightService::class);
                        $ctx['insight'] = $insightService->analyzeCustomer($customer->id);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('[Agent] User context fetch failed', ['error' => $e->getMessage()]);
            }
        }

        // Response language: profile → country → Accept-Language → default.
        try {
            $accept = request()?->header('Accept-Language');
            $locale = app(\Anwar\GunmaAgent\Services\LocalizationService::class)->resolve($customer, $accept);
            $ctx['language'] = $locale['name'];
            $ctx['language_code'] = $locale['code'];
            $ctx['language_script'] = $locale['script'] ?? null;
            $ctx['language_rtl'] = (bool) ($locale['rtl'] ?? false);
        } catch (\Exception $e) {
            Log::debug('[Agent] Locale resolve failed', ['error' => $e->getMessage()]);
        }

        // Best-effort location (prefecture) for weather-aware suggestions.
        try {
            $ctx['prefecture'] = $this->resolvePrefecture($session);
        } catch (\Exception $e) {
            Log::debug('[Agent] Prefecture resolve failed', ['error' => $e->getMessage()]);
        }

        return $ctx;
    }

    /**
     * Resolve a location string (prefecture/city/postcode) for the session's
     * customer, used for weather-aware suggestions. Cheap + null-safe.
     */
    private function resolvePrefecture(ChatSession $session): ?string
    {
        if (! $session->customer_id) {
            return null;
        }

        try {
            $addressModel = config('gunma-agent.models.address', \App\Models\Address::class);
            if (! $addressModel || ! class_exists($addressModel)) {
                return null;
            }
            $address = $addressModel::where('customer_id', $session->customer_id)
                ->orderByDesc('default')
                ->orderByDesc('id')
                ->first();

            return $address?->state ?: $address?->postal_code ?: null;
        } catch (\Throwable $e) {
            return null;
        }
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

        // 2. Semantic cache — skipped for action requests (checkout/login/cart)
        //    so the corresponding tool always fires instead of a cached reply.
        $isAction = $this->isActionIntent($userMessage);
        if (! $isAction) {
            $cachedResponse = $this->qdrantService->getSemanticCache($userMessage);
            if ($cachedResponse !== null) {
                Log::info('[Agent] Semantic cache hit');
                $this->persistMessages($session, $userMessage, $cachedResponse, 'semantic_cache');
                return $cachedResponse;
            }
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

        // 2. Semantic cache — skipped for action requests (checkout/login/cart)
        //    so the corresponding tool always fires instead of a cached reply.
        $isAction = $this->isActionIntent($userMessage);
        if (! $isAction) {
            $cachedResponse = $this->qdrantService->getSemanticCache($userMessage);
            if ($cachedResponse !== null) {
                $msg = $this->persistMessages($session, $userMessage, $cachedResponse, 'semantic_cache');
                yield $this->sseEvent('message', ['id' => $msg->id, 'content' => $cachedResponse]);
                yield $this->sseEvent('done', []);
                return;
            }
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
        $imageUrls = $this->extractImageUrls($userMessage);
        $messages = $this->buildContextWindow($session, $userMessage, $imageUrls);
        $finalContent = '';
        $totalTokens = 0;
        $iterations = 0;
        $maxIterations = (int) config('gunma-agent.max_tool_iterations', 5);
        $usedModel = null;
        $usedActionTool = false;
        $isAction = $this->isActionIntent($userMessage);

        // Use the vision model when the message carries an image.
        $primary = !empty($imageUrls) ? ($this->vision() ?? $this->llm()) : $this->llm();
        $fallback = $this->fallback();

        while ($iterations < $maxIterations) {
            $iterations++;
            try {
                $data = $this->chatCompletion($primary, $messages);
                $usedModel = $primary['model'];

                // Primary failed → try configured fallback provider.
                if ($data === null) {
                    if ($fallback !== null) {
                        Log::warning('[Agent] Primary LLM failed, trying fallback', ['model' => $fallback['model']]);
                        $data = $this->chatCompletion($fallback, $messages);
                        $usedModel = $fallback['model'];
                    }
                    if ($data === null) {
                        $finalContent = "I'm sorry, I couldn't process that. Would you like to speak with a human agent?";
                        break;
                    }
                }

                $message = $data['choices'][0]['message'] ?? [];
                $totalTokens += ($data['usage']['total_tokens'] ?? 0);
                $messages[] = $message;

                if (!empty($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $toolCall) {
                        $fnName = $toolCall['function']['name'] ?? '';
                        $fnArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                        if (in_array($fnName, self::ACTION_TOOLS, true)) {
                            $usedActionTool = true;
                        }
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
                    if (trim((string) $finalContent) === '' && !empty($message['reasoning'])) {
                        $finalContent = $message['reasoning'];
                    }
                    break;
                }
            } catch (\Exception $e) {
                Log::error('[Agent] Loop error', ['error' => $e->getMessage()]);
                $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                break;
            }
        }

        $this->persistMessages($session, $userMessage, $finalContent, $usedModel ?? ($primary['model']), $totalTokens);
        $this->qdrantService->indexMemory($session->id, $userMessage, $finalContent);
        $this->storeConversationSummary($session, $userMessage, $finalContent);
        // Never cache replies that triggered a UI action tool (they must re-run).
        if (! $usedActionTool && ! $isAction && $finalContent !== "I'm sorry, I encountered an error. How else can I help you?") {
            $this->qdrantService->setSemanticCache($userMessage, $finalContent);
        }

        return $finalContent;
    }

    /* ── Stream Agent Loop ─────────────────────────────────────── */

    private function runAgentLoopStream(ChatSession $session, string $userMessage): \Generator
    {
        $imageUrls = $this->extractImageUrls($userMessage);
        $messages = $this->buildContextWindow($session, $userMessage, $imageUrls);

        yield $this->sseEvent('thinking', ['status' => 'Processing your request...']);

        $finalContent = '';
        $totalTokens = 0;
        $iterations = 0;
        $maxIterations = (int) config('gunma-agent.max_tool_iterations', 5);
        $usedModel = null;
        $usedActionTool = false;
        $isAction = $this->isActionIntent($userMessage);

        $primary = !empty($imageUrls) ? ($this->vision() ?? $this->llm()) : $this->llm();
        $fallback = $this->fallback();

        while ($iterations < $maxIterations) {
            $iterations++;
            try {
                $data = $this->chatCompletion($primary, $messages);
                $usedModel = $primary['model'];

                if ($data === null) {
                    if ($fallback !== null) {
                        yield $this->sseEvent('status', ['message' => 'Primary AI unavailable, switching provider...']);
                        $data = $this->chatCompletion($fallback, $messages);
                        $usedModel = $fallback['model'];
                    }
                    if ($data === null) {
                        $finalContent = "I'm sorry, I couldn't process that. Would you like to speak with a human agent?";
                        yield $this->sseEvent('message', ['content' => $finalContent]);
                        break;
                    }
                }

                $message = $data['choices'][0]['message'] ?? [];
                $totalTokens += ($data['usage']['total_tokens'] ?? 0);
                $messages[] = $message;

                if (!empty($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $toolCall) {
                        $fnName = $toolCall['function']['name'] ?? '';
                        $fnArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                        if (in_array($fnName, self::ACTION_TOOLS, true)) {
                            $usedActionTool = true;
                        }

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
                    if (trim((string) $finalContent) === '' && !empty($message['reasoning'])) {
                        $finalContent = $message['reasoning'];
                    }
                    break;
                }
            } catch (\Exception $e) {
                Log::error('[Agent] Stream loop error', ['error' => $e->getMessage()]);
                $finalContent = "I'm sorry, I encountered an error. How else can I help you?";
                yield $this->sseEvent('message', ['content' => $finalContent]);
                break;
            }
        }

        if (empty(trim($finalContent)) || strlen(trim($finalContent)) < 5) {
            $finalContent = 'I apologize, but I wasn\'t able to generate a proper response. Could you rephrase your question or would you like me to connect you with a human agent?';
        }

        $savedMessage = $this->persistMessages($session, $userMessage, $finalContent, $usedModel ?? $primary['model'], $totalTokens);
        yield $this->sseEvent('message', ['id' => $savedMessage->id, 'content' => $finalContent]);

        $this->qdrantService->indexMemory($session->id, $userMessage, $finalContent);
        $this->storeConversationSummary($session, $userMessage, $finalContent);
        // Never cache replies that triggered a UI action tool (they must re-run).
        if (! $usedActionTool && ! $isAction && $finalContent !== "I'm sorry, I encountered an error. How else can I help you?") {
            $this->qdrantService->setSemanticCache($userMessage, $finalContent);
        }

        yield $this->sseEvent('done', ['tokens' => $totalTokens]);
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

    private function buildContextWindow(ChatSession $session, string $userMessage, array $imageUrls = []): array
    {
        $systemPrompt = $this->buildSystemPrompt($session);

        // If the message carries images, send the model a clean text (without the
        // [IMAGE: url] markers) plus the image parts.
        $textForModel = $userMessage;
        if (!empty($imageUrls)) {
            $textForModel = trim(preg_replace('/\[IMAGE:\s*https?:\/\/[^\]]+\]/i', '', $userMessage));
            if ($textForModel === '') {
                $textForModel = 'Please look at this image and help me.';
            }
        }

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

        // The current user message is already persisted (and therefore present in
        // history). Only append it again if it is not already the last history turn,
        // to avoid sending the same user text twice to the model.
        $last = end($history);
        $alreadyLast = is_array($last)
            && ($last['role'] ?? null) === 'user'
            && ($last['content'] ?? null) === $userMessage;

        if (!$alreadyLast) {
            $messages[] = ['role' => 'user', 'content' => $textForModel];
        }

        // Attach images to the last user turn (multimodal), replacing any history
        // entry that still contains the raw marker.
        if (!empty($imageUrls)) {
            $replaced = false;
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? '') === 'user') {
                    $messages[$i]['content'] = $this->withImages($textForModel, $imageUrls);
                    $replaced = true;
                    break;
                }
            }
            if (! $replaced) {
                $messages[] = ['role' => 'user', 'content' => $this->withImages($textForModel, $imageUrls)];
            }
        }

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
