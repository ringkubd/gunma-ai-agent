<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Log;

/**
 * Dispatches OpenAI tool calls to the appropriate Qdrant search services.
 * Mirrors the tool_calls loop from agent_server.js.
 */
class ToolExecutor
{
    public function __construct(
        private readonly QdrantService $qdrantService,
    ) {}

    /**
     * Execute a single tool call and return the result.
     */
    public function execute(string $functionName, array $args): mixed
    {
        Log::info("[ToolExecutor] {$functionName}", $args);

        return match ($functionName) {
            'search_products_bulk' => $this->qdrantService->searchProductsBulk($args['queries'] ?? []),
            'search_recipes'       => $this->qdrantService->searchRecipes($args['query'] ?? ''),
            'search_support_kb'    => $this->qdrantService->searchSupportKB($args['query'] ?? ''),
            'cache_new_recipe'     => $this->cacheNewRecipe($args),
            'get_order_status'     => $this->getOrderStatus($args),
            'get_customer_info'    => $this->getCustomerInfo(),
            'add_item_to_cart'     => $this->addItemToCart($args),
            'get_featured_recipe'  => $this->getFeaturedRecipe(),
            'create_support_ticket'=> $this->createSupportTicket($args),
            'check_delivery_time'  => $this->checkDeliveryTime($args),
            'get_trending_products'=> $this->getTrendingProducts($args),
            'get_cart_contents'    => $this->getCartContents(),
            'create_order_claim'   => $this->createOrderClaim($args),
            'get_personalized_recommendations' => $this->getPersonalizedRecommendations($args),
            'get_active_promotions'=> $this->getActivePromotions(),
            'hand_off_to_human'    => $this->handOffToHuman($session ?? null),
            default                => ['error' => "Unknown tool: {$functionName}"],
        };
    }

    private function cacheNewRecipe(array $args): array
    {
        $this->qdrantService->upsertRecipe($args);

        return ['status' => 'success', 'message' => 'Recipe cached for future users.'];
    }

    private function getOrderStatus(array $args): array
    {
        $identifier = $args['order_id_or_tracking'] ?? null;
        $customer = auth('customer')->user();

        $orderModel = config('gunma-agent.models.order', \App\Models\Order::class);

        if (!class_exists($orderModel)) {
            return ['error' => 'Order lookup is not available.'];
        }

        $query = $orderModel::with(['orderItems', 'address', 'tracking', 'payments']);

        if ($identifier) {
            $query->where(function($q) use ($identifier) {
                $q->where('id', $identifier)
                  ->orWhere('tracking_no', $identifier);
            });
            // If logged in, restrict to their orders
            if ($customer) {
                $query->where('customer_id', $customer->id);
            }
        } elseif ($customer) {
            $query->where('customer_id', $customer->id)->latest();
        } else {
            return ['error' => 'Please provide an order ID or tracking number, or log in to view your recent orders.'];
        }

        $order = $query->first();

        if (!$order) {
            return ['error' => 'Order not found.'];
        }

        return [
            'status' => 'success',
            'order_id' => $order->id,
            'tracking_no' => $order->tracking_no,
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
            'total_amount' => $order->total_amount,
            'due_amount' => $order->due_amount,
            'delivery_date' => $order->delivary_date ? $order->delivary_date->format('Y-m-d') : null,
            'items' => $order->orderItems->map(fn($item) => [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => $item->price
            ])->toArray()
        ];
    }

    private function getCustomerInfo(): array
    {
        $customer = auth('customer')->user();

        if (!$customer) {
            return ['error' => 'User is not logged in.'];
        }

        $orderModel = config('gunma-agent.models.order', \App\Models\Order::class);
        $recentOrders = class_exists($orderModel)
            ? $customer->orders()->latest()->take(3)->get()->map(fn($o) => [
                'id' => $o->id,
                'tracking_no' => $o->tracking_no,
                'status' => $o->status,
                'total_amount' => $o->total_amount,
                'date' => $o->created_at->format('Y-m-d')
            ])->toArray()
            : [];

        return [
            'status' => 'success',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'available_points' => $customer->available_point, // from customers table
            'wallet_amount' => $customer->amount,
            'recent_orders' => $recentOrders,
            'points_history' => $customer->pointHistories()->latest()->take(5)->get()->map(fn($p) => [
                'points' => $p->point,
                'type' => $p->type,
                'description' => $p->description,
                'date' => $p->created_at->format('Y-m-d')
            ])->toArray(),
        ];
    }

