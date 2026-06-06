<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Log;

class ToolExecutor
{
    public function __construct(
        private readonly QdrantService $qdrantService,
    ) {}

    public function execute(string $functionName, array $args): mixed
    {
        Log::info("[ToolExecutor] {$functionName}", $args);

        return match ($functionName) {
            'search_products_bulk'           => $this->qdrantService->searchProductsBulk($args['queries'] ?? []),
            'get_product_details'            => $this->getProductDetails($args),
            'filter_products'                => $this->filterProducts($args),
            'search_recipes'                 => $this->qdrantService->searchRecipes($args['query'] ?? ''),
            'search_support_kb'              => $this->qdrantService->searchSupportKB($args['query'] ?? ''),
            'cache_new_recipe'               => $this->cacheNewRecipe($args),
            'get_order_status'               => $this->getOrderStatus($args),
            'get_order_tracking'             => $this->getOrderTracking($args),
            'get_customer_info'              => $this->getCustomerInfo(),
            'add_item_to_cart'               => $this->addItemToCart($args),
            'get_featured_recipe'            => $this->getFeaturedRecipe(),
            'create_support_ticket'          => $this->createSupportTicket($args),
            'check_delivery_time'            => $this->checkDeliveryTime($args),
            'check_stock_availability'       => $this->checkStockAvailability($args),
            'get_trending_products'          => $this->getTrendingProducts($args),
            'get_cart_contents'              => $this->getCartContents(),
            'apply_coupon'                   => $this->applyCoupon($args),
            'submit_product_review'          => $this->submitProductReview($args),
            'create_order_claim'             => $this->createOrderClaim($args),
            'get_personalized_recommendations' => $this->getPersonalizedRecommendations($args),
            'get_active_promotions'          => $this->getActivePromotions(),
            'hand_off_to_human'              => $this->handOffToHuman($args),
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
        $identifier = $args['order_id_or_tracking'] ?? null;
        $customer = auth('customer')->user();
        $orderModel = $this->getModelClass('order', \App\Models\Order::class);

        if (!$orderModel) return ['error' => 'Order lookup is not available.'];

        $query = $orderModel::with(['orderItems', 'address', 'tracking', 'payments']);

        if ($identifier) {
            $query->where(function($q) use ($identifier) {
                $q->where('id', $identifier)->orWhere('tracking_no', $identifier);
            });
            if ($customer) $query->where('customer_id', $customer->id);
        } elseif ($customer) {
            $query->where('customer_id', $customer->id)->latest();
        } else {
            return ['error' => 'Please provide an order ID or tracking number, or log in.'];
        }

        $order = $query->first();
        if (!$order) return ['error' => 'Order not found.'];

        $timeline = [];
        if (method_exists($order, 'timeline')) {
            $timeline = $order->timeline()->latest()->get()->map(fn($t) => [
                'status' => $t->status,
                'note' => $t->note,
                'date' => $t->created_at->format('Y-m-d H:i'),
            ])->toArray();
        }

        return [
            'status' => 'success',
            'order_id' => $order->id,
            'tracking_no' => $order->tracking_no,
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
            'total_amount' => (float) ($order->total_amount ?? 0),
            'due_amount' => (float) ($order->due_amount ?? 0),
            'delivery_date' => $order->delivary_date ? $order->delivary_date->format('Y-m-d') : null,
            'delivery_address' => $order->address ? [
                'name' => $order->address->name,
                'phone' => $order->address->phone,
                'address' => $order->address->address,
                'post_code' => $order->address->post_code,
                'city' => $order->address->city?->name,
                'state' => $order->address->state?->name,
            ] : null,
            'items' => $order->orderItems->map(fn($item) => [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => (float) ($item->price ?? 0),
            ])->toArray(),
            'timeline' => $timeline,
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
        if ($order->tracking) {
            $single = $order->tracking;
            $trackingHistory[] = [
                'status' => $single->status ?? 'registered',
                'location' => $single->location ?? null,
                'note' => $single->remark ?? null,
                'date' => $single->created_at?->format('Y-m-d H:i'),
            ];
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
            ? $customer->orders()->latest()->take(3)->get()->map(fn($o) => [
                'id' => $o->id,
                'tracking_no' => $o->tracking_no,
                'status' => $o->status,
                'total_amount' => (float) $o->total_amount,
                'date' => $o->created_at->format('Y-m-d'),
            ])->toArray()
            : [];

        return [
            'status' => 'success',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone ?? null,
            'available_points' => (int) ($customer->available_point ?? 0),
            'wallet_amount' => (float) ($customer->amount ?? 0),
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

        if ($customer && $cartModel) {
            $existing = $cartModel::where('product_id', $productId)
                ->where('customer_id', $customer->id)
                ->first();

            if ($existing) {
                $newQty = $existing->quantity + $quantity;
                if ($newQty > $availableQty) {
                    return ['error' => "Only {$availableQty} available. You already have {$existing->quantity} in cart."];
                }
                $existing->update(['quantity' => $newQty]);
            } else {
                $price = (float) ($product->online_price ?? 0);
                if (!empty($product->discount)) $price -= (float) $product->discount;

                $cartModel::create([
                    'product_id' => $productId,
                    'customer_id' => $customer->id,
                    'quantity' => $quantity,
                    'item_price' => $price,
                    'total_amount' => $price * $quantity,
                ]);
            }
        }

        return [
            'status' => 'success',
            'message' => "Added {$quantity}x {$product->title} to cart.",
            'action' => 'redirect',
            'url' => config('gunma-agent.website_url') . '/checkout',
        ];
    }

    private function getFeaturedRecipe(): array
    {
        $results = $this->qdrantService->searchRecipes('halal', 5);
        if (empty($results)) return ['error' => 'No recipes found.'];
        return $results[array_rand($results)]['payload'];
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

    private function getCartContents(): array
    {
        $customer = auth('customer')->user();
        if (!$customer) return ['error' => 'Please log in to view your cart.'];

        $cartModel = $this->getModelClass('cart', \App\Models\Cart::class);
        if (!$cartModel) return ['error' => 'Cart system unavailable.'];

        $items = $cartModel::where('customer_id', $customer->id)
            ->with('product')
            ->get()
            ->map(fn($item) => [
                'product_id' => $item->product_id,
                'name'       => $item->product->title ?? 'Unknown',
                'quantity'   => $item->quantity,
                'price'      => (float) $item->item_price,
            ])->toArray();

        return ['status' => 'success', 'items' => $items, 'total_items' => count($items)];
    }

    private function getActivePromotions(): array
    {
        return [
            'status' => 'success',
            'promotions' => [
                ['title' => 'First Order Discount', 'code' => 'WELCOME10', 'description' => '10% off on your first order.'],
                ['title' => 'Free Shipping', 'code' => 'FREESHIP', 'description' => 'Free shipping on orders over ¥5000.'],
                ['title' => 'Ramadan Special', 'code' => 'RAMADAN', 'description' => 'Buy 5kg Rice, get 1kg Lentil free!'],
            ],
        ];
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

        return [
            'status' => 'success',
            'product' => [
                'id'           => $product->id,
                'title'        => $product->title,
                'slug'         => $product->slug,
                'description'  => $product->description,
                'short_description' => $product->short_description,
                'price'        => (float) ($product->latestStock?->online_price ?? 0),
                'discount'     => (float) ($product->discount ?? 0),
                'stock'        => (int) ($product->latestStock?->available_quantity ?? 0),
                'unit'         => $product->latestStock?->unit ?? $product->unit,
                'images'       => $product->images->pluck('image')->toArray(),
                'categories'   => $product->categories->pluck('title')->toArray(),
                'brand'        => $product->brand,
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

        if ($sort === 'price_asc') $query->orderBy(
            $productModel::select('online_price')->whereColumn('products.id', 'product_stocks.product_id')->latest('id')->limit(1),
            'asc'
        );
        elseif ($sort === 'price_desc') $query->orderBy(
            $productModel::select('online_price')->whereColumn('products.id', 'product_stocks.product_id')->latest('id')->limit(1),
            'desc'
        );
        else $query->latest();

        $products = $query->limit($limit)->get();

        return [
            'status' => 'success',
            'total' => $products->count(),
            'products' => $products->map(fn($p) => [
                'id'     => $p->id,
                'title'  => $p->title,
                'slug'   => $p->slug,
                'price'  => (float) ($p->latestStock?->online_price ?? 0),
                'image'  => $p->images->first()?->image,
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

        // In production this would query a coupons/promotions table
        $coupons = [
            'WELCOME10' => ['type' => 'percent', 'value' => 10, 'min' => 0, 'desc' => '10% off first order'],
            'FREESHIP'  => ['type' => 'free_shipping', 'value' => 0, 'min' => 5000, 'desc' => 'Free shipping over ¥5000'],
            'RAMADAN'   => ['type' => 'percent', 'value' => 15, 'min' => 3000, 'desc' => '15% off orders over ¥3000'],
            'FLAT200'   => ['type' => 'flat', 'value' => 200, 'min' => 2000, 'desc' => '¥200 off orders over ¥2000'],
        ];

        $coupon = $coupons[$code] ?? null;
        if (!$coupon) return ['error' => 'Invalid coupon code.'];

        if ($cartTotal > 0 && $cartTotal < $coupon['min']) {
            return ['error' => "Minimum order of ¥{$coupon['min']} required for this coupon."];
        }

        $discount = 0;
        if ($coupon['type'] === 'percent') $discount = $cartTotal * ($coupon['value'] / 100);
        elseif ($coupon['type'] === 'flat') $discount = $coupon['value'];

        return [
            'status' => 'success',
            'code' => $code,
            'description' => $coupon['desc'],
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
                ['rating' => $rating, 'review' => $comment, 'status' => 'pending']
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
                    'name' => 'get_featured_recipe',
                    'description' => 'Get a random featured halal recipe.',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
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
                    'name' => 'cache_new_recipe',
                    'description' => 'Save a new recipe for future users.',
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
                    'name' => 'get_order_status',
                    'description' => 'Get status, payment info, items, and delivery address for an order.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id_or_tracking' => [
                                'type' => 'string',
                                'description' => 'Order ID or tracking number. Empty = most recent order for logged-in user.',
                            ],
                        ],
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
                    'description' => 'Add a product to the user cart. Checks stock and existing items before adding.',
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
                    'name' => 'get_cart_contents',
                    'description' => 'Get items currently in the user cart. Use before suggesting products.',
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
                    'name' => 'apply_coupon',
                    'description' => 'Validate a coupon/promo code and calculate the discount.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'description' => 'Coupon code.'],
                            'cart_total' => ['type' => 'number', 'description' => 'Current cart total for discount calculation.'],
                        ],
                        'required' => ['code'],
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
                    'name' => 'hand_off_to_human',
                    'description' => 'Transfer conversation to a human agent.',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
                ],
            ],
        ];
    }
}
