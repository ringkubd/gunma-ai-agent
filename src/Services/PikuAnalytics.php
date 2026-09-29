<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Models\PikuEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PikuAnalytics — records and reports what Piku actually did for the business.
 *
 * Ingest is best-effort and never throws: analytics must never break chat or
 * the storefront. All writes go into the lightweight `piku_events` table, and
 * reporting aggregates from there.
 */
class PikuAnalytics
{
    /** Allowed event names (keeps the table clean + queryable). */
    public const EVENTS = [
        'doodle_message',   // Piku spoke a pre-composed line
        'compose',          // Piku composed an LLM line
        'chat_opened',
        'product_focus',    // customer lingered on a product
        'chip_click',
        'tool_call',        // an agent tool ran (add to cart, recipe, ...)
        'checkout_opened',
        'login_opened',
        'order_placed',     // attributed order
        'support_handoff',  // escalated to a human
    ];

    /**
     * Record one event. Returns true when stored.
     *
     * @param  array<string,mixed>  $meta
     */
    public function log(string $event, array $data = [], array $meta = []): bool
    {
        if (! in_array($event, self::EVENTS, true)) {
            return false;
        }

        try {
            if (! DB::getSchemaBuilder()->hasTable('piku_events')) {
                return false;
            }

            // Resolve customer from the session when not provided.
            $customerId = $data['customer_id'] ?? null;
            $sessionId = $data['session_id'] ?? null;
            if ($customerId === null && $sessionId) {
                try {
                    $customerId = ChatSession::whereKey($sessionId)->value('customer_id');
                } catch (\Throwable) { /* ignore */ }
            }

            PikuEvent::create([
                'event'       => $event,
                'session_id'  => $sessionId,
                'customer_id' => $customerId,
                'visitor_id'  => $data['visitor_id'] ?? null,
                'product_id'  => $data['product_id'] ?? null,
                'order_id'    => $data['order_id'] ?? null,
                'tool'        => $data['tool'] ?? null,
                'value'       => $data['value'] ?? null,
                'lang'        => $data['lang'] ?? null,
                'metadata'    => empty($meta) ? null : $meta,
                'created_at'  => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::debug('[PikuAnalytics] log failed', ['event' => $event, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Business summary for a period.
     *
     * @return array<string,mixed>
     */
    public function summary(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $since = now()->subDays($days);

        $base = fn () => PikuEvent::query()->where('created_at', '>=', $since);

        try {
            if (! DB::getSchemaBuilder()->hasTable('piku_events')) {
                return ['enabled' => false, 'days' => $days];
            }

            $total = (clone $base())->count();
            $chats = (clone $base())->where('event', 'chat_opened')->distinct('session_id')->count('session_id');
            $doodle = (clone $base())->where('event', 'doodle_message')->count();
            $composed = (clone $base())->where('event', 'compose')->count();

            // Orders Piku drove + attributed revenue.
            $orderRows = (clone $base())->where('event', 'order_placed');
            $orders = (clone $orderRows)->count();
            $revenue = (float) (clone $orderRows)->sum('value');

            // Unique customers / visitors helped.
            $customersHelped = (clone $base())->whereNotNull('customer_id')->distinct('customer_id')->count('customer_id');
            $visitorsHelped = (clone $base())->distinct('visitor_id')->count('visitor_id');

            // Tool usage breakdown (what Piku actually did).
            $tools = (clone $base())->where('event', 'tool_call')
                ->select('tool', DB::raw('COUNT(*) c'))
                ->groupBy('tool')->orderByDesc('c')->limit(20)
                ->pluck('c', 'tool')->all();

            // Event mix.
            $events = (clone $base())
                ->select('event', DB::raw('COUNT(*) c'))
                ->groupBy('event')->orderByDesc('c')
                ->pluck('c', 'event')->all();

            // Daily trend (orders + chats).
            $daily = (clone $base())
                ->select(DB::raw('DATE(created_at) d'), 'event', DB::raw('COUNT(*) c'))
                ->whereIn('event', ['chat_opened', 'order_placed', 'doodle_message'])
                ->groupBy('d', 'event')->orderBy('d')
                ->get()
                ->groupBy('d')
                ->map(fn ($rows) => $rows->pluck('c', 'event')->all())
                ->all();

            // Top products Piku engaged with (focus + cart adds).
            $topProducts = (clone $base())->whereNotNull('product_id')
                ->select('product_id', DB::raw('COUNT(*) c'))
                ->groupBy('product_id')->orderByDesc('c')->limit(10)
                ->pluck('c', 'product_id')->all();
            $titles = [];
            try {
                if ($topProducts) {
                    $titles = DB::table('products')->whereIn('id', array_map('intval', array_keys($topProducts)))
                        ->pluck('title', 'id')->all();
                }
            } catch (\Throwable) {}
            $topProductList = [];
            foreach ($topProducts as $pid => $c) {
                $topProductList[] = ['product_id' => (int) $pid, 'title' => $titles[$pid] ?? ('#' . $pid), 'count' => (int) $c];
            }

            $conv = $chats > 0 ? round($orders / $chats * 100, 1) : 0.0;
            $aov = $orders > 0 ? round($revenue / $orders, 2) : 0.0;

            return [
                'enabled'            => true,
                'days'               => $days,
                'since'              => $since->toDateTimeString(),
                'total_events'       => $total,
                'chats'              => $chats,
                'doodle_messages'    => $doodle,
                'composed_lines'     => $composed,
                'orders_attributed'  => $orders,
                'revenue_attributed' => $revenue,
                'avg_order_value'    => $aov,
                'conversion_pct'     => $conv,
                'customers_helped'   => $customersHelped,
                'visitors_helped'    => $visitorsHelped,
                'tools'              => $tools,
                'events'             => $events,
                'daily'              => $daily,
                'top_products'       => $topProductList,
            ];
        } catch (\Throwable $e) {
            Log::warning('[PikuAnalytics] summary failed', ['error' => $e->getMessage()]);
            return ['enabled' => false, 'days' => $days, 'error' => 'aggregation failed'];
        }
    }
}
