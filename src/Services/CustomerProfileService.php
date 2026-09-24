<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Anwar\GunmaAgent\Models\ChatSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds a complete 360° profile for a chat session's participant — covering
 * both registered customers and guests — so human agents can see everything
 * about the person they're talking to.
 *
 * All host model/table access is config-driven or defensive so the package
 * works even when optional tables are absent.
 */
class CustomerProfileService
{
    /**
     * @return array<string,mixed>
     */
    public function forSession(ChatSession $session): array
    {
        $customerId = $session->customer_id ? (int) $session->customer_id : null;
        $visitorId  = (string) ($session->visitor_id ?? '');

        $profile = [
            'session' => [
                'id'             => (string) $session->id,
                'visitor_id'     => $visitorId,
                'channel'        => $session->channel ?? 'web',
                'status'         => $session->status ?? 'active',
                'is_ai_enabled'  => (bool) $session->is_ai_enabled,
                'customer_id'    => $customerId,
                'created_at'     => optional($session->created_at)->toIso8601String(),
                'updated_at'     => optional($session->updated_at)->toIso8601String(),
            ],
            'is_guest' => $customerId === null,
            'customer' => null,
            'orders'   => [],
            'cart'     => [],
            'addresses'=> [],
            'activity' => [],
            'interests'=> [],
            'metrics'  => [],
            'recent_messages' => [],
        ];

        if ($customerId) {
            $profile['customer']  = $this->customer($customerId);
            $profile['orders']    = $this->orders($customerId);
            $profile['cart']      = $this->cartByCustomer($customerId);
            $profile['addresses'] = $this->addresses($customerId);
        }

        $profile['activity'] = $this->activity($customerId, $visitorId);
        $profile['metrics']  = $this->metrics($profile, $session);
        $profile['recent_messages'] = $this->recentMessages($session);

        return $profile;
    }

    private function customer(int $id): ?array
    {
        try {
            $model = config('gunma-agent.models.customer');
            if (! $model || ! class_exists($model)) {
                return null;
            }
            $c = $model::find($id);
            if (! $c) {
                return null;
            }

            return [
                'id'              => $c->id,
                'name'            => $c->name ?? null,
                'email'           => $c->email ?? null,
                'phone'           => $c->contact_no ?? null,
                'country'         => $c->country ?? null,
                'native_language' => $c->native_language ?? null,
                'type'            => $c->type ?? null,
                'status'          => $c->status ?? null,
                'points'          => (int) ($c->available_point ?? 0),
                'wallet'          => (float) ($c->amount ?? 0),
                'joined_at'       => isset($c->created_at) ? (string) $c->created_at : null,
            ];
        } catch (\Throwable $e) {
            Log::debug('[Profile] customer failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function orders(int $customerId): array
    {
        try {
            $orderModel = config('gunma-agent.models.order');
            if (! $orderModel || ! class_exists($orderModel)) {
                return [];
            }

            return $orderModel::where('customer_id', $customerId)
                ->latest()
                ->limit(15)
                ->get()
                ->map(fn ($o) => [
                    'id'             => $o->id,
                    'status'         => $o->status ?? null,
                    'payment_status' => $o->payment_status ?? null,
                    'payment_method' => $o->payment_method ?? null,
                    'total_amount'   => (float) ($o->total_amount ?? 0),
                    'due_amount'     => (float) ($o->due_amount ?? 0),
                    'delivery_date'  => $o->delivary_date ? (string) $o->delivary_date : null,
                    'created_at'     => isset($o->created_at) ? (string) $o->created_at : null,
                ])
                ->toArray();
        } catch (\Throwable $e) {
            Log::debug('[Profile] orders failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function cartByCustomer(int $customerId): array
    {
        try {
            $cartModel = config('gunma-agent.models.cart');
            if (! $cartModel || ! class_exists($cartModel)) {
                return [];
            }

            return $cartModel::with('product')
                ->where('customer_id', $customerId)
                ->get()
                ->map(fn ($c) => [
                    'product_id' => $c->product_id,
                    'title'      => $c->product->title ?? null,
                    'quantity'   => (float) $c->quantity,
                    'price'      => (float) $c->item_price,
                    'line_total' => (float) $c->total_amount,
                ])
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function addresses(int $customerId): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('addresses')) {
                return [];
            }
            return DB::table('addresses')
                ->where('customer_id', $customerId)
                ->orderByDesc('default')
                ->limit(5)
                ->get()
                ->map(fn ($a) => [
                    'name'        => $a->name ?? null,
                    'phone'       => $a->phone ?? null,
                    'postal_code' => $a->postal_code ?? null,
                    'state'       => $a->state ?? null,
                    'city'        => $a->city ?? null,
                    'street'      => $a->street ?? null,
                    'apartment'   => $a->apartment ?? null,
                    'is_default'  => ($a->default ?? null) === 'Yes',
                ])
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Behavioural activity — works for both customers (by customer_id) and
     * guests (by visitor_id).
     */
    private function activity(?int $customerId, string $visitorId): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('customer_activities')) {
                return [];
            }

            $q = DB::table('customer_activities');
            if ($customerId) {
                $q->where('customer_id', $customerId);
            } elseif ($visitorId !== '') {
                $q->where('visitor_id', $visitorId);
            } else {
                return [];
            }

            $recent = (clone $q)->orderByDesc('logged_at')->limit(25)->get()
                ->map(fn ($a) => [
                    'action'    => $a->action,
                    'query'     => $a->query,
                    'page_url'  => $a->page_url,
                    'product_id'=> $a->product_id,
                    'prefecture'=> $a->prefecture,
                    'logged_at' => (string) $a->logged_at,
                ])->toArray();

            $byAction = (clone $q)
                ->select('action', DB::raw('COUNT(*) as c'))
                ->groupBy('action')
                ->orderByDesc('c')
                ->limit(12)
                ->get()
                ->pluck('c', 'action')
                ->toArray();

            $searches = (clone $q)->where('action', 'search')
                ->whereNotNull('query')
                ->orderByDesc('logged_at')
                ->limit(10)
                ->pluck('query')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            return [
                'recent'        => $recent,
                'by_action'     => $byAction,
                'recent_searches' => $searches,
                'last_seen'     => (clone $q)->max('logged_at'),
                'first_seen'    => (clone $q)->min('logged_at'),
            ];
        } catch (\Throwable $e) {
            Log::debug('[Profile] activity failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function metrics(array $profile, ChatSession $session): array
    {
        $orders = $profile['orders'] ?? [];
        $paid = array_filter($orders, fn ($o) => ! in_array($o['status'], ['Cancel', 'Cancelled', 'Payment Failed', 'Payment Pending'], true));
        $totalSpent = array_sum(array_map(fn ($o) => (float) $o['total_amount'], $paid));

        return [
            'orders_count'   => count($paid),
            'total_spent'    => round($totalSpent, 2),
            'avg_order'      => count($paid) ? round($totalSpent / count($paid), 2) : 0,
            'cart_items'     => count($profile['cart'] ?? []),
            'messages_count' => $session->messages()->count(),
        ];
    }

    private function recentMessages(ChatSession $session, int $limit = 30): array
    {
        try {
            return $session->messages()
                ->whereIn('role', ['user', 'assistant'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->sortBy('created_at')
                ->values()
                ->map(fn ($m) => [
                    'role'       => $m->role,
                    'content'    => mb_substr((string) $m->content, 0, 500),
                    'created_at' => (string) $m->created_at,
                ])
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
