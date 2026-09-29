<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ToolExecutor
{
    /**
     * Order statuses that are NOT real orders and must never be shown as a
     * customer's order/pending order: abandoned or failed checkouts.
     */
    public const NON_ORDER_STATUSES = ['Payment Failed', 'Payment Pending'];

    public function __construct(
        private readonly QdrantService $qdrantService,
    ) {}

    /* ── Helper: get insight service (lazy load) ───────────────── */

    private function insightService(): CustomerInsightService
    {
        return app(CustomerInsightService::class);
    }

    public function execute(string $functionName, array $args): mixed
    {
        Log::info("[ToolExecutor] {$functionName}", $args);

        return match ($functionName) {
            'search_products_bulk'           => $this->decorateStock($this->qdrantService->searchProductsBulk($args['queries'] ?? [])),
            'get_product_details'            => $this->getProductDetails($args),
            'filter_products'                => $this->decorateStock($this->filterProducts($args)),
            'search_recipes'                 => $this->qdrantService->searchRecipes($args['query'] ?? ''),
            'search_support_kb'              => $this->qdrantService->searchSupportKB($args['query'] ?? ''),
            'cache_new_recipe'               => $this->cacheNewRecipe($args),
            'get_order_status'               => $this->getOrderStatus($args),
            'get_order_tracking'             => $this->getOrderTracking($args),
            'get_customer_info'              => $this->getCustomerInfo(),
            'add_item_to_cart'               => $this->addItemToCart($args),
            'bulk_add_to_cart'               => $this->bulkAddToCart($args),
            'remove_item_from_cart'          => $this->removeItemFromCart($args),
            'update_cart_quantity'           => $this->updateCartQuantity($args),
            'clear_cart'                     => $this->clearCart(),
            'get_featured_recipe'            => $this->getFeaturedRecipe(),
            'create_support_ticket'          => $this->createSupportTicket($args),
            'check_delivery_time'            => $this->checkDeliveryTime($args),
            'check_stock_availability'       => $this->checkStockAvailability($args),
            'get_trending_products'          => $this->decorateStock($this->getTrendingProducts($args)),
            'get_cart_contents'              => $this->getCartContents(),
            'apply_coupon'                   => $this->applyCoupon($args),
            'submit_product_review'          => $this->submitProductReview($args),
            'create_order_claim'             => $this->createOrderClaim($args),
            'get_personalized_recommendations' => $this->getPersonalizedRecommendations($args),
            'reorder_suggestions'            => $this->getReorderSuggestions($args),
            'seasonal_suggestions'           => $this->getSeasonalSuggestions($args),
            'frequently_bought_together'     => $this->getFrequentlyBoughtTogether($args),
            'get_context_summary'            => $this->getContextSummary($args),
            'record_conversation_summary'    => $this->recordSummary($args),
            'get_active_promotions'          => $this->getActivePromotions(),
            'hand_off_to_human'              => $this->handOffToHuman($args),
            'open_checkout'                  => $this->openCheckout($args),
            'open_login'                     => $this->openLogin($args),
            default                          => ['error' => "Unknown tool: {$functionName}"],
        };
    }

    private function getModel(string $configKey, string $default): ?object
    {
        $class = config("gunma-agent.models.{$configKey}", $default);
        if (!class_exists($class)) return null;
        return new $class();
    }

    private function getModelClass(string $configKey, string $default): ?string
    {
        $class = config("gunma-agent.models.{$configKey}", $default);
        if (!class_exists($class)) return null;
        return $class;
    }

    /* ── Existing Tools (improved) ─────────────────────────────── */

    private function cacheNewRecipe(array $args): array
    {
        $this->qdrantService->upsertRecipe($args);
        return ['status' => 'success', 'message' => 'Recipe cached for future users.'];
    }

    private function getOrderStatus(array $args): array
    {
        $identifier = $args['order_id_or_tracking'] ?? $args['order_id'] ?? null;
        $email = strtolower(trim((string) ($args['email'] ?? '')));
        $customer = auth('customer')->user();
        $orderModel = $this->getModelClass('order', \App\Models\Order::class);

        if (!$orderModel) return ['error' => 'Order lookup is not available.'];

        if (!$identifier && $customer) {
            // Fallback: the logged-in customer's latest REAL order. Failed or
            // pending-payment checkouts are not "orders" — skip them so we
            // never show an abandoned payment as the customer's latest order.
            $order = $orderModel::with(['orderItems', 'address', 'tracking'])
                ->where('customer_id', $customer->id)
                ->whereNotIn('status', self::NON_ORDER_STATUSES)
                ->latest('id')->first();
        } elseif ($identifier) {
            // PUBLIC: order number / tracking number is enough — no login needed.
            $order = $orderModel::with(['orderItems', 'address', 'tracking'])
                ->where(function ($q) use ($identifier) {
                    $q->where('id', $identifier)->orWhere('tracking_no', $identifier);
                })->first();
        } else {
            return ['error' => 'Please give me your order number (or tracking number).'];
        }

        if (!$order) {
            return ['error' => "এই order number টা পাইনি ভাই — order number/tracking number টা আবার ঠিক করে বলুন।"];
        }

        // Who is asking?
        $isOwner = $customer && (int) $order->customer_id === (int) $customer->id;
        $emailMatches = false;
        if (!$isOwner && $email !== '') {
            try {
                $customerModel = config('gunma-agent.models.customer');
                if ($customerModel && class_exists($customerModel)) {
                    $orderEmail = strtolower((string) ($customerModel::where('id', $order->customer_id)->value('email') ?? ''));
                    $emailMatches = $orderEmail !== '' && $orderEmail === $email;
                }
            } catch (\Throwable) {}
        }
        $verified = $isOwner || $emailMatches;

        $timeline = [];
        if (method_exists($order, 'trackingHistories') && $order->trackingHistories) {
            $timeline = $order->trackingHistories->map(fn($t) => [
                'status' => $t->status,
                'note' => $t->note,
                'date' => $t->created_at?->format('Y-m-d H:i'),
            ])->toArray();
        } elseif ($order->relationLoaded('tracking') && $order->tracking->isNotEmpty()) {
            $timeline = $order->tracking->map(fn($t) => [
                'status' => $t->status,
                'note' => $t->note,
                'date' => $t->created_at?->format('Y-m-d H:i'),
            ])->toArray();
        }

        $isCash = in_array($order->payment_method, ['Cash', 'COD'], true);
        $paymentNote = $isCash
            ? 'Cash on Delivery: payment is collected when the order is delivered. An "Unpaid" status or a due amount is normal and NOT a problem.'
            : 'Card/online payment.';

        $out = [
            'status' => 'success',
            'order_id' => $order->id,
            'tracking_no' => $order->tracking_no,
            'order_status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'is_cash_on_delivery' => $isCash,
            'payment_note' => $paymentNote,
            'total_amount' => (float) ($order->total_amount ?? 0),
            'due_amount' => (float) ($order->due_amount ?? 0),
            'delivery_date' => $order->delivary_date ? $order->delivary_date->format('Y-m-d') : null,
            'delivery_time' => $order->delivary_time ?? null,
            'is_pending' => in_array(strtolower((string) $order->status), ['pending', 'onhold', 'on hold', 'processing', 'pre-order'], true),
            'is_payment_failed' => in_array((string) $order->status, self::NON_ORDER_STATUSES, true),
            'verified_owner' => $verified,
            'items' => $order->orderItems->map(fn($item) => [
                'name' => $item->product_title,
                'quantity' => $item->quantity,
                'price' => (float) ($item->unit_price ?? 0),
            ])->toArray(),
            'timeline' => $timeline,
        ];

        // Address/phone are PII — only reveal to the verified owner / matching email.
        if ($verified && $order->address) {
            $address = $order->address;
            $out['delivery_address'] = [
                'name' => $address->shipping_name ?: $address->billing_name,
                'phone' => $address->shipping_phone ?: $address->billing_phone,
                'address' => trim(implode(' ', array_filter([
                    $address->shipping_apartment ?: $address->billing_apartment,
                    $address->shipping_street ?: $address->billing_street,
                ]))),
                'post_code' => $address->shipping_postal_code ?: $address->billing_postal_code,
                'city' => $address->shipping_city ?: $address->billing_city,
                'state' => $address->shipping_state ?: $address->billing_state,
            ];
        } else {
            $out['address_hidden'] = 'Delivery address/phone is private; it is shown only to the logged-in owner or when the order email matches.';
        }

        return $out;
    }

    /**
     * Change (reschedule) or cancel a PENDING order.
     * Requires the logged-in owner OR the email used on the order.
     */
    private function updatePendingOrder(array $args): array
    {
        $identifier = $args['order_id_or_tracking'] ?? $args['order_id'] ?? null;
        $email = strtolower(trim((string) ($args['email'] ?? '')));
        $action = strtolower(trim((string) ($args['action'] ?? 'change_date')));
        $newDate = $args['new_date'] ?? null;   // YYYY-MM-DD
        $newTime = $args['new_time'] ?? null;   // e.g. "09:00 - 12:00"

        if (!$identifier) return ['error' => 'Please give me the order number to change.'];

        $orderModel = $this->getModelClass('order', \App\Models\Order::class);
        if (!$orderModel) return ['error' => 'Order system unavailable.'];

        $order = $orderModel::where(function ($q) use ($identifier) {
            $q->where('id', $identifier)->orWhere('tracking_no', $identifier);
        })->first();

        if (!$order) return ['error' => 'এই order number টা পাইনি ভাই।'];

        // Only PENDING orders are changeable.
        $pendingStatuses = ['pending', 'onhold', 'on hold', 'processing', 'pre-order'];
        if (!in_array(strtolower((string) $order->status), $pendingStatuses, true)) {
            return ['error' => "এই order টার status এখন '{$order->status}' — Pending থাকা অবস্থাতেই change/cancel করা যায়। আমাদের team-কে support ticket করি?"];
        }

        // Identity: logged-in owner OR matching email.
        $customer = auth('customer')->user();
        $isOwner = $customer && (int) $order->customer_id === (int) $customer->id;
        if (!$isOwner) {
            if ($email === '') {
                return ['error' => 'নিরাপত্তার জন্য — Please log in, অথবা যে email দিয়ে order করেছেন সেটা বলুন।'];
            }
            $customerModel = config('gunma-agent.models.customer');
            $orderEmail = '';
            if ($customerModel && class_exists($customerModel)) {
                $orderEmail = strtolower((string) ($customerModel::where('id', $order->customer_id)->value('email') ?? ''));
            }
            if ($orderEmail === '' || $orderEmail !== $email) {
                return ['error' => 'এই email টা এই order-এর সাথে মিলছে না। আপনি login করে নিলে আমি সরাসরি change করে দিতে পারব।'];
            }
        }

        if ($action === 'cancel') {
            $order->update(['status' => 'Cancel']);
            return [
                'status' => 'success',
                'order_id' => $order->id,
                'new_status' => 'Cancel',
                'message' => "Order #{$order->id} cancel করা হয়েছে।",
            ];
        }

        // change_date / change_time
        $updates = [];
        if ($newDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $newDate)) {
            $updates['delivary_date'] = $newDate;
        }
        if ($newTime) {
            $updates['delivary_time'] = (string) $newTime;
        }
        if (empty($updates)) {
            return ['error' => 'কোন নতুন date/time পাইনি — কোন তারিখ বা সময়ে চান বলুন (যেমন 2026-10-05, 09:00 - 12:00)।'];
        }

        $order->update($updates);

        return [
            'status' => 'success',
            'order_id' => $order->id,
            'new_delivery_date' => $order->fresh()->delivary_date?->format('Y-m-d'),
            'new_delivery_time' => $order->fresh()->delivary_time,
            'message' => "Order #{$order->id} update করা হয়েছে।",
        ];
    }

    private function getOrderTracking(array $args): array
    {
        $trackingNo = $args['tracking_number'] ?? null;
        if (!$trackingNo) return ['error' => 'Please provide a tracking number.'];

        $orderModel = $this->getModelClass('order', \App\Models\Order::class);
        if (!$orderModel) return ['error' => 'Tracking is not available.'];

        $order = $orderModel::with(['tracking', 'address'])
            ->where('tracking_no', $trackingNo)
            ->first();

        if (!$order) return ['error' => 'Tracking number not found.'];

        $trackingHistory = [];
        if ($order->tracking && $order->tracking->isNotEmpty()) {
            $trackingHistory = $order->tracking->map(fn($single) => [
                'status' => $single->status ?? 'registered',
                'note' => $single->note ?? null,
                'date' => $single->created_at?->format('Y-m-d H:i'),
            ])->toArray();
        }

        return [
            'status' => 'success',
            'tracking_no' => $order->tracking_no,
            'order_status' => $order->status,
            'estimated_delivery' => $order->delivary_date?->format('Y-m-d'),
            'history' => $trackingHistory,
        ];
    }

    private function getCustomerInfo(): array
    {
        $customer = auth('customer')->user();
        if (!$customer) return ['error' => 'User is not logged in.'];

        $orderModel = $this->getModelClass('order', \App\Models\Order::class);
        $recentOrders = $orderModel
            ? $orderModel::where('customer_id', $customer->id)
                ->whereNotIn('status', self::NON_ORDER_STATUSES)
                ->latest()->take(5)->get()->map(fn($o) => [
                'id' => $o->id,
                'tracking_no' => $o->tracking_no,
                'status' => $o->status,
                'total_amount' => (float) $o->total_amount,
                'date' => $o->created_at->format('Y-m-d'),
                'items' => $o->orderItems->map(fn($i) => [
                    'product' => $i->product_title ?? $i->product?->title,
                    'quantity' => $i->quantity,
                ])->toArray(),
            ])->toArray()
            : [];

        // Purchase insights
        $insight = $this->insightService()->analyzeCustomer($customer->id);

        return [
            'status' => 'success',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone ?? null,
            'available_points' => (int) ($customer->available_point ?? 0),
            'wallet_amount' => (float) ($customer->amount ?? 0),
            'total_orders' => $insight['total_orders'] ?? 0,
            'avg_order_value' => $insight['avg_order_value'] ?? 0,
            'days_since_last_order' => $insight['days_since_last'] ?? 0,
            'top_categories' => $insight['top_categories'] ?? [],
            'frequent_items' => $insight['frequent_items'] ?? [],
            'recent_orders' => $recentOrders,
            'points_history' => method_exists($customer, 'pointHistories')
                ? $customer->pointHistories()->latest()->take(5)->get()->map(fn($p) => [
                    'points' => $p->point,
                    'type' => $p->type,
                    'description' => $p->description,
                    'date' => $p->created_at->format('Y-m-d'),
                ])->toArray()
                : [],
        ];
    }

    private function addItemToCart(array $args): array
    {
        $productId = $args['product_id'] ?? null;
        $quantity = max(1, (int) ($args['quantity'] ?? 1));
        if (!$productId) return ['error' => 'Please specify a product ID.'];

        $productModel = $this->getModelClass('product', \App\Models\Product::class);
        $cartModel = $this->getModelClass('cart', \App\Models\Cart::class);
        if (!$productModel) return ['error' => 'Product system unavailable.'];

        $product = $productModel::with('latestStock')->find($productId);
        if (!$product) return ['error' => 'Product not found.'];

        $stock = $product->latestStock;
        $availableQty = $stock ? (int) $stock->available_quantity : 0;
        if ($availableQty < $quantity) {
            return [
                'status' => 'error',
                'message' => $availableQty > 0
                    ? "Only {$availableQty} available (you requested {$quantity})."
                    : 'This product is currently out of stock.',
            ];
        }

        $customer = auth('customer')->user();

        // Guest checkout support: mirror the storefront's guest cart identity
        // (encrypted `guest_id` cookie → cookie_id) so AI cart adds really work
        // for guests — previously this silently did nothing for them.
        $identity = null;
        if ($customer) {
            $identity = ['customer_id' => $customer->id];
        } elseif ($cartModel) {
            $cookieId = $this->resolveGuestCookieId();
            if (! $cookieId) {
                return [
                    'status' => 'error',
                    'message' => 'Please log in to add items to your cart.',
                ];
            }
            $identity = ['cookie_id' => $cookieId];
        } else {
            $identity = null;
        }

        if ($identity && $cartModel) {
            $existing = $cartModel::where('product_id', $productId)
                ->where('product_option_id', '')
                ->where($identity)
                ->first();

            if ($existing) {
                $newQty = $existing->quantity + $quantity;
                if ($newQty > $availableQty) {
                    return ['error' => "Only {$availableQty} available. You already have {$existing->quantity} in cart."];
                }
                $existing->update(['quantity' => $newQty]);
            } else {
                $price = (float) ($stock?->online_price ?? 0);
                $discount = (float) ($product->discount->amount ?? 0);
                $taxPercent = (float) ($product->tax_percent ?? 8);
                $lineAmount = ($price - $discount) * $quantity;
                $totalTax = $lineAmount * ($taxPercent / 100);

                $cartModel::create(array_merge($identity, [
                    'product_id' => $productId,
                    'product_option_id' => '',
                    'quantity' => $quantity,
                    'weight' => (float) ($product->weight ?? 0),
                    'unit' => $product->unit ?? $stock?->unit,
                    'item_price' => $price,
                    'tax_percent' => $taxPercent,
                    'total_tax_amount' => $totalTax,
                    'total_discount_amount' => $discount * $quantity,
                    'total_amount' => $lineAmount,
                ]));
            }
        }

        return [
            'status' => 'success',
            'message' => "Added {$quantity}x {$product->title} to cart.",
            'action' => 'open_checkout',
            'url' => config('gunma-agent.website_url') . '/checkout',
        ];
    }

    private function bulkAddToCart(array $args): array
    {
        $productIds = $args['product_ids'] ?? [];
        if (empty($productIds)) return ['error' => 'Please provide product_ids array.'];

        $customer = auth('customer')->user();
        $productModel = $this->getModelClass('product', \App\Models\Product::class);
        $cartModel = $this->getModelClass('cart', \App\Models\Cart::class);
        if (!$productModel) return ['error' => 'Product system unavailable.'];

        // Guest support (same policy as add_item_to_cart).
        $identity = null;
        if ($customer) {
            $identity = ['customer_id' => $customer->id];
        } elseif ($cartModel) {
            $cookieId = $this->resolveGuestCookieId();
            if (! $cookieId) {
                return ['error' => 'Please log in to add items to your cart.'];
            }
            $identity = ['cookie_id' => $cookieId];
        }

        $added = [];
        $skipped = [];
        $errors = [];

        foreach ($productIds as $pid) {
            try {
                $product = $productModel::with('latestStock')->find($pid);
                if (!$product) {
                    $skipped[] = "Product #{$pid} not found";
                    continue;
                }

                $stock = $product->latestStock;
                $available = $stock ? (int) $stock->available_quantity : 0;
                if ($available < 1) {
                    $skipped[] = "{$product->title} is out of stock";
                    continue;
                }

                if ($identity && $cartModel) {
                    $existing = $cartModel::where('product_id', $pid)
                        ->where('product_option_id', '')
                        ->where($identity)
                        ->first();
                    if ($existing) {
                        if ($existing->quantity < $available) {
                            $existing->increment('quantity');
                            $added[] = "{$product->title} (qty: {$existing->quantity})";
                        } else {
                            $skipped[] = "{$product->title} already at max stock";
                        }
                    } else {
                        $price = (float) ($stock?->online_price ?? 0);
                        $discount = (float) ($product->discount->amount ?? 0);
                        $taxPercent = (float) ($product->tax_percent ?? 8);
                        $lineAmount = max(0, $price - $discount);
                        $totalTax = $lineAmount * ($taxPercent / 100);
                        $cartModel::create(array_merge($identity, [
                            'product_id' => $pid,
                            'product_option_id' => '',
                            'quantity' => 1,
                            'weight' => (float) ($product->weight ?? 0),
                            'unit' => $product->unit ?? $stock?->unit,
                            'item_price' => $price,
                            'tax_percent' => $taxPercent,
                            'total_tax_amount' => $totalTax,
                            'total_discount_amount' => $discount,
                            'total_amount' => $lineAmount,
                        ]));
                        $added[] = $product->title;
                    }
                } else {
                    $added[] = $product->title;
                }
            } catch (\Exception $e) {
                $errors[] = "Failed to add #{$pid}: {$e->getMessage()}";
            }
        }

        $msg = count($added) . ' items added to cart.';
        if (!empty($skipped)) $msg .= ' ' . count($skipped) . ' skipped.';
        if (!empty($errors)) $msg .= ' ' . count($errors) . ' errors.';

        return [
            'status' => 'success',
            'message' => $msg,
            'added' => $added,
            'skipped' => $skipped,
            'errors' => $errors,
            'action' => 'open_checkout',
            'cart_url' => config('gunma-agent.website_url') . '/checkout',
        ];
    }

    /**
     * Resolve the guest cart identity exactly like the storefront
     * (encrypted `guest_id` cookie → cookie_id). Prefers the pre-bootstrapped
     * GuestCartService (ChatController bootstraps it per request), falls back
     * to decrypting the cookie directly. Returns null when unavailable.
     */
    private function resolveGuestCookieId(): ?string
    {
        try {
            if (app()->bound(\Anwar\GunmaAgent\Services\GuestCartService::class)) {
                $svc = app(\Anwar\GunmaAgent\Services\GuestCartService::class);
                if ($svc->cookieId()) {
                    return $svc->cookieId();
                }
            }
        } catch (\Throwable) {
            // fall through to cookie decryption
        }

        if (! app()->bound('request')) {
            return null;
        }

        $cookie = \Illuminate\Support\Facades\Request::cookie('guest_id');
        if (! $cookie) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decrypt($cookie);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Shared cart identity for all cart tools: logged-in customer, else guest
     * cookie id. Returns null -> not identifiable (guest without cart session).
     */
    private function cartIdentity(): ?array
    {
        $customer = auth('customer')->user();
        if ($customer) {
            return ['customer_id' => $customer->id];
        }
        $cookieId = $this->resolveGuestCookieId();
        if (! $cookieId) {
            return null;
        }
        return ['cookie_id' => $cookieId];
    }

    private function getFeaturedRecipe(): array
    {
        // Try Qdrant first
        $results = $this->qdrantService->searchRecipes('halal', 5);
        if (!empty($results)) {
            return $results[array_rand($results)]['payload'];
        }

        // Fallback: curated Bengali recipes when Qdrant is empty
        $recipes = [
            [
                'title' => 'Classic Beef Biryani',
                'ingredients' => ['beef', 'rice', 'onion', 'garlic', 'ginger', 'turmeric', 'chili powder', 'cumin', 'coriander', 'biryani spice', 'ghee', 'saffron'],
                'instructions' => 'Marinate beef with spices for 2hrs. Fry onions until golden. Layer with parboiled rice. Cook on low heat for 45min.',
            ],
            [
                'title' => 'Mutton Rezala',
                'ingredients' => ['mutton', 'yogurt', 'onion', 'garlic', 'ginger', 'poppy seed paste', 'cashew paste', 'ghee', 'cardamom', 'cinnamon', 'rose water'],
                'instructions' => 'Slow-cook mutton with yogurt and spice paste for 1hr. Add ghee and rose water. Serve with naan or rice.',
            ],
            [
                'title' => 'Shorshe Ilish (Hilsa in Mustard)',
                'ingredients' => ['hilsa fish', 'mustard paste', 'turmeric', 'green chili', 'mustard oil', 'salt'],
                'instructions' => 'Marinate fish with turmeric and salt. Cook in mustard paste and oil for 10min. Garnish with green chili.',
            ],
            [
                'title' => 'Bangladeshi Beef Curry',
                'ingredients' => ['beef', 'onion', 'garlic', 'ginger', 'panch phoron', 'turmeric', 'chili powder', 'cumin', 'coriander', 'potato', 'oil', 'salt'],
                'instructions' => 'Fry whole spices in oil. Add onion paste and cook until golden. Add beef and spices, cook 20min. Add potatoes, cook until tender.',
            ],
            [
                'title' => 'Chicken Roast',
                'ingredients' => ['chicken', 'yogurt', 'onion', 'garlic', 'ginger', 'chili powder', 'turmeric', 'cumin', 'ghee', 'saffron', 'fried onion', 'salt'],
                'instructions' => 'Marinate chicken in yogurt and spices. Sear in ghee, add marinade and a little water, cover and cook until tender. Uncover and reduce the gravy until thick and glossy. Serve with polao.',
            ],
            [
                'title' => 'Beef Chaap (Eid Special)',
                'ingredients' => ['beef steak cut', 'yogurt', 'onion', 'garlic', 'ginger', 'poppy seed', 'coconut', 'ghee', 'cardamom', 'cinnamon', 'nutmeg', 'rose water'],
                'instructions' => 'Tenderize beef. Marinate in yogurt and spice paste overnight. Slow-fry in ghee until caramelized. A must for Eid!',
            ],
            [
                'title' => 'Daal (Bengali Lentils)',
                'ingredients' => ['masoor dal', 'onion', 'garlic', 'turmeric', 'cumin', 'ghee', 'green chili', 'salt'],
                'instructions' => 'Boil lentils with turmeric until soft. Temper with fried onion, garlic, and cumin in ghee. Daily comfort food.',
            ],
        ];

        return $recipes[array_rand($recipes)];
    }

    private function createSupportTicket(array $args): array
    {
        $customer = auth('customer')->user();

        if (($args['issue_type'] ?? '') === 'cancellation' && !empty($args['order_id'])) {
            $orderModel = $this->getModelClass('order', \App\Models\Order::class);
            if ($orderModel) {
                $order = $orderModel::find($args['order_id']);
                if ($order && in_array(strtolower((string)$order->status), ['delivered', 'shipped', 'on the way', 'completed', 'on-the-way'])) {
                    return [
                        'status' => 'error',
                        'message' => "This order is already '{$order->status}'. You can raise a return claim once received.",
                    ];
                }
            }
        }

        $ticket = \Anwar\GunmaAgent\Models\SupportTicket::create([
            'name'       => $customer->name ?? $args['name'] ?? 'Guest User',
            'email'      => $customer->email ?? $args['email'] ?? null,
            'phone'      => $customer->phone ?? $args['phone'] ?? null,
            'order_id'   => $args['order_id'] ?? null,
            'issue_type' => $args['issue_type'] ?? 'general',
            'subject'    => $args['issue_type'] === 'cancellation'
                ? "Cancellation Request for Order #{$args['order_id']}"
                : "Support: " . ($args['issue_type'] ?? 'General'),
            'message'    => $args['message'],
            'status'     => 'pending',
            'metadata'   => $args,
        ]);

        event(new \Anwar\GunmaAgent\Events\SupportTicketCreated($ticket, $args));

        return [
            'status' => 'success',
            'message' => 'Support ticket created. Our team will get back to you soon.',
            'ticket_id' => $ticket->id,
        ];
    }

    private function checkDeliveryTime(array $args): array
    {
        $postCode = $args['post_code'] ?? null;
        if (!$postCode) return ['error' => 'Please provide a post code.'];

        $postCodeModel = $this->getModelClass('post_code', \App\Models\PostCode::class);
        if (!$postCodeModel) return ['error' => 'Delivery check unavailable.'];

        $data = $postCodeModel::with(['schedules', 'city', 'state'])->where('code', $postCode)->first();
        if (!$data) return ['error' => 'Post code not found.'];

        return [
            'status' => 'success',
            'post_code' => $data->code,
            'city' => $data->city->name ?? null,
            'state' => $data->state->name ?? null,
            'delay_days' => (int) ($data->after_delay ?? 0),
            'schedules' => $data->schedules->pluck('schedule')->toArray(),
        ];
    }

    private function getTrendingProducts(array $args): array
    {
        $productModel = $this->getModelClass('product', \App\Models\Product::class);
        if (!$productModel) return [];

        return $productModel::where('status', 'Active')
            ->where('is_online_available', 'Yes')
            ->with(['latestStock', 'images'])
            ->latest()
            ->limit(min((int) ($args['limit'] ?? 5), 20))
            ->get()
            ->map(fn($p) => [
                'id'        => $p->id,
                'title'     => $p->title,
                'price'     => (float) ($p->latestStock?->online_price ?? 0),
                'image_url' => $p->images->first()?->image_path,
                'slug'      => $p->slug,
            ])->toArray();
    }

    /* ── New: Reorder Suggestions ──────────────────────────────── */

    private function getReorderSuggestions(array $args): array
    {
        $customer = auth('customer')->user();
        if (!$customer) return ['error' => 'Please log in to get personalized suggestions.'];

        $suggestions = $this->insightService()->getReorderSuggestions(
            $customer->id,
            (int) ($args['limit'] ?? 5)
        );

        if (empty($suggestions)) {
            return ['status' => 'success', 'message' => 'No reorder suggestions yet. The more you shop, the smarter I get!'];
        }

        return [
            'status' => 'success',
            'total_orders' => $this->insightService()->analyzeCustomer($customer->id)['total_orders'] ?? 0,
            'suggestions' => $suggestions,
        ];
    }

    /* ── New: Frequently Bought Together ───────────────────────── */

    private function getFrequentlyBoughtTogether(array $args): array
    {
        $customer = auth('customer')->user();
        $productId = $args['product_id'] ?? null;
        if (!$productId) return ['error' => 'Please provide a product_id.'];

        if ($customer) {
            $items = $this->insightService()->getFrequentlyBoughtTogether(
                $customer->id,
                (int) $productId,
                (int) ($args['limit'] ?? 5)
            );
            if (!empty($items)) {
                return ['status' => 'success', 'items' => $items, 'source' => 'personalized'];
            }
        }

        return ['status' => 'success', 'items' => [], 'message' => 'No related items found yet. Try browsing with filter_products instead.'];
    }

    /* ── New: Seasonal Suggestions ─────────────────────────────── */

    private function getSeasonalSuggestions(array $args): array
    {
        $customer = auth('customer')->user();
        $insight = $customer ? $this->insightService()->analyzeCustomer($customer->id) : null;

        $triggerService = app(ProactiveTriggerService::class);
        $triggers = $triggerService->getTriggers($insight);

        return [
            'status' => 'success',
            'season' => $triggers['season'],
            'time_period' => $triggers['time_period'],
            'day' => $triggers['day'] ?? null,
            'suggestions' => $triggers['seasonal_suggestions'] ?? [],
            'time_suggestions' => $triggers['time_suggestions'] ?? [],
            'day_suggestions' => $triggers['day_suggestions'] ?? [],
            'ramadan_coming' => $triggers['ramadan_coming'] ?? false,
            'eid_coming' => $triggers['eid_coming'] ?? false,
            'reorder_alerts' => $triggers['reorder_alerts'] ?? [],
        ];
    }

    /* ── New: Conversation Context Summary ─────────────────────── */

    private function getContextSummary(array $args): array
    {
        $sessionId = $args['session_id'] ?? null;
        if (!$sessionId) return ['error' => 'No session ID provided.'];

        $summary = DB::table('conversation_summaries')
            ->where('session_id', $sessionId)
            ->latest()
            ->first();

        if (!$summary) {
            // Try latest summary for this session
            $lastSession = DB::table('conversation_summaries')
                ->where('session_id', '!=', $sessionId)
                ->where('customer_id', $args['customer_id'] ?? 0)
                ->latest()
                ->first();

            if (!$lastSession) return ['status' => 'success', 'summary' => null, 'message' => 'No previous context.'];

            return [
                'status' => 'success',
                'summary' => $lastSession->summary,
                'key_topics' => json_decode($lastSession->key_topics ?? '[]', true),
                'sentiment' => $lastSession->sentiment,
                'follow_up_needed' => (bool) $lastSession->follow_up_needed,
            ];
        }

        return [
            'status' => 'success',
            'summary' => $summary->summary,
            'key_topics' => json_decode($summary->key_topics ?? '[]', true),
            'sentiment' => $summary->sentiment,
            'follow_up_needed' => (bool) $summary->follow_up_needed,
        ];
    }

    /* ── New: Record Conversation Summary ──────────────────────── */

    private function recordSummary(array $args): array
    {
        $sessionId = $args['session_id'] ?? null;
        $customerId = $args['customer_id'] ?? null;
        $summary = $args['summary'] ?? '';
        $topics = $args['key_topics'] ?? [];
        $sentiment = $args['sentiment'] ?? 'neutral';

        if (!$sessionId || !$summary) return ['error' => 'Missing session_id or summary.'];

        DB::table('conversation_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'session_id' => $sessionId,
            'customer_id' => $customerId,
            'summary' => $summary,
            'key_topics' => json_encode($topics),
            'sentiment' => $sentiment,
            'follow_up_needed' => $args['follow_up_needed'] ?? false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['status' => 'success', 'message' => 'Conversation summary stored.'];
    }

    private function getCartContents(): array
    {
        $customer = auth('customer')->user();

        $cartModel = $this->getModelClass('cart', \App\Models\Cart::class);
        if (!$cartModel) return ['error' => 'Cart system unavailable.'];

        // Guests read their own cart via the storefront-style guest cookie.
        $query = $cartModel::with('product');
        if ($customer) {
            $query->where('customer_id', $customer->id);
        } else {
            $cookieId = $this->resolveGuestCookieId();
            if (! $cookieId) {
                return ['error' => 'Your cart is empty. Add something you like!'];
            }
            $query->where('cookie_id', $cookieId);
        }

        $items = $query->get();

        $subtotal = 0.0;
        $tax = 0.0;
        $mapped = $items->map(function ($item) use (&$subtotal, &$tax) {
            $lineNet = (float) $item->total_amount;
            $lineTax = (float) ($item->total_tax_amount ?? 0);
            $subtotal += $lineNet;
            $tax += $lineTax;
            return [
                'product_id' => $item->product_id,
                'name'       => $item->product->title ?? 'Unknown',
                'quantity'   => $item->quantity,
                'price'      => (float) $item->item_price,
                'line_total' => round($lineNet, 2),
                'line_tax'   => round($lineTax, 2),
            ];
        })->toArray();

        // Cart figures EXCLUDE shipping. The checkout adds 8% tax (already in
        // total_tax_amount) and a shipping charge, so quote the customer the
        // full amount when relevant.
        $totalWithTax = round($subtotal + $tax, 2);

        return [
            'status'      => 'success',
            'items'       => $mapped,
            'total_items' => count($mapped),
            'subtotal'    => round($subtotal, 2),
            'tax'         => round($tax, 2),
            'total_with_tax' => $totalWithTax,
            'note'        => 'Prices exclude shipping. Checkout adds shipping (¥0 for orders ¥10,000+ outside Okinawa, otherwise ¥1,200). Quote total_with_tax (plus shipping) as the payable amount.',
        ];
    }

    /**
     * Stamp every product hit with a live, DB-verified in_stock flag so the
     * model can never accidentally present an out-of-stock item as buyable.
     * Also annotates the hit title with "(out of stock)" for extra safety.
     */
    private function decorateStock(mixed $result): mixed
    {
        try {
            if (is_array($result)) {
                $this->sniffAndStamp($result);
            }
        } catch (\Throwable) {
            // Best-effort decoration only — never break the tool.
        }
        return $result;
    }

    private function sniffAndStamp(array &$array, int $depth = 0): void
    {
        if ($depth > 4) {
            return;
        }
        $productIds = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (isset($value['product_id'])) {
                    $productIds[$key] = (int) $value['product_id'];
                } elseif (isset($value['id']) && is_numeric($value['id']) && isset($value['title'])) {
                    $productIds[$key] = (int) $value['id'];
                }
            }
        }
        if ($productIds) {
            $cartModel = $this->getModelClass('product', \App\Models\Product::class);
            if ($cartModel && class_exists($cartModel)) {
                $rows = $cartModel::with('latestStock')
                    ->whereIn('id', array_values(array_unique($productIds)))
                    ->get()
                    ->keyBy('id');
                foreach ($productIds as $key => $pid) {
                    $p = $rows[$pid] ?? null;
                    $stock = $p?->latestStock?->available_quantity ?? 0;
                    $inStock = (int) $stock > 0
                        && ($p->status ?? '') === 'Active'
                        && ($p->is_online_available ?? 'Yes') === 'Yes';
                    $array[$key]['in_stock'] = $inStock;
                    $array[$key]['stock'] = (int) $stock;
                    if (! $inStock && isset($array[$key]['title']) && strpos((string) $array[$key]['title'], '(out of stock)') === false
                        && strpos((string) $array[$key]['title'], '(not in stock') === false) {
                        $array[$key]['title'] = (string) $array[$key]['title'] . ' (out of stock)';
                    }
                }
            }
        }
        foreach ($array as $key => $value) {
            if (is_array($value) && isset($value['results']) || (is_array($value) && array_is_list($array))) {
                // recurse into grouped structures (search_products_bulk groups)
            }
            if (is_array($value)) {
                $this->sniffAndStamp($array[$key], $depth + 1);
            }
        }
    }

    /* ── Cart management tools (works for customers AND guests) ── */

    private function removeItemFromCart(array $args): array
    {
        $productId = $args['product_id'] ?? null;
        if (!$productId) return ['error' => 'Please specify a product ID.'];

        $cartModel = $this->getModelClass('cart', \App\Models\Cart::class);
        if (!$cartModel) return ['error' => 'Cart system unavailable.'];

        $identity = $this->cartIdentity();
        if (!$identity) return ['error' => 'Your cart is empty.'];

        $item = $cartModel::where('product_id', $productId)
            ->where($identity)
            ->first();

        if (!$item) return ['error' => 'That item is not in your cart.'];

        $title = $item->product->title ?? ("#" . $productId);
        // Delete every variant row of this product for this owner
        // (option rows share the product_id; an item can be stored multiple times).
        $cartModel::where('product_id', $productId)
            ->where($identity)
            ->delete();

        return ['status' => 'success', 'message' => "Removed {$title} (all variants) from your cart."];
    }

    private function updateCartQuantity(array $args): array
    {
        $productId = $args['product_id'] ?? null;
        $quantity = (int) ($args['quantity'] ?? -1);
        if (!$productId || $quantity < 0 || $quantity > 99) {
            return ['error' => 'Please specify a product ID and a quantity between 0 and 99.'];
        }

        $cartModel = $this->getModelClass('cart', \App\Models\Cart::class);
        if (!$cartModel) return ['error' => 'Cart system unavailable.'];

        $identity = $this->cartIdentity();
        if (!$identity) return ['error' => 'Your cart is empty.'];

        $item = $cartModel::where('product_id', $productId)
            ->where($identity)
            ->orderBy('id')
            ->first();

        if (!$item) return ['error' => 'That item is not in your cart.'];

        if ($quantity === 0) {
            $title = $item->product->title ?? ("#" . $productId);
            $item->delete();
            return ['status' => 'success', 'message' => "Removed {$title} from your cart."];
        }

        $stock = $item->product->latestStock ?? null;
        $availableQty = $stock ? (int) $stock->available_quantity : 0;
        if ($availableQty && $quantity > $availableQty) {
            return ['status' => 'error', 'message' => "Only {$availableQty} available for that item."];
        }

        $item->update([
            'quantity' => $quantity,
            'total_amount' => $item->total_amount > 0 && $item->quantity > 0
                ? round(($item->total_amount / $item->quantity) * $quantity, 2)
                : $item->total_amount,
            'total_tax_amount' => $item->total_tax_amount > 0 && $item->quantity > 0
                ? round(($item->total_tax_amount / $item->quantity) * $quantity, 2)
                : $item->total_tax_amount,
        ]);

        $title = $item->product->title ?? ("#" . $productId);
        return ['status' => 'success', 'message' => "Updated {$title} to {$quantity}."];
    }

    private function clearCart(): array
    {
        $cartModel = $this->getModelClass('cart', \App\Models\Cart::class);
        if (!$cartModel) return ['error' => 'Cart system unavailable.'];

        $identity = $this->cartIdentity();
        if (!$identity) return ['error' => 'Your cart is already empty.'];

        $deleted = $cartModel::where($identity)->delete();

        return [
            'status' => 'success',
            'message' => $deleted
                ? "Cleared {$deleted} items from your cart."
                : 'Your cart is already empty.',
        ];
    }

    private function getActivePromotions(): array
    {
        $couponModel = config('gunma-agent.models.coupon', \App\Models\Coupon::class);
        $promotions = [];

        if (class_exists($couponModel)) {
            try {
                $now = now();
                $promotions = $couponModel::where('status', 'Active')
                    ->where(function ($q) use ($now) {
                        $q->whereNull('start_date')->orWhere('start_date', '<=', $now);
                    })
                    ->where(function ($q) use ($now) {
                        $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
                    })
                    ->limit(10)
                    ->get()
                    ->map(function ($c) {
                        $desc = [];
                        if ((float) $c->discount_parcent > 0) $desc[] = ((float) $c->discount_parcent) . '% off';
                        if ((float) $c->discount_amount > 0) $desc[] = '¥' . number_format((float) $c->discount_amount) . ' off';
                        if ((float) $c->min_buying_amount > 0) $desc[] = 'min ¥' . number_format((float) $c->min_buying_amount);
                        return [
                            'title' => $c->title,
                            'code' => $c->code,
                            'description' => implode(', ', $desc) ?: 'Active coupon',
                        ];
                    })
                    ->toArray();
            } catch (\Exception $e) {
                Log::warning('[ToolExecutor] Coupon fetch failed', ['error' => $e->getMessage()]);
            }
        }

        return ['status' => 'success', 'promotions' => $promotions];
    }

    private function handOffToHuman(array $args): array
    {
        $sessionId = $args['session_id'] ?? null;
        if ($sessionId) {
            $session = \Anwar\GunmaAgent\Models\ChatSession::find($sessionId);
            if ($session) $session->update(['is_ai_enabled' => false]);
        }
        return ['status' => 'success', 'message' => 'A human agent will take over shortly.'];
    }

    /**
     * Open the in-chat checkout panel. The widget listens for the
     * "open_checkout" action and renders the cart → address → delivery →
     * payment flow inside the chat (no page navigation).
     */
    private function openCheckout(array $args): array
    {
        return [
            'status'  => 'success',
            'message' => 'Opening checkout inside the chat now.',
            'action'  => 'open_checkout',
            'url'     => config('gunma-agent.website_url') . '/checkout',
        ];
    }

    /**
     * Open the in-chat login/registration panel.
     */
    private function openLogin(array $args): array
    {
        return [
            'status'  => 'success',
            'message' => 'Opening the login form inside the chat.',
            'action'  => 'open_login',
            'url'     => config('gunma-agent.website_url') . '/login',
        ];
    }

    private function createOrderClaim(array $args): array
    {
        $customer = auth('customer')->user();
        $sessionId = request()->header('X-Chat-Session-Id');

        $ticket = \Anwar\GunmaAgent\Models\SupportTicket::create([
            'session_id'  => $sessionId,
            'customer_id' => $customer->id ?? null,
            'name'        => $customer->name ?? 'Guest',
            'email'       => $customer->email ?? null,
            'order_id'    => $args['order_id'],
            'issue_type'  => $args['issue_type'] ?? 'claim',
            'subject'     => "Claim: {$args['issue_type']} for Order #{$args['order_id']}",
            'message'     => "Products: " . ($args['product_details'] ?? 'N/A') . "\n" . ($args['message'] ?? ''),
            'status'      => 'pending',
            'metadata'    => $args,
        ]);

        event(new \Anwar\GunmaAgent\Events\SupportTicketCreated($ticket, $args));

        return [
            'status' => 'success',
            'message' => 'Claim registered. Claim ID: ' . $ticket->id,
            'claim_id' => $ticket->id,
        ];
    }

    private function getPersonalizedRecommendations(array $args): array
    {
        $customer = auth('customer')->user();
        if (!$customer) return $this->getTrendingProducts($args);

        $results = $this->qdrantService->searchPersonalizedProducts($customer->id, (int) ($args['limit'] ?? 5));
        return array_map(fn($hit) => [
            'id'        => $hit['payload']['id'] ?? null,
            'title'     => $hit['payload']['title'] ?? $hit['payload']['name'] ?? 'Unknown',
            'price'     => $hit['payload']['price'] ?? null,
            'image_url' => $hit['payload']['image_url'] ?? null,
            'slug'      => $hit['payload']['slug'] ?? null,
        ], $results);
    }

    /* ── New Tools: Product Details ────────────────────────────── */

    private function getProductDetails(array $args): array
    {
        $productId = $args['product_id'] ?? null;
        $slug = $args['slug'] ?? null;
        if (!$productId && !$slug) return ['error' => 'Please provide a product_id or slug.'];

        $productModel = $this->getModelClass('product', \App\Models\Product::class);
        if (!$productModel) return ['error' => 'Product system unavailable.'];

        $query = $productModel::with(['latestStock', 'images', 'categories', 'discount']);
        if ($productId) $query->where('id', $productId);
        else $query->where('slug', $slug);

        $product = $query->first();
        if (!$product) return ['error' => 'Product not found.'];

        $discount = $product->discount;
        $discountAmount = 0.0;
        if ($discount) {
            $discountAmount = ($discount->type ?? '') === 'percent'
                ? (float) ($discount->percent ?? 0)
                : (float) ($discount->amount ?? 0);
        }

        return [
            'status' => 'success',
            'product' => [
                'id'           => $product->id,
                'title'        => $product->title,
                'slug'         => $product->slug,
                'description'  => $product->description,
                'short_description' => $product->short_description,
                'price'        => (float) ($product->latestStock?->online_price ?? 0),
                'discount'     => $discountAmount,
                'stock'        => (int) ($product->latestStock?->available_quantity ?? 0),
                'unit'         => $product->latestStock?->unit ?? $product->unit,
                'images'       => $product->images->pluck('image_path')->filter()->values()->toArray(),
                'categories'   => $product->categories->pluck('title')->toArray(),
                'brand'        => optional($product->brand)->title ?? optional($product->brand)->name,
                'status'       => $product->status,
                'is_online'    => (bool) $product->is_online_available,
            ],
        ];
    }

    /* ── New Tools: Filter Products ────────────────────────────── */

    private function filterProducts(array $args): array
    {
        $productModel = $this->getModelClass('product', \App\Models\Product::class);
        if (!$productModel) return ['error' => 'Product system unavailable.'];

        $query = $productModel::where('status', 'Active')
            ->where('is_online_available', 'Yes')
            ->with(['latestStock', 'images', 'categories']);

        // Category filter
        if (!empty($args['category'])) {
            $query->whereHas('categories', fn($q) => $q->where('title', 'LIKE', "%{$args['category']}%"));
        }

        // Price range
        if (isset($args['min_price'])) $query->whereHas('latestStock', fn($q) => $q->where('online_price', '>=', (float) $args['min_price']));
        if (isset($args['max_price'])) $query->whereHas('latestStock', fn($q) => $q->where('online_price', '<=', (float) $args['max_price']));

        // Search text
        if (!empty($args['search'])) {
            $s = $args['search'];
            $query->where(function($q) use ($s) {
                $q->where('title', 'LIKE', "%{$s}%")
                  ->orWhere('short_description', 'LIKE', "%{$s}%");
            });
        }

        $limit = min((int) ($args['limit'] ?? 10), 30);
        $sort = $args['sort'] ?? 'latest';

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $direction = $sort === 'price_asc' ? 'asc' : 'desc';
            $query->orderBy(
                $productModel::select('online_price')
                    ->from('stocks')
                    ->whereColumn('stocks.product_id', 'products.id')
                    ->latest('id')
                    ->limit(1),
                $direction
            );
        } else {
            $query->latest();
        }

        $products = $query->limit($limit)->get();

        return [
            'status' => 'success',
            'total' => $products->count(),
            'products' => $products->map(fn($p) => [
                'id'     => $p->id,
                'title'  => $p->title,
                'slug'   => $p->slug,
                'price'  => (float) ($p->latestStock?->online_price ?? 0),
                'image'  => $p->images->first()?->image_path,
                'stock'  => (int) ($p->latestStock?->available_quantity ?? 0),
            ])->toArray(),
        ];
    }

    /* ── New Tools: Stock Availability ─────────────────────────── */

    private function checkStockAvailability(array $args): array
    {
        $productId = $args['product_id'] ?? null;
        $postCode = $args['post_code'] ?? null;
        if (!$productId) return ['error' => 'Please provide a product_id.'];

        $productModel = $this->getModelClass('product', \App\Models\Product::class);
        if (!$productModel) return ['error' => 'Product system unavailable.'];

        $product = $productModel::with('latestStock')->find($productId);
        if (!$product) return ['error' => 'Product not found.'];

        $stock = $product->latestStock;
        $availableQty = $stock ? (int) $stock->available_quantity : 0;

        $deliverable = true;
        $deliveryDelay = 0;
        $deliverySchedules = [];

        if ($postCode) {
            $postCodeModel = $this->getModelClass('post_code', \App\Models\PostCode::class);
            if ($postCodeModel) {
                $area = $postCodeModel::with('schedules')->where('code', $postCode)->first();
                if ($area) {
                    $deliveryDelay = (int) ($area->after_delay ?? 0);
                    $deliverySchedules = $area->schedules->pluck('schedule')->toArray();
                } else {
                    $deliverable = false;
                }
            }
        }

        return [
            'status' => 'success',
            'product_id' => $productId,
            'title' => $product->title,
            'available_quantity' => $availableQty,
            'in_stock' => $availableQty > 0,
            'deliverable' => $deliverable,
            'delivery_delay_days' => $deliveryDelay,
            'delivery_schedules' => $deliverySchedules,
        ];
    }

    /* ── New Tools: Apply Coupon ───────────────────────────────── */

    private function applyCoupon(array $args): array
    {
        $code = strtoupper(trim($args['code'] ?? ''));
        $cartTotal = (float) ($args['cart_total'] ?? 0);

        if (!$code) return ['error' => 'Please provide a coupon code.'];

        $couponModel = config('gunma-agent.models.coupon', \App\Models\Coupon::class);
        if (!class_exists($couponModel)) {
            return ['error' => 'Coupons are not available right now.'];
        }

        // Query the host coupons table (active + within validity window).
        $now = now();
        $coupon = $couponModel::whereRaw('UPPER(code) = ?', [$code])
            ->where('status', 'Active')
            ->where(function ($q) use ($now) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
            })
            ->first();

        if (!$coupon) return ['error' => 'Invalid or expired coupon code.'];

        $min = (float) ($coupon->min_buying_amount ?? 0);
        if ($cartTotal > 0 && $cartTotal < $min) {
            return ['error' => 'Minimum order of ¥' . number_format($min) . ' required for this coupon.'];
        }

        $percent = (float) ($coupon->discount_parcent ?? 0);
        $flat = (float) ($coupon->discount_amount ?? 0);

        $discount = 0.0;
        $description = $coupon->title ?: $code;
        if ($percent > 0) {
            $discount = $cartTotal * ($percent / 100);
            $description .= " ({$percent}% off)";
        } elseif ($flat > 0) {
            $discount = $flat;
            $description .= ' (¥' . number_format($flat) . ' off)';
        }

        return [
            'status' => 'success',
            'code' => $coupon->code,
            'description' => $description,
            'discount_amount' => round($discount, 2),
            'new_total' => $cartTotal > 0 ? round(max(0, $cartTotal - $discount), 2) : null,
        ];
    }

    /* ── New Tools: Submit Product Review ──────────────────────── */

    private function submitProductReview(array $args): array
    {
        $productId = $args['product_id'] ?? null;
        $rating = (int) ($args['rating'] ?? 0);
        $comment = $args['comment'] ?? '';

        if (!$productId) return ['error' => 'Please provide a product_id.'];
        if ($rating < 1 || $rating > 5) return ['error' => 'Rating must be between 1 and 5.'];

        $customer = auth('customer')->user();
        $productModel = $this->getModelClass('product', \App\Models\Product::class);
        if (!$productModel) return ['error' => 'Product system unavailable.'];

        $product = $productModel::find($productId);
        if (!$product) return ['error' => 'Product not found.'];

        // Check if review model exists (try common table names)
        $reviewSaved = false;
        $reviewModel = config('gunma-agent.models.review', \App\Models\Review::class);
        if (class_exists($reviewModel)) {
            $reviewModel::updateOrCreate(
                ['product_id' => $productId, 'customer_id' => $customer?->id ?? 0],
                ['rating' => $rating, 'comment' => $comment, 'status' => 'pending']
            );
            $reviewSaved = true;
        }

        return [
            'status' => 'success',
            'message' => $reviewSaved
                ? 'Thank you! Your review has been submitted and is pending approval.'
                : 'Thank you for your feedback!',
            'product_id' => $productId,
            'rating' => $rating,
        ];
    }

    /* ── Tool Definitions for OpenAI ───────────────────────────── */

    public static function getToolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'cache_new_recipe',
                    'description' => 'After YOU compose a recipe yourself (when stored recipes had no match), save it for other customers.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'ingredients' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'instructions' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'ingredients', 'instructions'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_products_bulk',
                    'description' => 'Search for halal products by name or ingredient. Returns price, stock, image.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'queries' => [
                                'type' => 'array', 'items' => ['type' => 'string'],
                                'description' => 'List of product names or ingredients to search for.',
                            ],
                        ],
                        'required' => ['queries'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_product_details',
                    'description' => 'Get full details for a product: description, price, stock, images, categories, nutrition.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer', 'description' => 'The product ID.'],
                            'slug' => ['type' => 'string', 'description' => 'The product slug.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'filter_products',
                    'description' => 'Browse/filter products by category, price range, or search text. Use when user wants to explore products.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'Category name to filter by.'],
                            'min_price' => ['type' => 'number', 'description' => 'Minimum price.'],
                            'max_price' => ['type' => 'number', 'description' => 'Maximum price.'],
                            'search' => ['type' => 'string', 'description' => 'Text to search in product name/description.'],
                            'sort' => ['type' => 'string', 'enum' => ['latest', 'price_asc', 'price_desc'], 'default' => 'latest'],
                            'limit' => ['type' => 'integer', 'default' => 10],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_recipes',
                    'description' => 'Find halal recipe ideas and cooking instructions.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Dish or ingredient to find a recipe for.'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_support_kb',
                    'description' => 'Search support knowledge base for shipping, delivery, payments, orders.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'The support question.'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
                        [
                'type' => 'function',
                'function' => [
                    'name' => 'get_order_status',
                    'description' => 'Get delivery status for an order. The customer ONLY needs to give the order number or tracking number — NO login, email or phone required. Use this whenever someone asks "amar order kothay" / order status.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id_or_tracking' => [
                                'type' => 'string',
                                'description' => 'Order ID or tracking number.',
                            ],
                            'email' => [
                                'type' => 'string',
                                'description' => 'Optional. If given and it matches the order email, private info like the delivery address is shown.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_pending_order',
                    'description' => 'Reschedule (change delivery date/time) or cancel a PENDING order. Requires the customer to be logged in OR to provide the email used on the order. Refuse politely if the order is not Pending.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id_or_tracking' => ['type' => 'string', 'description' => 'Order ID or tracking number.'],
                            'action' => ['type' => 'string', 'enum' => ['change_date', 'cancel'], 'description' => 'What to do. Default change_date.'],
                            'new_date' => ['type' => 'string', 'description' => 'New delivery date YYYY-MM-DD (for change_date).'],
                            'new_time' => ['type' => 'string', 'description' => 'New delivery time window e.g. "09:00 - 12:00" (optional).'],
                            'email' => ['type' => 'string', 'description' => 'Email on the order (required only if not logged in).'],
                        ],
                        'required' => ['order_id_or_tracking'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_order_tracking',
                    'description' => 'Get real-time tracking updates for a delivery by tracking number.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'tracking_number' => ['type' => 'string', 'description' => 'The tracking number.'],
                        ],
                        'required' => ['tracking_number'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_customer_info',
                    'description' => 'Get the logged-in user profile: name, email, phone, points, wallet, order history.',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'add_item_to_cart',
                    'description' => 'Add a single product to the user cart. Checks stock and existing items before adding.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer', 'description' => 'Product ID to add.'],
                            'quantity' => ['type' => 'integer', 'description' => 'Quantity (default 1).'],
                        ],
                        'required' => ['product_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'bulk_add_to_cart',
                    'description' => 'Add multiple products to cart at once. Use when user says "add all", "add everything", or wants to add multiple items.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                                'description' => 'Array of product IDs to add to cart.',
                            ],
                        ],
                        'required' => ['product_ids'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_cart_contents',
                    'description' => 'Get items currently in the user cart. Use before suggesting products.',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'remove_item_from_cart',
                    'description' => 'Remove one item from the user cart when they say "add ta remove/delete koro", "bad diye dao", "remove item".',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer', 'description' => 'Product ID to remove.'],
                        ],
                        'required' => ['product_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_cart_quantity',
                    'description' => 'Change the quantity of an item in the cart ("2kg koro", "1 ta kore dio"). Quantity 0 removes the item.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'integer', 'description' => 'New quantity (0 = remove).'],
                        ],
                        'required' => ['product_id', 'quantity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'clear_cart',
                    'description' => 'Empty the user cart entirely when they say "cart khali koro", "clear cart", "sob remove koro".',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_support_ticket',
                    'description' => 'Create a support ticket for payment issues, complaints, cancellations, or messages.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'description' => 'Summary of the issue.'],
                            'issue_type' => [
                                'type' => 'string', 'enum' => ['payment', 'delivery', 'quality', 'feedback', 'cancellation', 'product_missing', 'product_damage', 'extra_item', 'other'],
                            ],
                            'order_id' => ['type' => 'string', 'description' => 'Related order ID.'],
                            'product_details' => ['type' => 'string'],
                            'name' => ['type' => 'string', 'description' => 'Name if guest.'],
                            'email' => ['type' => 'string', 'description' => 'Email if guest.'],
                        ],
                        'required' => ['message', 'issue_type'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'check_delivery_time',
                    'description' => 'Check delivery schedules and estimated time for a post code.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'post_code' => ['type' => 'string', 'description' => 'Post code (e.g., 270-0021).'],
                        ],
                        'required' => ['post_code'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'check_stock_availability',
                    'description' => 'Check if a product is in stock and can be delivered to a post code.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer', 'description' => 'Product ID.'],
                            'post_code' => ['type' => 'string', 'description' => 'Delivery post code (optional).'],
                        ],
                        'required' => ['product_id'],
                    ],
                ],
            ],
                        [
                'type' => 'function',
                'function' => [
                    'name' => 'submit_product_review',
                    'description' => 'Submit a rating and review for a product.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer', 'description' => 'Product ID.'],
                            'rating' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5], 'description' => 'Rating 1-5.'],
                            'comment' => ['type' => 'string', 'description' => 'Review text.'],
                        ],
                        'required' => ['product_id', 'rating'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_trending_products',
                    'description' => 'Get the latest/popular products for guests.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'default' => 5],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_personalized_recommendations',
                    'description' => 'Get personalized product recommendations based on purchase history.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'default' => 5],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_order_claim',
                    'description' => 'Register a claim for missing, damaged, or extra items in an order.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id' => ['type' => 'string', 'description' => 'Order ID.'],
                            'issue_type' => ['type' => 'string', 'enum' => ['product_missing', 'product_damage', 'extra_item']],
                            'product_details' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ],
                        'required' => ['order_id', 'issue_type', 'product_details', 'message'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_active_promotions',
                    'description' => 'Check current store discounts, coupons, and special deals.',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'reorder_suggestions',
                    'description' => 'Check if the logged-in customer likely needs to restock frequently bought items. Uses order history to predict when staples are running low.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'description' => 'Max suggestions (default 5).'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'frequently_bought_together',
                    'description' => 'Find products the customer commonly buys together with a given product.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer', 'description' => 'The product ID.'],
                            'limit' => ['type' => 'integer', 'description' => 'Max suggestions (default 5).'],
                        ],
                        'required' => ['product_id'],
                    ],
                ],
            ],
                                    [
                'type' => 'function',
                'function' => [
                    'name' => 'hand_off_to_human',
                    'description' => 'Transfer conversation to a human agent.',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'open_checkout',
                    'description' => 'Open the checkout panel INSIDE the chat so the customer can review their cart, choose address, delivery date/time, apply coins, and pay (Cash or card) without leaving the chat. Call this whenever the customer says they want to checkout/order/pay, e.g. "checkout koro", "order korte chai", "pay korte chai". Do NOT give a link — just call this tool.',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'open_login',
                    'description' => 'Open the login / registration form INSIDE the chat so the customer can log in or create an account without leaving the chat. Call this when the customer wants to log in, sign in, or register, or when login is required before checkout.',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
                ],
            ],
        ];
    }
}
