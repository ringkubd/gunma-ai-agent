<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Analyzes customer order history to build purchase patterns,
 * reorder predictions, and category preferences.
 */
class CustomerInsightService
{
    private array $config;

    public function __construct()
    {
        $this->config = config('gunma-agent.customer_insight', [
            'min_orders_for_analysis' => 2,
            'reorder_window_days' => 21,
            'max_suggestions' => 5,
        ]);
    }

    /* ── Main Analysis ─────────────────────────────────────────── */

    public function analyzeCustomer(int $customerId): ?array
    {
        try {
            $totalOrders = $this->countOrders($customerId);
            if ($totalOrders < $this->config['min_orders_for_analysis']) return null;

            return [
                'total_orders'        => $totalOrders,
                'avg_order_value'     => $this->avgOrderValue($customerId),
                'days_since_last'     => $this->daysSinceLastOrder($customerId),
                'top_categories'      => $this->getCategoryPreferences($customerId),
                'frequent_items'      => $this->getFrequentItems($customerId),
                'suggested_reorders'  => $this->getReorderSuggestions($customerId),
            ];
        } catch (\Exception $e) {
            Log::warning('[CustomerInsight] Analysis failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /* ── Count Orders ──────────────────────────────────────────── */

    private function countOrders(int $customerId): int
    {
        $orderModel = config('gunma-agent.models.order', \App\Models\Order::class);
        if (!class_exists($orderModel)) return 0;
        return $orderModel::where('customer_id', $customerId)->count();
    }

    private function avgOrderValue(int $customerId): float
    {
        $orderModel = config('gunma-agent.models.order', \App\Models\Order::class);
        if (!class_exists($orderModel)) return 0;
        return round(
            (float) $orderModel::where('customer_id', $customerId)->avg('total_amount') ?? 0,
            2
        );
    }

    private function daysSinceLastOrder(int $customerId): int
    {
        $orderModel = config('gunma-agent.models.order', \App\Models\Order::class);
        if (!class_exists($orderModel)) return 0;
        $last = $orderModel::where('customer_id', $customerId)->latest()->first();
        if (!$last) return 0;
        return (int) now()->diffInDays($last->created_at);
    }

    /* ── Category Preferences ──────────────────────────────────── */

    public function getCategoryPreferences(int $customerId, int $limit = 5): array
    {
        try {
            $orderIds = $this->getOrderIds($customerId);
            if (empty($orderIds)) return [];

            return DB::table('order_items')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->join('category_product', 'products.id', '=', 'category_product.product_id')
                ->join('categories', 'category_product.category_id', '=', 'categories.id')
                ->whereIn('order_items.order_id', $orderIds)
                ->select('categories.title', DB::raw('COUNT(DISTINCT order_items.order_id) as order_count'))
                ->groupBy('categories.title')
                ->orderByDesc('order_count')
                ->limit($limit)
                ->pluck('title')
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('[CustomerInsight] Category prefs failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /* ── Frequent Items ────────────────────────────────────────── */

    public function getFrequentItems(int $customerId, int $limit = 8): array
    {
        try {
            $orderIds = $this->getOrderIds($customerId);
            if (empty($orderIds)) return [];

            return DB::table('order_items')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->join('stocks', function ($join) {
                    $join->on('stocks.product_id', '=', 'products.id')
                         ->whereRaw('stocks.id = (SELECT MAX(s2.id) FROM stocks s2 WHERE s2.product_id = products.id)');
                })
                ->whereIn('order_items.order_id', $orderIds)
                ->where('products.status', 'Active')
                ->select(
                    'products.id',
                    'products.title',
                    'products.slug',
                    DB::raw('SUM(order_items.quantity) as total_qty'),
                    DB::raw('COUNT(DISTINCT order_items.order_id) as purchase_count'),
                    DB::raw('MAX(order_items.created_at) as last_purchased'),
                    'stocks.online_price'
                )
                ->groupBy('products.id', 'products.title', 'products.slug', 'stocks.online_price')
                ->orderByDesc('purchase_count')
                ->orderByDesc('last_purchased')
                ->limit($limit)
                ->get()
                ->map(fn($row) => $this->formatItem($row))
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('[CustomerInsight] Frequent items failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /* ── Reorder Suggestions ───────────────────────────────────── */

    public function getReorderSuggestions(int $customerId, int $limit = 5): array
    {
        try {
            $frequentItems = $this->getFrequentItems($customerId, 15);
            if (empty($frequentItems)) return [];

            $reorderWindow = $this->config['reorder_window_days'];
            $suggestions = [];

            foreach ($frequentItems as $item) {
                $daysSince = $item['days_since'];
                if ($daysSince > $reorderWindow * 0.6) {
                    $item['running_low'] = $daysSince > $reorderWindow * 0.8;
                    $item['message'] = $item['running_low']
                        ? "It's been {$daysSince}d since your last {$item['title']} — running low?"
                        : "You usually buy {$item['title']} every ~{$reorderWindow}d — {$daysSince}d since last. Want to restock?";
                    $suggestions[] = $item;
                }
            }

            return array_slice($suggestions, 0, $limit);
        } catch (\Exception $e) {
            Log::warning('[CustomerInsight] Reorder suggestions failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /* ── Frequently Bought Together ────────────────────────────── */

    public function getFrequentlyBoughtTogether(int $customerId, int $productId, int $limit = 5): array
    {
        try {
            $orderIds = $this->getOrderIds($customerId);
            if (empty($orderIds)) return [];

            $ordersWithProduct = DB::table('order_items')
                ->whereIn('order_id', $orderIds)
                ->where('product_id', $productId)
                ->pluck('order_id')
                ->toArray();

            if (empty($ordersWithProduct)) return [];

            return DB::table('order_items')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->join('stocks', function ($join) {
                    $join->on('stocks.product_id', '=', 'products.id')
                         ->whereRaw('stocks.id = (SELECT MAX(s2.id) FROM stocks s2 WHERE s2.product_id = products.id)');
                })
                ->whereIn('order_items.order_id', $ordersWithProduct)
                ->where('order_items.product_id', '!=', $productId)
                ->where('products.status', 'Active')
                ->select(
                    'products.id',
                    'products.title',
                    'products.slug',
                    DB::raw('COUNT(*) as together_count'),
                    'stocks.online_price'
                )
                ->groupBy('products.id', 'products.title', 'products.slug', 'stocks.online_price')
                ->orderByDesc('together_count')
                ->limit($limit)
                ->get()
                ->map(fn($row) => [
                    'product_id' => $row->id,
                    'title' => $row->title,
                    'price' => (float) ($row->online_price ?? 0),
                    'slug' => $row->slug,
                    'bought_together_count' => $row->together_count,
                ])
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('[CustomerInsight] Bought together failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /* ── Helpers ───────────────────────────────────────────────── */

    private function getOrderIds(int $customerId): array
    {
        $orderModel = config('gunma-agent.models.order', \App\Models\Order::class);
        if (!class_exists($orderModel)) return [];

        return $orderModel::where('customer_id', $customerId)
            ->whereNotIn('status', ['Cancel', 'Cancelled', 'Payment Failed', 'Payment Pending'])
            ->pluck('id')
            ->toArray();
    }

    private function formatItem($row): array
    {
        $lastPurchased = strtotime($row->last_purchased);
        return [
            'product_id'     => $row->id,
            'title'          => $row->title,
            'slug'           => $row->slug,
            'price'          => (float) ($row->online_price ?? 0),
            'total_quantity' => (int) $row->total_qty,
            'purchase_count' => (int) $row->purchase_count,
            'last_purchased' => $row->last_purchased,
            'days_since'     => (int) round((time() - $lastPurchased) / 86400),
        ];
    }
}