    private function addItemToCart(array $args): array
    {
        $productId = $args['product_id'];
        $quantity = $args['quantity'] ?? 1;
        $customer = auth('customer')->user();

        $productModel = config('gunma-agent.models.product', \App\Models\Product::class);
        $cartModel = config('gunma-agent.models.cart', \App\Models\Cart::class);

        if (!class_exists($productModel)) {
            return ['error' => 'Product lookup is not available.'];
        }

        // Ensure product exists
        $product = $productModel::find($productId);
        if (!$product) {
            return ['error' => 'Product not found.'];
        }

        if ($customer && class_exists($cartModel)) {
            // Add to Cart directly for logged-in user
            $price = $product->online_price;
            if ($product->discount > 0) {
                $price = $price - $product->discount;
            }
            
            $taxAmount = ($product->tax_percent / 100) * ($price * $quantity);
            $totalAmount = ($price * $quantity) + $taxAmount;

            $cartModel::create([
                'product_id' => $product->id,
                'customer_id' => $customer->id,
                'quantity' => $quantity,
                'item_price' => $price,
                'tax_percent' => $product->tax_percent,
                'total_tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
            ]);
        }

        // Return an action indicating frontend to redirect or add to cart
        return [
            'status' => 'success',
            'message' => "Added {$quantity} of {$product->name} to cart. Redirecting to checkout...",
            'action' => 'redirect',
            'url' => config('gunma-agent.website_url') . "/checkout" . ($customer ? "" : "?add_to_cart={$productId}&qty={$quantity}")
        ];
    }

    private function getFeaturedRecipe(): array
    {
        // Search for a common recipe or just "halal" to get a random high-score match
        $results = $this->qdrantService->searchRecipes('halal', 5);
        
        if (empty($results)) {
            return ['error' => 'No recipes found.'];
        }

        // Return a random one from the top 5
        return $results[array_rand($results)]['payload'];
    }

    private function createSupportTicket(array $args): array
    {
        $messageModel = config('gunma-agent.models.message', \App\Models\Message::class);
        $customer = auth('customer')->user();

        if (!class_exists($messageModel)) {
            return ['error' => 'Support system is currently unavailable.'];
        }

        $ticket = $messageModel::create([
            'name'    => $customer->name ?? $args['name'] ?? 'Guest User',
            'email'   => $customer->email ?? $args['email'] ?? null,
            'phone'   => $customer->phone ?? $args['phone'] ?? null,
            'message' => "[AI TICKET] " . ($args['issue_type'] ?? 'General') . " | Order: " . ($args['order_id'] ?? 'N/A') . "\nDetails: " . ($args['product_details'] ?? 'N/A') . "\nSummary: " . $args['message'],
        ]);

        // Dispatch event for external integrations (e.g., Google Sheets, Email)
        event(new \Anwar\GunmaAgent\Events\SupportTicketCreated($ticket, $args));

        return [
            'status'  => 'success',
            'message' => 'Your support ticket has been raised. Our team will contact you soon.',
            'ticket_id' => $ticket->id
        ];
    }

    private function checkDeliveryTime(array $args): array
    {
        $postCode = $args['post_code'] ?? null;
        if (!$postCode) {
            return ['error' => 'Please provide a post code.'];
        }

        $postCodeModel = config('gunma-agent.models.post_code', \App\Models\PostCode::class);
        if (!class_exists($postCodeModel)) {
            return ['error' => 'Delivery check is currently unavailable.'];
        }

        $data = $postCodeModel::with(['schedules', 'city', 'state'])->where('code', $postCode)->first();

        if (!$data) {
            return ['error' => 'Post code not found. Please double check the code.'];
        }

        return [
            'status' => 'success',
            'post_code' => $data->code,
            'city' => $data->city->name ?? null,
            'state' => $data->state->name ?? null,
            'delay_days' => $data->after_delay,
            'schedules' => $data->schedules->pluck('schedule')->toArray(),
        ];
    }

