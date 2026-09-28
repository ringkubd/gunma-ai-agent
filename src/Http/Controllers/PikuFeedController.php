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

        foreach (array_keys($picks) as $pid) {
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
            return array_map('intval', array_keys(array_slice($top, 0, 10, true)));
        } catch (\Throwable) {
            return [];
        }
    }

    private function trendingProductIds(int $limit): array
    {
        try {
            return DB::table('order_items')
                ->select('product_id', DB::raw('COUNT(*) c'))
                ->groupBy('product_id')
                ->orderByDesc('c')
                ->limit($limit * 3)
                ->pluck('product_id')
                ->map('intval')
                ->all();
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
}
