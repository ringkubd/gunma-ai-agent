<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Http\Controllers;

use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Models\PikuProductBlurb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Fast, public endpoints that feed the Piku doodle:
 *  - /agent-features        (in AgentSettingsController)
 *  - /products/briefs       pre-generated blurbs for given product ids
 *  - /piku-suggestions      interest/cart/reorder-aware product picks,
 *                           with LIVE price + stock re-checked every call.
 */
class PikuFeedController extends Controller
{
    /** Supported blurb languages; English is the fallback. */
    private const LANGS = ['en', 'bn', 'hi'];

    /** GET /api/chat/products/briefs?ids=1,2,3&lang=bn */
    public function briefs(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->unique()
            ->take(50)
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['data' => (object) []])->header('Cache-Control', 'public, max-age=120');
        }

        $lang = $this->resolveLang($request);

        $rows = PikuProductBlurb::whereIn('product_id', $ids)
            ->whereIn('lang', [$lang, 'en'])
            ->inRandomOrder()
            ->get();

        $map = [];
        foreach ($ids as $id) {
            $own = $rows->filter(fn ($r) => (int) $r->product_id === $id && $r->lang === $lang);
            $base = $rows->filter(fn ($r) => (int) $r->product_id === $id && $r->lang === 'en');
            $pool = $own->isNotEmpty() ? $own : $base;
            $text = $pool->first()?->text;
            if ($text) {
                $map[(string) $id] = $text;
            }
        }

        return response()->json(['data' => $map, 'lang' => $lang])
            ->header('Cache-Control', 'public, max-age=120');
    }

    /**
     * GET /api/chat/piku-suggestions?session_id=...&limit=4
     *
     * Priority: cart item → last order/reorder → interest profile → trending.
     * Price + stock are read LIVE for every suggestion.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $limit = min(6, max(1, (int) $request->query('limit', 4)));
        $sessionId = (string) $request->query('session_id', '');

        $session = $sessionId !== '' ? ChatSession::find($sessionId) : null;
        $customerId = $session?->customer_id ? (int) $session->customer_id : (auth('customer')->id() ?: null);
        $visitorId = (string) ($session?->visitor_id ?? $request->header('X-Visitor-Id') ?? '');

        $lang = $this->resolveLang($request, $customerId);
        $picks = []; // product_id => kind
        $cart = $this->cartContext($customerId);

        // 1) Cart (most relevant; abandoned carts may become a recovery nudge)
        foreach ($cart['ids'] as $id) {
            $picks[$id] = ($cart['stale_hours'] >= 6) ? 'cart_recovery' : 'cart';
        }

        // 2) Last order (reorder)
        foreach ($this->lastOrderProductIds($customerId) as $id) {
            $picks[$id] ??= 'reorder';
        }

        // 3) Interest profile (customer, else visitor)
        foreach ($this->interestProductIds($customerId, $visitorId) as $id) {
            $picks[$id] ??= 'interest';
        }

        // 4) Trending fallback (best-selling recent orders)
        if (count($picks) < $limit) {
            foreach ($this->trendingProductIds($limit) as $id) {
                $picks[$id] ??= 'trending';
            }
        }

        $items = [];
        $productModel = config('gunma-agent.models.product', \App\Models\Product::class);

        $orderedPids = array_keys(array_filter($picks, fn ($k) => in_array($k, ['cart', 'cart_recovery'], true)));
        // cart stays first; the rest shuffle for variety
        $shufflePids = $orderedPids;
        $otherPids = array_filter(array_keys($picks), fn ($pid) => ! in_array($pid, $orderedPids, true));
        shuffle($otherPids);
        $orderedPids = array_merge($orderedPids, $otherPids);

        foreach ($orderedPids as $pid) {
            if (count($items) >= $limit) break;
            try {
                $product = $productModel::with(['latestStock', 'images'])->find($pid);
                if (! $product || $product->status !== 'Active') continue;

                // ── LIVE price + stock re-check (never cached) ──
                $stock = $product->latestStock;
                $price = (float) ($stock?->online_price ?? 0);
                $qty = (int) ($stock?->available_quantity ?? 0);
                $inStock = $qty > 0 && ($product->stock_availability ?? 'Stock-In') !== 'Stock-Out';

                $blurb = $this->blurbFor($pid, $lang);

                $items[] = [
                    'product_id' => (int) $product->id,
                    'title'      => (string) $product->title,
                    'slug'       => (string) $product->slug,
                    'price'      => $price,
                    'stock'      => $qty,
                    'in_stock'   => $inStock,
                    'kind'       => $picks[$pid],
                    'text'       => $blurb,
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }

        return response()->json([
            'data' => $items,
            'lang' => $lang,
            'cart_stale_hours' => $cart['stale_hours'],
        ])->header('Cache-Control', 'no-store');
    }

    /* ── Admin: Phase-2 coverage panel (Piku Monitor) ─────────────── */

    /** GET /api/admin/chat/piku-analytics?days=7 — business impact of Piku. */
    public function analytics(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 7);
        $data = app(\Anwar\GunmaAgent\Services\PikuAnalytics::class)->summary($days);
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    /** GET /api/admin/chat/piku-coverage */
    public function coverage(): JsonResponse
    {
        $blurbProductIds = 0;
        $byLang = [];
        try {
            $byLang = DB::table('piku_product_blurbs')->select('lang', DB::raw('COUNT(*) c'))->groupBy('lang')->pluck('c', 'lang')->all();
            $blurbTable = DB::getSchemaBuilder()->hasTable('piku_product_blurbs');
            $blurbTotal = $blurbTable ? DB::table('piku_product_blurbs')->count() : 0;
        } catch (\Throwable $e) {
            $blurbTable = false;
            $blurbTotal = 0;
        }

        try {
            $blurbCovered = $blurbTable
                ? DB::table('piku_product_blurbs')->distinct()->count('product_id')
                : 0;
            $productModel = config('gunma-agent.models.product', \App\Models\Product::class);
            $activeTotal = $productModel::where('status', 'Active')->where('is_online_available', 'Yes')->count();
        } catch (\Throwable $e) {
            $blurbCovered = 0;
            $activeTotal = 0;
        }

        $kbPoints = 0;
        $productPoints = 0;
        $fetchPoints = function (string $collection) use (&$productData) {
            try {
                $url = rtrim((string) config('gunma-agent.qdrant_url'), '/') . '/collections/'
                    . config('gunma-agent.qdrant_collection_prefix', '') . $collection;
                $ctx = stream_context_create(['http' => ['timeout' => 6]]);
                $raw = @file_get_contents($url, false, $ctx);
                if ($raw === false) return 0;
                $j = json_decode((string) $raw, true);
                return (int) ($j['result']['points_count'] ?? 0);
            } catch (\Throwable) {
                return 0;
            }
        };
        $kbPoints = $fetchPoints(config('gunma-agent.qdrant_collections.kb', 'gunmahal_kb'));
        $productPoints = $fetchPoints(config('gunma-agent.qdrant_collections.products', 'products'));

        $chats24 = 0;
        $msgs24 = 0;
        try {
            if (DB::getSchemaBuilder()->hasTable('chat_sessions')) {
                $chats24 = DB::table('chat_sessions')->where('created_at', '>=', now()->subDay())->count();
            }
            if (DB::getSchemaBuilder()->hasTable('chat_messages')) {
                $msgs24 = DB::table('chat_messages')->where('created_at', '>=', now()->subDay())->count();
            }
        } catch (\Throwable) {}

        $doodleClicks24 = 0;
        try {
            if (DB::getSchemaBuilder()->hasTable('chat_messages')) {
                $doodleClicks24 = DB::table('chat_messages')
                    ->where('role', 'user')
                    ->where('created_at', '>=', now()->subDay())
                    ->where('content', 'LIKE', 'Ei product ta niye aro jante chai%')
                    ->count();
            }
        } catch (\Throwable) {}

        $productData = [
            'chats_24h' => $chats24,
            'messages_24h' => $msgs24,
            'doodle_clicks_24h' => $doodleClicks24,
            'blurbs' => [ 
                'covered_products' => $blurbCovered,
                'active_products' => $activeTotal,
                'pct' => $activeTotal > 0 ? round($blurbCovered / max(1, $activeTotal) * 100, 1) : 0,
                'by_lang' => $byLang,
            ],
            'products_indexed' => $productPoints,
            'kb_entries' => $kbPoints,
        ];
        return response()->json(['data' => $productData]);
    }


    /**
     * Phase 5 — typed doodle-message pool.
     * GET /api/chat/piku-messages?session_id=&limit=8
     * Varied intents: greet / cart_complement / cart_recovery / cart_helper /
     * search_hook / reorder / spotlight / free_shipping / (tip). Text
     * pre-composed in the customer's language; price/stock are LIVE. No PII.
     */
    public function messages(Request $request): JsonResponse
    {
        $limit = min(12, max(1, (int) $request->query('limit', 8)));
        $sessionId = (string) $request->query('session_id', '');

        $session = $sessionId !== '' ? ChatSession::find($sessionId) : null;
        $customerId = $session?->customer_id ? (int) $session->customer_id : (auth('customer')->id() ?: null);
        $visitorId = (string) ($session?->visitor_id ?? $request->header('X-Visitor-Id') ?? '');
        $lang = $this->resolveLang($request, $customerId);
        $mode = (string) $request->query('mode', 'classic'); // 'activity' from the smart brain

        // ── Anonymous traffic collapse ──────────────────────────────────
        // With no customer and no visitor context the pool is identical for
        // every guest, so cache it briefly in Redis. At 1000 concurrent
        // visitors this turns thousands of DB-heavy builds into ~1/minute.
        $cacheable = $customerId === null && $visitorId === '';
        $cacheKey = "piku_pool:{$lang}:{$mode}:{$limit}";
        if ($cacheable) {
            $hit = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (is_array($hit)) {
                return response()->json(['data' => $hit, 'lang' => $lang, 'mode' => $mode])
                    ->header('Cache-Control', 'public, max-age=60');
            }
        }

        $out = [];
        $add = function (string $type, string $text, ?array $product = null, ?array $chips = null, ?int $weight = null, ?int $cooldownMs = null) use (&$out) {
            $row = ['type' => $type, 'text' => $text];
            if ($product) $row['product'] = $product;
            if ($chips) $row['chips'] = $chips;
            if ($weight !== null) $row['weight'] = $weight;
            if ($cooldownMs !== null) $row['cooldownMs'] = $cooldownMs;
            $out[] = $row;
        };

        $activityMode = $mode === 'activity';

        // 0) Greeting
        $name = null;
        if ($customerId) {
            try {
                $cm = config('gunma-agent.models.customer');
                $name = $cm && class_exists($cm) ? ($cm::find($customerId)->name ?? null) : null;
            } catch (\Throwable) {}
        }
        $greet = match ($lang) {
            'bn' => $name ? "আপনি তো রিবল হলেন {$name}! আজ কী লাগবে? 😊" : 'আসসালামু আলাইকুম! আজ কী রান্না হবে? আমি রেডি 🍳',
            'hi' => $name ? "नमस्ते {$name}! आज क्या बनाएंगे?" : 'नमस्ते! आज मैं क्या दिला दूँ? 💬',
            default => $name ? "Hello {$name}! What are we cooking today? 😊" : 'Hi! What can I get for you today? 💬',
        };
        $add('greet', $greet);

        // 1) Cart context
        $cart = $this->cartContext($customerId);
        $justOrdered = (float) ($cart['recent_order_hours'] ?? 99) < 6; // সফল order হলে add-to-cart নয়

        if (! empty($cart['ids']) && ! $justOrdered) {
            $firstPid = (int) reset($cart['ids']);
            $p = $this->productBrief($customerId, $firstPid, $lang);
            if ($p) {
                $chips = [
                    ['label' => ($lang === 'bn' ? 'রেসিপি দাও' : 'Recipe please'), 'prefill' => "{$p['title']} er recipe dao"],
                    ['label' => ($lang === 'bn' ? 'কার্ট দেখাও' : 'Show cart'), 'prefill' => 'amar cart dekhao'],
                ];
                $add('cart_complement', ($lang === 'bn' ? 'আপনার কার্টে ' : 'You have ') . "{$p['title']} — " . ($lang === 'bn' ? 'একটা বানানো যাই?' : 'want to make something with it?'), $p, $chips);
            }
        }

        // 2) Search-intent hook (recent site search -> best product)
        $kw = $this->recentSearchKeyword($customerId, (string) ($session?->visitor_id ?? ''));
        if ($kw) {
            $hit = $this->searchProduct($kw);
            if ($hit) {
                $add('search_hook', ($lang === 'bn'
                    ? "আপনি \"{$kw}\" খুঁজে প্র ছিলেন! এখন স্টকে আছে {$hit['title']} ✅"
                    : "You were looking for \"{$kw}\" — found: {$hit['title']} ✅"), $hit, [
                    ['label' => ($lang === 'bn' ? 'দেখাও' : 'Show it'), 'prefill' => "{$hit['title']} dekhao"]
                ], 90, 6000);
            }
        }

        // 3) Reorder (last order)
        $reorderIds = $this->lastOrderProductIds($customerId);
        if (! empty($reorderIds)) {
            $pid = (int) reset($reorderIds);
            if ($p = $this->productBrief($customerId, $pid, $lang)) {
                $add('reorder', ($lang === 'bn' ? 'আগে এটা নিতে চিলেন — ' : 'Last time you loved ') . "{$p['title']} — আবার নিতে চান? 🛒", $p, [
                    ['label' => ($lang === 'bn' ? 'রিঅর্ডার করো' : 'Reorder'), 'prefill' => "{$p['title']} cart e add koro"],
                ], 75, 90000);
            }
        }

        // 4) Free shipping nudge
        if ($customerId) {
            $subtotal = $this->cartSubtotal($customerId);
            if ($subtotal >= 8000 && $subtotal < 10000) {
                $remaining = (float) (10000 - $subtotal);
                $msg = $lang === 'bn'
                    ? 'আরও ¥' . number_format($remaining) . " অ্যাড করলে ডেলিভারি ফ্রি ভাই! 💸"
                    : "Add ¥" . number_format($remaining) . " more and delivery is FREE! 💸";
                $add('free_shipping', $msg, null, null, 80, 60000);
            }
        }

        // 5) Cart recovery (stalled carts only)
        if ($justOrdered) {
            // nothing — placed recently; reorder section handles nostalgia without add-to-cart
        } elseif ($cart['stale_hours'] >= 6) {
            $stale = (int) $cart['stale_hours'];
            $add('cart_recovery', ($lang === 'bn'
                ? "আপনার কার্টে আইটেম আছে ({$stale} ঘণ্টা ধরে!) — চেকআউট হয় নাই! এখন করে নিবেন? 💬"
                : "Your cart has been waiting {$stale}h — let's finish checkout! 💬"));
        } elseif (! empty($cart['ids'])) {
            $add('cart_helper', ($lang === 'bn'
                ? 'চলুন সব আইটেম কার্টে রাখতে পারি! "Add all" বলুন 💬'
                : 'Say "add all" and I will add all to your cart at once 💬'));
        }

        // 6) Interest spotlight
        $numSpot = max(0, $limit - count($out) - 2);
        $spotAdded = 0;
        foreach ($this->interestProductIds($customerId, (string) ($session?->visitor_id ?? '')) as $pid) {
            if ($spotAdded >= max(1, $numSpot) || count($out) >= $limit) break;
            if ($p = $this->productBrief($customerId, (int) $pid, $lang)) {
                $spotAdded++;
                $chips = [
                    ['label' => ($lang === 'bn' ? 'রেসিপি দাও' : 'Recipe please'), 'prefill' => "{$p['title']} er recipe dao"],
                    ['label' => ($lang === 'bn' ? 'অ্যাড করো' : 'Add to cart'), 'prefill' => "{$p['title']} cart e add koro"],
                ];
                $add('spotlight', ($lang === 'bn' ? 'পিকু মনে রাখল — ' : 'Piku picked just for you — ') . $p['text'] . ' (' . $p['price_line'] . ')', $p, $chips, 60, 45000);
            }
        }

        // 7) Anonymous filler: popular products so the pool is not greet-only
        if ($customerId === null) {
            try {
                $pm = config('gunma-agent.models.product', \App\Models\Product::class);
                $pids = $pm::where('status', 'Active')->where('is_online_available', 'Yes')
                    ->inRandomOrder()->limit(max(2, 5 - count($out)))->pluck('id')->all();
            } catch (\Throwable) { $pids = []; }
            foreach ($pids as $pid) {
                if (count($out) >= $limit) break;
                if ($p = $this->productBrief(null, (int) $pid, $lang)) {
                    $chips = [
                        ['label' => ($lang === 'bn' ? 'রেসিপি দাও' : 'Recipe please'), 'prefill' => "{$p['title']} er recipe dao"],
                        ['label' => ($lang === 'bn' ? 'অ্যাড করো' : 'Add to cart'), 'prefill' => "{$p['title']} cart e add koro"],
                    ];
                    $add('spotlight', ($lang === 'bn' ? 'আজকের স্পেশাল — ' : "Today's pick — ") . $p['title'] . ' (' . $p['price_line'] . ')', $p, $chips, 50, 45000);
                }
            }
        }

        // 8) Generic tips (activity mode only) — keep Piku alive without nagging.
        if ($activityMode) {
            foreach ($this->genericTips($lang) as $i => $tip) {
                if (count($out) >= $limit) break;
                $add('tip', $tip, null, null, 30, 35000);
            }
            // 9) Seasonal / time-based opener (existing ProactiveTriggerService)
            if (count($out) < $limit) {
                try {
                    $triggers = app(\Anwar\GunmaAgent\Services\ProactiveTriggerService::class)->getTriggers();
                    $season = (string) ($triggers['season'] ?? '');
                    if ($season !== '' && in_array($season, ['ramadan', 'eid', 'winter', 'summer', 'rainy'], true)) {
                        $seasonLines = [
                            'bn' => "{$season} সিজনের স্পেশাল রেডি — কী লাগবে বলুন! 🍽️",
                            'hi' => "{$season} सीज़न का स्पेशल तैयार है — बताइए क्या चाहिए! 🍽️",
                            'en' => ucfirst($season) . " season specials are ready — tell me what you need! 🍽️",
                        ];
                        $add('season_time', $seasonLines[$lang] ?? $seasonLines['en'], null, null, 40, 120000);
                    }
                } catch (\Throwable) { /* never block the feed */ }
            }
        }

        $payload = array_slice($out, 0, $limit);
        // Attribute the doodle pool serving to this session/visitor (one row
        // per fetch, so "Piku reached N visitors" is measurable).
        try {
            app(\Anwar\GunmaAgent\Services\PikuAnalytics::class)->log('doodle_message', [
                'session_id'  => $sessionId ?: null,
                'customer_id' => $customerId,
                'visitor_id'  => $visitorId ?: null,
                'lang'        => $lang,
            ], ['mode' => $mode, 'count' => count($payload)]);
        } catch (\Throwable) { /* never block */ }

        if ($cacheable && ! empty($payload)) {
            try {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $payload, now()->addSeconds(60));
            } catch (\Throwable) { /* cache best-effort */ }
            return response()->json(['data' => $payload, 'lang' => $lang, 'mode' => $mode])
                ->header('Cache-Control', 'public, max-age=60');
        }

        return response()->json(['data' => $payload, 'lang' => $lang, 'mode' => $mode])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/chat/piku-compose
     *
     * Body: { signal: {...}, context: {...}, lang: 'bn' }
     * Returns { text, lang }. Best-quality contextual one-liner for the smart
     * Piku. Falls back to { text: null } so the widget uses pre-composed cues.
     */
    public function compose(Request $request): JsonResponse
    {
        $signal = (array) $request->input('signal', []);
        $context = (array) $request->input('context', []);
        $bodyLang = strtolower((string) $request->input('lang', ''));
        $lang = in_array($bodyLang, self::LANGS, true)
            ? $bodyLang
            : $this->resolveLang($request, auth('customer')->id() ?: null);

        // Merge the caller context over the signal for a richer prompt.
        $merged = array_merge($signal, $context);
        $merged['type'] = (string) ($signal['type'] ?? 'tip');

        try {
            $text = app(\Anwar\GunmaAgent\Services\PikuComposerService::class)->compose($merged, $lang);
        } catch (\Throwable $e) {
            $text = null;
        }

        if (is_string($text) && $text !== '') {
            try {
                app(\Anwar\GunmaAgent\Services\PikuAnalytics::class)->log('compose', [
                    'session_id' => $request->input('session_id'),
                    'visitor_id' => $request->header('X-Visitor-Id'),
                    'product_id' => isset($merged['product_id']) ? (int) $merged['product_id'] : null,
                    'lang'       => $lang,
                ], ['signal' => $merged['type']]);
            } catch (\Throwable) { /* never block */ }
        }

        return response()->json(['text' => $text, 'lang' => $lang])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/chat/piku-events
     *
     * Widget-side analytics ingest (doodle_message, chat_opened, chip_click,
     * product_focus, checkout_opened, login_opened). Best-effort, throttled.
     */
    public function events(Request $request): JsonResponse
    {
        $event = (string) $request->input('event', '');
        $payload = (array) $request->input('data', []);
        $meta = (array) $request->input('meta', []);

        $ok = app(\Anwar\GunmaAgent\Services\PikuAnalytics::class)->log($event, array_merge($payload, [
            'session_id' => $request->input('session_id') ?? ($payload['session_id'] ?? null),
            'visitor_id' => $payload['visitor_id'] ?? $request->header('X-Visitor-Id'),
        ]), $meta);

        return response()->json(['ok' => $ok])->header('Cache-Control', 'no-store');
    }

    /** Language-matched generic tips for the activity brain (non-nagging). */
    private function genericTips(string $lang): array
    {
        $tips = [
            'bn' => [
                'আজকের ফ্রেশ স্টক এসে গেছে — দেখে নিন! 🥬',
                'রেসিপি লাগলে বলুন, আমি স্টেপ-বাই-স্টেপ দিয়ে দেবো 🍳',
                'কার্টে অ্যাড করলে আমি উপকরণও মিলিয়ে দেবো ✅',
                'আজকে কোনটা বানাতে চান? বলুন, হেল্প করবো 😊',
                'ফ্রি ডেলিভারি পেতে ¥10,000 পর্যন্ত পৌঁছান — একটু বাকি! 💸',
            ],
            'hi' => [
                'आज ताज़ा स्टॉक आ गया है — देख लीजिए! 🥬',
                'रेसिपी चाहिए तो बताइए, स्टेप-बाय-स्टेप दूँगी 🍳',
                'कार्ट में जोड़ें, मैं सामग्री भी मिला दूँगी ✅',
            ],
            'en' => [
                'Fresh stock just landed — take a look! 🥬',
                'Need a recipe? Say the word and I will give it step-by-step 🍳',
                'Add it to cart and I can sort the ingredients too ✅',
                'What are we cooking today? I am happy to help 😊',
            ],
        ];

        $list = $tips[$lang] ?? $tips['en'];
        shuffle($list);
        return $list;
    }
    private function blurbFor(int $productId, string $lang): ?string
    {
        try {
            $rows = PikuProductBlurb::where('product_id', $productId)
                ->whereIn('lang', [$lang, 'en'])
                ->inRandomOrder()
                ->get();
            $rows = $rows->sortBy(fn ($r) => $r->lang === $lang ? 0 : 1);
            return $rows->first()?->text;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveLang(Request $request, ?int $customerId = null): string
    {
        $requested = strtolower((string) $request->query('lang', ''));
        if (in_array($requested, self::LANGS, true)) {
            return $requested;
        }

        // Registered customer's stored language
        if ($customerId) {
            try {
                $customer = config('gunma-agent.models.customer');
                if ($customer && class_exists($customer)) {
                    $row = $customer::find($customerId);
                    $native = strtolower((string) ($row->native_language ?? ''));
                    $mapped = $this->mapLang($native);
                    if ($mapped) return $mapped;
                }
            } catch (\Throwable) {}
        }

        // Accept-Language header
        $header = strtolower((string) $request->header('Accept-Language', ''));
        foreach (['hi', 'bn', 'en'] as $code) {
            if (str_contains($header, $code)) {
                return $code;
            }
        }

        return 'en'; // fallback
    }

    private function mapLang(string $value): ?string
    {
        return match (true) {
            str_contains($value, 'bengali'), str_contains($value, 'bangla'), $value === 'bn' => 'bn',
            str_contains($value, 'hindi'), $value === 'hi' => 'hi',
            str_contains($value, 'english'), $value === 'en' => 'en',
            default => null,
        };
    }

    /**
     * Cart product ids + staleness (hours since the OLDEST un-completed cart
     * row). Stale >= 6h is treated as an abandoned-cart recovery moment.
     *
     * @return array{ids: array, stale_hours: float}
     */
    private function cartContext(?int $customerId): array
    {
        if (! $customerId) return ['ids' => [], 'stale_hours' => 0.0, 'recent_order_hours' => 99.0];
        try {
            $cart = config('gunma-agent.models.cart', \App\Models\Cart::class);
            if (! class_exists($cart)) return ['ids' => [], 'stale_hours' => 0.0, 'recent_order_hours' => 99.0];

            $rows = $cart::where('customer_id', $customerId)->whereNull('deleted_at')->get(['product_id', 'created_at']);
            if ($rows->isEmpty()) return ['ids' => [], 'stale_hours' => 0.0, 'recent_order_hours' => $this->lastOrderHours($customerId)];

            $ids = $rows->pluck('product_id')->unique()->all();
            $stale = 0.0;
            if ($cart::where('customer_id', $customerId)->whereNull('deleted_at')->exists()) {
                // only counts as abandoned if nothing has been ordered since
                $lastOrder = config('gunma-agent.models.order', \App\Models\Order::class);
                $orderedSince = $lastOrder && class_exists($lastOrder)
                    ? $lastOrder::where('customer_id', $customerId)->max('created_at')
                    : null;

                $oldest = $rows->min('created_at');
                $hours = $oldest ? now()->diffInHours($oldest) : 0;
                $recentOrder = $orderedSince ? now()->diffInHours($orderedSince) : 99;
                $stale = $recentOrder > 6 ? round(max(0.0, (float) $hours), 1) : max(0.0, min($hours, 5.9));
            }
            return ['ids' => $ids, 'stale_hours' => $stale, 'recent_order_hours' => $this->lastOrderHours($customerId)];
        } catch (\Throwable) {
            return ['ids' => [], 'stale_hours' => 0.0, 'recent_order_hours' => 99.0];
        }
    }

    private function lastOrderHours(?int $customerId): float
    {
        try {
            $m = config('gunma-agent.models.order', \App\Models\Order::class);
            if (! $m || ! class_exists($m)) return 99.0;
            $last = $m::where('customer_id', $customerId)->max('created_at');
            return $last ? round((float) now()->diffInHours($last), 1) : 99.0;
        } catch (\Throwable) { return 99.0; }
    }

    private function lastOrderProductIds(?int $customerId): array
    {
        if (! $customerId) return [];
        try {
            $order = config('gunma-agent.models.order', \App\Models\Order::class);
            if (! class_exists($order)) return [];

            $orderId = $order::where('customer_id', $customerId)->latest('id')->value('id');
            if (! $orderId) return [];

            return DB::table('order_items')->where('order_id', $orderId)->pluck('product_id')->unique()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function interestProductIds(?int $customerId, string $visitorId): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('customer_interest_profiles')) return [];

            $keys = [];
            if ($customerId) $keys[] = 'customer:' . $customerId;
            if ($visitorId !== '') $keys[] = 'visitor:' . $visitorId;
            if (empty($keys)) return [];

            $row = DB::table('customer_interest_profiles')->whereIn('profile_key', $keys)->first();
            if (! $row) return [];

            $top = json_decode((string) ($row->top_products ?? '[]'), true);
            if (! is_array($top)) return [];

            arsort($top);
            $keys = array_map('intval', array_keys($top));
            // random weight-2 slice from the interest candidates — variety
            shuffle($keys);
            return array_slice($keys, 0, 6);
        } catch (\Throwable) {
            return [];
        }
    }

    private function trendingProductIds(int $limit): array
    {
        // Best-seller aggregation is expensive and identical for everyone —
        // cache it in Redis for 5 minutes.
        try {
            return \Illuminate\Support\Facades\Cache::remember("piku_trending:{$limit}", now()->addMinutes(5), function () use ($limit) {
                return $this->computeTrending($limit);
            });
        } catch (\Throwable) {
            return $this->computeTrending($limit);
        }
    }

    /** @return array<int,int> */
    private function computeTrending(int $limit): array
    {
        try {
            $ids = DB::table('order_items')
                ->select('product_id', DB::raw('COUNT(*) c'))
                ->groupBy('product_id')
                ->orderByDesc('c')
                ->limit($limit * 4)
                ->pluck('product_id')
                ->all();
            shuffle($ids);
            return $ids;
        } catch (\Throwable) {
            try {
                $productModel = config('gunma-agent.models.product', \App\Models\Product::class);
                return $productModel::where('status', 'Active')
                    ->where('is_online_available', 'Yes')
                    ->inRandomOrder()
                    ->limit($limit)
                    ->pluck('id')
                    ->all();
            } catch (\Throwable) {
                return [];
            }
        }
    }

    /* ── Phase-5 helpers ─────────────────────────────────────── */

    private function productBrief(?int $customerId, int $pid, string $lang): ?array
    {
        // Product core (title/slug/image/price/stock) is identical for every
        // shopper — cache per (pid, lang) in Redis for 90s. This is the single
        // biggest DB saving at 1000 concurrent users: thousands of per-user
        // productBrief queries collapse to a handful of cached rows.
        $key = "piku_brief:{$pid}:{$lang}";
        try {
            $hit = \Illuminate\Support\Facades\Cache::get($key);
            if (is_array($hit)) return $hit === [] ? null : $hit;
        } catch (\Throwable) { /* cache best-effort */ }

        try {
            $productModel = config('gunma-agent.models.product', \App\Models\Product::class);
            if (! class_exists($productModel)) return null;
            $product = str_contains($productModel, '\\') && method_exists($productModel, 'with')
                ? $productModel::with(['latestStock', 'images'])->find($pid)
                : $productModel::find($pid);
            if (! $product || $product->status !== 'Active') {
                try { \Illuminate\Support\Facades\Cache::put($key, [], now()->addSeconds(60)); } catch (\Throwable) {}
                return null;
            }

            $stock = $product->latestStock;
            $price = (float) ($stock?->online_price ?? 0);
            $qty = (int) ($stock?->available_quantity ?? 0);
            $inStock = $qty > 0 && ($product->stock_availability ?? 'Stock-In') !== 'Stock-Out';

            $brief = [
                'product_id' => (int) $product->id,
                'title'      => (string) $product->title,
                'slug'       => (string) $product->slug,
                'image'      => $product->images->first()?->image_path ?? null,
                'price'      => $price,
                'stock'      => $qty,
                'in_stock'   => $inStock,
                'price_line' => '¥' . number_format($price) . ($inStock ? '' : ' — stock nei'),
                'text'       => $this->blurbFor($pid, $lang),
            ];
            try { \Illuminate\Support\Facades\Cache::put($key, $brief, now()->addSeconds(90)); } catch (\Throwable) {}
            return $brief;
        } catch (\Throwable) {
            return null;
        }
    }

    private function recentSearchKeyword(?int $customerId, string $visitorId): ?string
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('customer_interest_profiles')) return null;
            $keys = [];
            if ($customerId) $keys[] = 'customer:' . $customerId;
            if ($visitorId !== '') $keys[] = 'visitor:' . $visitorId;
            $row = $keys ? DB::table('customer_interest_profiles')->whereIn('profile_key', $keys)->get()->first() : null;
            if (! $row) return null;
            $kw = json_decode((string) ($row->top_search_keywords ?? '[]'), true);
            if (! is_array($kw)) return null;
            arsort($kw);
            foreach (array_keys($kw) as $word) {
                $w = trim((string) $word);
                if (mb_strlen($w) > 2 && mb_strlen($w) < 40) return $w;
            }
        } catch (\Throwable) {}
        return null;
    }

    private function searchProduct(string $kw): ?array
    {
        try {
            $productModel = config('gunma-agent.models.product', \App\Models\Product::class);
            if (! class_exists($productModel)) return null;
            $product = $productModel::where('status', 'Active')
                ->where('is_online_available', 'Yes')
                ->where(function ($q) use ($kw) {
                    $q->where('title', 'LIKE', "%{$kw}%")
                      ->orWhere('description', 'LIKE', "%{$kw}%");
                })
                ->with(['latestStock', 'images'])
                ->first();

            if (! $product) return null;
            $stock = $product->latestStock;
            $qty = (int) ($stock?->available_quantity ?? 0);

            return [
                'product_id' => (int) $product->id,
                'title'      => (string) $product->title,
                'slug'       => (string) $product->slug,
                'image'      => $product->images->first()?->image_path ?? null,
                'price'      => (float) ($stock?->online_price ?? 0),
                'stock'      => $qty,
                'in_stock'   => $qty > 0 && ($product->stock_availability ?? 'Stock-In') !== 'Stock-Out',
                'price_line' => '¥' . number_format((float) ($stock?->online_price ?? 0)),
                'text'       => $this->blurbFor((int) $product->id, 'en'),
                'text_line'  => null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function cartSubtotal(?int $customerId): float
    {
        try {
            $cart = config('gunma-agent.models.cart', \App\Models\Cart::class);
            if (! $cart || ! class_exists($cart)) return 0.0;
            return (float) ($cart::where('customer_id', $customerId)->whereNull('deleted_at')->sum('total_amount') ?? 0);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function freeShippingNear(?int $customerId): bool
    {
        $subtotal = $this->cartSubtotal($customerId);
        return $subtotal >= 8000 && $subtotal < 10000;
    }

}