    private function getTrendingProducts(array $args): array
    {
        $productModel = config('gunma-agent.models.product');
        if (!class_exists($productModel)) return [];

        $products = $productModel::where('status', 'Active')
            ->where('is_online_available', 1)
            ->with(['latestStock', 'images'])
            ->latest()
            ->limit($args['limit'] ?? 5)
            ->get();

        return $products->map(fn($p) => [
            'id'        => $p->id,
            'title'     => $p->title,
            'price'     => (float) ($p->latestStock?->online_price ?? 0),
            'image_url' => $p->images->first()?->image_path,
            'slug'      => $p->slug
        ])->toArray();
    }

    private function getCartContents(): array
    {
        $customer = auth('customer')->user();
        if (!$customer) {
            return ['error' => 'User not logged in. Cannot fetch cart.'];
        }

        $cartModel = config('gunma-agent.models.cart', \App\Models\Cart::class);
        if (!class_exists($cartModel)) {
            return ['error' => 'Cart system unavailable.'];
        }

        $items = $cartModel::where('customer_id', $customer->id)
            ->with('product')
            ->get()
            ->map(fn($item) => [
                'product_id' => $item->product_id,
                'name' => $item->product->title ?? 'Unknown Product',
                'quantity' => $item->quantity,
                'price' => $item->item_price,
            ])->toArray();

        return [
            'status' => 'success',
            'items' => $items,
            'total_items' => count($items),
        ];
    }

    private function getActivePromotions(): array
    {
        // In a real scenario, this would fetch from a 'promotions' or 'coupons' table
        return [
            'status' => 'success',
            'promotions' => [
                ['title' => 'First Order Discount', 'code' => 'WELCOME10', 'description' => '10% off on your first order.'],
                ['title' => 'Free Shipping', 'code' => 'FREESHIP', 'description' => 'Free shipping on orders over ¥5000.'],
                ['title' => 'Ramadan Special', 'code' => 'RAMADAN', 'description' => 'Buy 5kg Rice, get 1kg Lentil free!'],
            ]
        ];
    }

    private function handOffToHuman($session): array
    {
        if ($session) {
            $session->update(['is_ai_enabled' => false]);
            // Here you could also fire a notification event to Pusher for the admin
        }

        return [
            'status' => 'success',
            'message' => 'AI has been disabled. A human agent will take over this conversation shortly.'
        ];
    }

    private function createOrderClaim(array $args): array
    {
        $claimModel = config('gunma-agent.models.order_claim', \App\Models\OrderClaim::class);
        $customer = auth('customer')->user();

        if (!class_exists($claimModel)) {
            return ['error' => 'Order claim system is currently unavailable.'];
        }

        $claim = $claimModel::create([
            'customer_id' => $customer->id ?? null,
            'order_id'    => $args['order_id'],
            'claim_type'  => $args['issue_type'],
            'description' => $args['product_details'] . "\n" . $args['message'],
            'status'      => 'Pending',
        ]);

        // Dispatch event for further processing (Google Sheets, Email, etc.)
        event(new \Anwar\GunmaAgent\Events\SupportTicketCreated($claim, $args));

        return [
            'status'  => 'success',
            'message' => 'Your claim has been registered in our system. Claim ID: ' . $claim->id,
            'claim_id' => $claim->id
        ];
    }

    private function getPersonalizedRecommendations(array $args): array
    {
        $customer = auth('customer')->user();
        if (!$customer) {
            return $this->getTrendingProducts($args);
        }

        $results = $this->qdrantService->searchPersonalizedProducts($customer->id, $args['limit'] ?? 5);
        
        return array_map(fn($hit) => [
            'id'        => $hit['payload']['id'] ?? null,
            'title'     => $hit['payload']['title'] ?? $hit['payload']['name'] ?? 'Unknown',
            'price'     => $hit['payload']['price'] ?? null,
            'image_url' => $hit['payload']['image_url'] ?? null,
            'slug'      => $hit['payload']['slug'] ?? null,
        ], $results);
    }

