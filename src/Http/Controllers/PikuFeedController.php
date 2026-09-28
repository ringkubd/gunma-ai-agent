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

        $out = [];
        $add = function (string $type, string $text, ?array $product = null, ?array $chips = null) use (&$out) {
            $row = ['type' => $type, 'text' => $text];
            if ($product) $row['product'] = $product;
            if ($chips) $row['chips'] = $chips;
            $out[] = $row;
        };

        // 0) Greeting
        $name = null;
        if ($customerId) {
            try {
                $cm = config('gunma-agent.models.customer');
                $name = $cm && class_exists($cm) ? ($cm::find($customerId)->name ?? null) : null;
            } catch (\Throwable) {}
        }
        $greet = match ($lang) {
            'bn' => $name ? "Apni to ribol holen {$name}! Aaj ki lagbe? 😊" : 'Assalamu alaikum! Aaj ki ranna hobe? Ami ready 🍳',
            'hi' => $name ? "नमस्ते {$name}! आज क्या बनाएंगे?" : 'नमस्ते! आज मैं क्या दिला दूँ? 💬',
            default => $name ? "Hello {$name}! What are we cooking today? 😊" : 'Hi! What can I get for you today? 💬',
        };
        $add('greet', $greet);

        // 1) Cart context
        $cart = $this->cartContext($customerId);

        if (! empty($cart['ids'])) {
            $firstPid = (int) reset($cart['ids']);
            $p = $this->productBrief($customerId, $firstPid, $lang);
            if ($p) {
                $chips = [
                    ['label' => ($lang === 'bn' ? 'Recipe dao' : 'Recipe please'), 'prefill' => "{$p['title']} er recipe dao"],
                    ['label' => ($lang === 'bn' ? 'Cart dekhao' : 'Show cart'), 'prefill' => 'amar cart dekhao'],
                ];
                $add('cart_complement', ($lang === 'bn' ? 'Apnar cart e ' : 'You have ') . "{$p['title']} — " . ($lang === 'bn' ? 'ewta banano jai?' : 'want to make something with it?'), $p, $chips);
            }
        }

        // 2) Search-intent hook (recent site search -> best product)
        $kw = $this->recentSearchKeyword($customerId, (string) ($session?->visitor_id ?? ''));
        if ($kw) {
            $hit = $this->searchProduct($kw);
            if ($hit) {
                $add('search_hook', ($lang === 'bn'
                    ? "Apni \"{$kw}\" khuje pro ছিলেন! Ekhon stock e ache {$hit['title']} ✅"
                    : "You were looking for \"{$kw}\" — found: {$hit['title']} ✅"), $hit, [
                    ['label' => ($lang === 'bn' ? 'Dekhao' : 'Show it'), 'prefill' => "{$hit['title']} dekhao"]
                ]);
            }
        }

        // 3) Reorder (last order)
        $reorderIds = $this->lastOrderProductIds($customerId);
        if (! empty($reorderIds)) {
            $pid = (int) reset($reorderIds);
            if ($p = $this->productBrief($customerId, $pid, $lang)) {
                $add('reorder', ($lang === 'bn' ? 'Age ei nite chilen — ' : 'Last time you loved ') . "{$p['title']} — abar nite chan? 🛒", $p, [
                    ['label' => ($lang === 'bn' ? 'Reorder koro' : 'Reorder'), 'prefill' => "{$p['title']} cart e add koro"],
                ]);
            }
        }

        // 4) Free shipping nudge
        if ($customerId) {
            $subtotal = $this->cartSubtotal($customerId);
            if ($subtotal >= 8000 && $subtotal < 10000) {
                $remaining = (float) (10000 - $subtotal);
                $msg = $lang === 'bn'
                    ? 'Aro ¥' . number_format($remaining) . " add korle delivery FREE bhai! 💸"
                    : "Add ¥" . number_format($remaining) . " more and delivery is FREE! 💸";
                $add('free_shipping', $msg);
            }
        }

        // 5) Cart recovery (stalled carts only)
        if ($cart['stale_hours'] >= 6) {
            $stale = (int) $cart['stale_hours'];
            $add('cart_recovery', ($lang === 'bn'
                ? "Apnar cart e item ache ({$stale} hours dhore!) — checkout hoy নাই! Ekhon kore niben? 💬"
                : "Your cart has been waiting {$stale}h — let's finish checkout! 💬"));
        } elseif (! empty($cart['ids'])) {
            $add('cart_helper', ($lang === 'bn'
                ? 'Choi sob item এখানে ওই বা ওটা cart e rakhkhei dilam! "Add all" bolun 💬'
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
                    ['label' => ($lang === 'bn' ? 'Recipe dao' : 'Recipe please'), 'prefill' => "{$p['title']} er recipe dao"],
                    ['label' => ($lang === 'bn' ? 'Add koro' : 'Add to cart'), 'prefill' => "{$p['title']} cart e add koro"],
                ];
                $add('spotlight', ($lang === 'bn' ? 'Piku মনে রাখল — ' : 'Piku picked just for you — ') . $p['text'] . ' (' . $p['price_line'] . ')', $p, $chips);
            }
        }

        // 7) Anonymous filler: popular products so the pool is not greet-only
        if ($customerId === null) {
            try {
                $pm = config('gunma-agent.models.product', \App\Models\Product::class);
                $pids = $pm::where('status', 'Active')->where('is_online_available', 'Yes')
                    ->inRandomOrder()->limit(max(0, 3 - count($out)))->pluck('id')->all();
            } catch (\Throwable) { $pids = []; }
            foreach ($pids as $pid) {
                if (count($out) >= $limit) break;
                if ($p = $this->productBrief(null, (int) $pid, $lang)) {
                    $chips = [
                        ['label' => ($lang === 'bn' ? 'Recipe dao' : 'Recipe please'), 'prefill' => "{$p['title']} er recipe dao"],
                        ['label' => ($lang === 'bn' ? 'Add koro' : 'Add to cart'), 'prefill' => "{$p['title']} cart e add koro"],
                    ];
                    $add('spotlight', ($lang === 'bn' ? 'Ajker special — ' : "Today's pick — ") . $p['title'] . ' (' . $p['price_line'] . ')', $p, $chips);
                }
            }
        }

        return response()->json(['data' => array_slice($out, 0, $limit), 'lang' => $lang])
            ->header('Cache-Control', 'no-store');
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
        if (! $customerId) return ['ids' => [], 'stale_hours' => 0.0];
        try {
            $cart = config('gunma-agent.models.cart', \App\Models\Cart::class);
            if (! class_exists($cart)) return ['ids' => [], 'stale_hours' => 0.0];

            $rows = $cart::where('customer_id', $customerId)->whereNull('deleted_at')->get(['product_id', 'created_at']);
            if ($rows->isEmpty()) return ['ids' => [], 'stale_hours' => 0.0];

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
            return ['ids' => $ids, 'stale_hours' => $stale];
        } catch (\Throwable) {
            return ['ids' => [], 'stale_hours' => 0.0];
        }
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
        try {
            $productModel = config('gunma-agent.models.product', \App\Models\Product::class);
            if (! class_exists($productModel)) return null;
            $product = $productModel::with(['latestStock', 'images'])->find($pid);
            if (! $product || $product->status !== 'Active') return null;

            $stock = $product->latestStock;
            $price = (float) ($stock?->online_price ?? 0);
            $qty = (int) ($stock?->available_quantity ?? 0);
            $inStock = $qty > 0 && ($product->stock_availability ?? 'Stock-In') !== 'Stock-Out';

            return [
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