    /**
     * Return the OpenAI tool definitions for the system prompt.
     */
    public static function getToolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_featured_recipe',
                    'description' => 'Get a random featured halal recipe to suggest to the user.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_products_bulk',
                    'description' => 'Search for multiple halal products, prices, and availability at once.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'queries' => [
                                'type'        => 'array',
                                'items'       => ['type' => 'string'],
                                'description' => 'List of product names or ingredients to search for.',
                            ],
                        ],
                        'required' => ['queries'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_recipes',
                    'description' => 'Find halal recipe ideas and cooking instructions.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => [
                                'type'        => 'string',
                                'description' => 'The type of dish or ingredient to find a recipe for.',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_support_kb',
                    'description' => 'Search our support knowledge base for questions about shipping, delivery, payments, and order status.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => [
                                'type'        => 'string',
                                'description' => 'The customer support question.',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'cache_new_recipe',
                    'description' => 'Save a new recipe to our store database for future users.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'title'        => ['type' => 'string'],
                            'ingredients'  => ['type' => 'array', 'items' => ['type' => 'string']],
                            'instructions' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'ingredients', 'instructions'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_order_status',
                    'description' => 'Get the current status and information for an order.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'order_id_or_tracking' => [
                                'type'        => 'string',
                                'description' => 'The order ID or tracking number. Leave empty if the user asks for their most recent order and is logged in.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_customer_info',
                    'description' => 'Get the logged-in user\'s profile information, available points, and order history.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'add_item_to_cart',
                    'description' => 'Add a specific product to the user\'s cart and generate a direct checkout link.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'product_id' => [
                                'type'        => 'integer',
                                'description' => 'The ID of the product to add.',
                            ],
                            'quantity' => [
                                'type'        => 'integer',
                                'description' => 'The quantity to add (default 1).',
                            ],
                        ],
                        'required' => ['product_id'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'create_support_ticket',
                    'description' => 'Automatically create a support ticket for payment issues, complaints, or if the user wants to leave a message.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'message' => [
                                'type'        => 'string',
                                'description' => 'Summary of the user\'s issue or message.',
                            ],
                            'issue_type' => [
                                'type'        => 'string',
                                'enum'        => ['payment', 'delivery', 'quality', 'feedback', 'product_missing', 'product_damage', 'extra_item', 'other'],
                                'description' => 'The category of the issue.',
                            ],
                            'order_id' => [
                                'type'        => 'string',
                                'description' => 'The ID of the order related to the issue.',
                            ],
                            'product_details' => [
                                'type'        => 'string',
                                'description' => 'Details of the specific product(s) involved (e.g., name, quantity).',
                            ],
                            'name'  => ['type' => 'string', 'description' => 'User name if guest.'],
                            'email' => ['type' => 'string', 'description' => 'User email if guest.'],
                        ],
                        'required' => ['message', 'issue_type'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'check_delivery_time',
                    'description' => 'Check the estimated delivery time and available schedules for a specific post code.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'post_code' => [
                                'type'        => 'string',
                                'description' => 'The user\'s post code (e.g., 270-0021).',
                            ],
                        ],
                        'required' => ['post_code'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_trending_products',
                    'description' => 'Get the most popular and latest products for guest or new users.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'default' => 5],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_personalized_recommendations',
                    'description' => 'Get personalized product recommendations based on the customer\'s specific purchase history.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'default' => 5],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_cart_contents',
                    'description' => 'Get the items currently in the user\'s shopping cart. Use this before suggesting products to avoid duplicates.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'create_order_claim',
                    'description' => 'Register a formal claim for missing, damaged, or extra products in an order.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'order_id' => ['type' => 'string', 'description' => 'The order ID.'],
                            'issue_type' => [
                                'type' => 'string',
                                'enum' => ['product_missing', 'product_damage', 'extra_item'],
                            ],
                            'product_details' => ['type' => 'string', 'description' => 'Name and quantity of products involved.'],
                            'message' => ['type' => 'string', 'description' => 'Additional notes.'],
                        ],
                        'required' => ['order_id', 'issue_type', 'product_details', 'message'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_active_promotions',
                    'description' => 'Check for current store-wide discounts, coupons, and special deals.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'hand_off_to_human',
                    'description' => 'Transfer the conversation to a human support agent if the issue is too complex or the user is angry.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
        ];
    }
}
