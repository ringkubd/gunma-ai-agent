<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Http\Controllers;

use Anwar\GunmaAgent\Models\ChatMessage;
use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Services\AgentOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly AgentOrchestrator $agent,
    ) {
        // Auth is resolved by the ResolveCustomer middleware.
        // Use auth()->user() or auth()->id() freely in any method.
    }

    /* ── POST /chat/sessions — Create a new chat session ───────── */

    public function createSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visitor_id'     => 'required|string|max:64',
            'customer_name'  => 'nullable|string|max:255',
            'channel'        => 'nullable|in:web,admin,whatsapp',
            'metadata'       => 'nullable|array',
        ]);

        // If the request is authenticated, use the real customer identity
        $customerId   = auth()->id();
        $customerName = $validated['customer_name'] ?? null;

        if ($customerId && ! $customerName) {
            $user = auth()->user();
            // Support name, full_name, or email as display name
            $customerName = $user->name
                ?? $user->full_name
                ?? $user->email
                ?? null;
        }

        // Find existing active session for this visitor (or customer)
        $sessionQuery = ChatSession::where('channel', $validated['channel'] ?? 'web')->active();

        if ($customerId) {
            // Authenticated: match on customer_id first, then visitor_id as fallback
            $session = (clone $sessionQuery)->where('customer_id', $customerId)->first()
                ?? (clone $sessionQuery)->where('visitor_id', $validated['visitor_id'])->first();
        } else {
            // Guest: match on visitor_id only
            $session = $sessionQuery->where('visitor_id', $validated['visitor_id'])->first();
        }

        if (! $session) {
            $session = ChatSession::create([
                'visitor_id'    => $validated['visitor_id'],
                'customer_id'   => $customerId,
                'customer_name' => $customerName,
                'channel'       => $validated['channel'] ?? 'web',
                'status'        => 'active',
                'metadata'      => $validated['metadata'] ?? null,
            ]);
        } elseif ($customerId && ! $session->customer_id) {
            // Upgrade anonymous session to authenticated
            $session->update([
                'customer_id'   => $customerId,
                'customer_name' => $customerName ?? $session->customer_name,
            ]);
        }

        return response()->json([
            'session' => $session,
        ], 201);
    }

    /* ── Private: ownership guard ──────────────────────────────── */

    /**
     * Ensure the caller owns this chat session. Authenticated customers are
     * matched by customer_id; guests must present the same visitor_id the
     * session was created with (X-Visitor-Id header or visitor_id input).
     */
    private function assertSessionOwnership(Request $request, ChatSession $session): void
    {
        if (! config('gunma-agent.enforce_session_ownership', true)) {
            return;
        }

        // Admin / staff requests (web/sanctum guards) bypass ownership.
        foreach (config('gunma-agent.admin_guards', ['web', 'sanctum']) as $guard) {
            try {
                if (auth()->guard($guard)->check()) {
                    return;
                }
            } catch (\Exception) {
                // Guard not present in host app — ignore.
            }
        }

        $customerId = auth()->id();
        if ($customerId && (int) $session->customer_id === (int) $customerId) {
            return;
        }

        $visitorId = (string) ($request->header('X-Visitor-Id') ?? $request->input('visitor_id', ''));
        if ($visitorId !== '' && hash_equals((string) $session->visitor_id, $visitorId)) {
            return;
        }

        abort(403, 'You are not allowed to access this chat session.');
    }

    /* ── GET /chat/sessions/{id} — Get session with messages ───── */

    public function showSession(Request $request, string $id): JsonResponse
    {
        $session = ChatSession::with(['messages' => function ($query) {
            $query->whereIn('role', ['user', 'assistant'])->orderBy('created_at');
        }])->findOrFail($id);

        $this->assertSessionOwnership($request, $session);

        return response()->json([
            'session' => $session,
        ]);
    }

    /* ── POST /chat/sessions/{id}/messages — Send message (SSE) ── */

    public function sendMessage(Request $request, string $id): StreamedResponse
    {
        $session = ChatSession::findOrFail($id);
        $this->assertSessionOwnership($request, $session);

        if (! $session->isActive()) {
            return new StreamedResponse(function () {
                echo $this->sseEvent('error', ['message' => 'Session has ended.']);
            }, 200, $this->sseHeaders());
        }

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $userMessage = $validated['message'];

        // Rate limiting via Redis
        $rateLimitKey = "gunma:chat:rate:{$session->visitor_id}";
        $rateLimit    = config('gunma-agent.rate_limit', 30);

        try {
            $current = (int) Redis::get($rateLimitKey);
            if ($current >= $rateLimit) {
                return new StreamedResponse(function () {
                    echo $this->sseEvent('error', [
                        'message' => 'Too many messages. Please wait a moment.',
                    ]);
                }, 200, $this->sseHeaders());
            }
            Redis::incr($rateLimitKey);
            Redis::expire($rateLimitKey, 60);
        } catch (\Exception $e) {
            // Redis unavailable — proceed without rate limiting
        }

        return new StreamedResponse(function () use ($session, $userMessage) {
            foreach ($this->agent->chatStream($session, $userMessage) as $chunk) {
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, $this->sseHeaders());
    }

    /* ── POST /chat/sessions/{id}/messages/sync — Non-streaming ── */

    public function sendMessageSync(Request $request, string $id): JsonResponse
    {
        $session = ChatSession::findOrFail($id);
        $this->assertSessionOwnership($request, $session);

        if (! $session->isActive()) {
            return response()->json(['error' => 'Session has ended.'], 422);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $reply = $this->agent->chat($session, $validated['message']);

        return response()->json([
            'reply' => $reply,
        ]);
    }

    /* ── GET /chat/sessions/{id}/messages — Get message history ── */

    public function getMessages(Request $request, string $id): JsonResponse
    {
        $session = ChatSession::findOrFail($id);
        $this->assertSessionOwnership($request, $session);
        $limit   = min((int) ($request->query('limit', 50)), 100);

        $messages = ChatMessage::where('session_id', $session->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('created_at')
            ->take($limit)
            ->get()
            ->sortBy('created_at')
            ->values()
            ->map(fn ($m) => [
                'id'         => $m->id,
                'role'       => $m->role,
                'content'    => $m->content,
                'model'      => $m->model,
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        return response()->json([
            'messages' => $messages,
        ]);
    }

    /* ── POST /chat/sessions/{id}/end — End a session ──────────── */

    public function endSession(Request $request, string $id): JsonResponse
    {
        $session = ChatSession::findOrFail($id);
        $this->assertSessionOwnership($request, $session);
        $session->end();

        return response()->json([
            'status' => 'ended',
        ]);
    }

    /* ── POST /chat/upload — Upload a file (images for claims) ──── */

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB limit
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('chat_uploads', 'public');
            $url = asset('storage/' . $path);

            return response()->json([
                'status' => 'success',
                'url' => $url,
            ]);
        }

        return response()->json(['error' => 'No file uploaded.'], 422);
    }

    /* ── Private: SSE Helpers ──────────────────────────────────── */

    private function sseHeaders(): array
    {
        $origin = request()->headers->get('Origin');
        $allowed = config('gunma-agent.cors_origins', ['*']);

        // Echo only allowed origins; never a blanket wildcard when credentials
        // or an explicit allow-list is configured.
        if (in_array('*', $allowed, true)) {
            $acao = $origin ?: '*';
        } else {
            $acao = ($origin && in_array($origin, $allowed, true)) ? $origin : ($allowed[0] ?? '');
        }

        return [
            'Content-Type'                => 'text/event-stream',
            'Cache-Control'               => 'no-cache',
            'Connection'                  => 'keep-alive',
            'X-Accel-Buffering'           => 'no',
            'Access-Control-Allow-Origin' => $acao,
            'Vary'                        => 'Origin',
        ];
    }

    private function sseEvent(string $event, array $data): string
    {
        return "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    }

    /* ── Admin Methods ────────────────────────────────────────── */
    /**
     * List all active chat sessions.
     */
    public function listSessions(Request $request): JsonResponse
    {
        $sessions = ChatSession::withCount('messages')
            ->latest('updated_at')
            ->get()
            ->map(function ($s) {
                $feedback = DB::table('chat_feedback')->where('session_id', $s->id)->first();
                return [
                    'id'               => (string) $s->id,
                    'visitor_id'       => (string) ($s->visitor_id ?? ''),
                    'customer_name'    => $s->resolved_name,
                    'customer_email'   => $s->resolved_email,
                    'channel'          => $s->channel ?? 'web',
                    'status'           => $s->status ?? 'active',
                    'is_ai_enabled'    => (bool) ($s->is_ai_enabled ?? true),
                    'messages_count'   => $s->messages_count ?? 0,
                    'updated_at'       => (string) ($s->updated_at ?? $s->created_at),
                    'feedback_rating'  => $feedback ? (int) $feedback->rating : null,
                    'feedback_comment' => $feedback ? $feedback->comment : null,
                    'metadata'         => [
                        'priority_score' => (int) ($s->metadata['priority_score'] ?? 0),
                    ],
                ];
            })
            ->values();

        return response()->json(['data' => $sessions]);
    }

    /**
     * Get details of a specific session.
     */
    public function getSession(string $sessionId): JsonResponse
    {
        $session = ChatSession::with(['messages' => fn($q) => $q->oldest()->take(100)])
            ->findOrFail($sessionId);

        return response()->json([
            'session' => $session,
            'customer_name' => $session->resolved_name,
        ]);
    }

    /**
     * Toggle AI for a specific session.
     */
    public function toggleAi(Request $request, string $sessionId): JsonResponse
    {
        $session = ChatSession::findOrFail($sessionId);
        $session->update([
            'is_ai_enabled' => $request->boolean('enabled'),
        ]);

        event(new \Anwar\GunmaAgent\Events\AiStatusChanged($session));

        return response()->json(['status' => 'success', 'is_ai_enabled' => $session->is_ai_enabled]);
    }

    /**
     * Send a manual message from an agent.
     */
    public function sendManualMessage(Request $request, string $sessionId): JsonResponse
    {
        $session = ChatSession::findOrFail($sessionId);
        $content = $request->input('message');

        $message = ChatMessage::create([
            'session_id' => $session->id,
            'role'       => 'assistant',
            'content'    => $content,
            'model'      => 'manual',
        ]);

        // Broadcast to user and admin dashboard
        event(new \Anwar\GunmaAgent\Events\MessageBroadcasted($message));

        return response()->json(['status' => 'success', 'message' => $message]);
    }

    /**
     * List all support tickets.
     */
    public function listTickets(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $query = \Anwar\GunmaAgent\Models\SupportTicket::latest();

        if ($status) {
            $query->where('status', $status);
        }

        $tickets = $query->paginate(20);

        return response()->json($tickets);
    }

    /**
     * Get a single ticket with full details.
     * GET /api/admin/chat/tickets/{id}
     */
    public function getTicket(string $id): JsonResponse
    {
        $ticket = \Anwar\GunmaAgent\Models\SupportTicket::findOrFail($id);

        // Find related session messages if session_id exists
        $messages = [];
        if ($ticket->session_id) {
            $messages = \Anwar\GunmaAgent\Models\ChatMessage::where('session_id', $ticket->session_id)
                ->orderBy('created_at')
                ->take(50)
                ->get()
                ->toArray();
        }

        return response()->json([
            'ticket' => $ticket,
            'messages' => $messages,
        ]);
    }

    /**
     * Update a ticket's status.
     */
    public function updateTicketStatus(Request $request, string $id): JsonResponse
    {
        $ticket = \Anwar\GunmaAgent\Models\SupportTicket::findOrFail($id);
        $ticket->update([
            'status' => $request->input('status'),
        ]);

        return response()->json(['status' => 'success', 'ticket' => $ticket]);
    }

    /**
     * Get basic analytics for the dashboard.
     */
    public function getStats(): JsonResponse
    {
        return response()->json([
            'total_sessions'   => ChatSession::count(),
            'active_sessions'  => ChatSession::active()->count(),
            'total_messages'   => ChatMessage::count(),
            'manual_sessions'  => ChatSession::where('is_ai_enabled', false)->count(),
            'pending_tickets'  => \Anwar\GunmaAgent\Models\SupportTicket::where('status', 'pending')->count(),
        ]);
    }

    /**
     * Bulk Add to Cart — writes to the SAME cart table the storefront reads,
     * using the same identity rules as App\Http\Controllers\Frontend\CartAPIController:
     * authenticated customers by customer_id, guests by the encrypted `guest_id`
     * cookie (or an explicitly supplied `cookie`).
     */
    public function bulkAddToCart(Request $request): JsonResponse
    {
        $productIds = $request->input('product_ids', []);

        if (empty($productIds)) {
            return response()->json(['error' => 'No products provided.'], 422);
        }

        $cartModel  = config('gunma-agent.models.cart', \App\Models\Cart::class);
        $stockModel = config('gunma-agent.models.stock', \App\Models\Stock::class);
        $productModel = config('gunma-agent.models.product', \App\Models\Product::class);

        if (!class_exists($cartModel) || !class_exists($stockModel)) {
            return response()->json(['error' => 'Cart models not available.'], 500);
        }

        $customerId = auth('customer')->id();
        [$cookieId, $encryptedCookie, $isNewCookie] = $this->resolveGuestCartIdentity($request, $customerId);

        $results = [];

        foreach ($productIds as $id) {
            $lastStock = $stockModel::where('product_id', $id)->latest('id')->first();
            $price = (float) ($lastStock->online_price ?? 0);

            // Discount + tax (mirror CartAPIController)
            $discount = 0.0;
            $taxPercent = 8.0;
            $weight = 0.0;
            $unit = null;
            if (class_exists($productModel)) {
                $product = $productModel::with('latestStock')->find($id);
                if ($product) {
                    $discount = (float) ($product->discount->amount ?? 0);
                    $taxPercent = (float) ($product->tax_percent ?? 8);
                    $weight = (float) ($product->weight ?? 0);
                    $unit = $product->unit;
                }
            }

            $identity = $customerId
                ? ['customer_id' => $customerId]
                : ['cookie_id' => $cookieId];

            $duplicate = $cartModel::where('product_id', $id)
                ->where('product_option_id', '')
                ->where($identity)
                ->first();

            if ($duplicate) {
                $qty = $duplicate->quantity + 1;
                $duplicate->update([
                    'quantity' => $qty,
                    'total_amount' => $qty * ($price - $discount),
                    'total_discount_amount' => $qty * $discount,
                    'total_tax_amount' => ($taxPercent / 100) * ($qty * ($price - $discount)),
                ]);
                $results[] = $duplicate->fresh();
            } else {
                $totalAmount = $price - $discount;
                $results[] = $cartModel::create(array_merge($identity, [
                    'product_id' => $id,
                    'product_option_id' => '',
                    'quantity' => 1,
                    'weight' => $weight,
                    'unit' => $unit,
                    'item_price' => $price,
                    'discount_amount' => 0,
                    'tax_percent' => $taxPercent,
                    'total_discount_amount' => $discount,
                    'total_tax_amount' => ($taxPercent / 100) * $totalAmount,
                    'total_amount' => $totalAmount,
                ]));
            }
        }

        // Broadcast cart update so the storefront reacts in real-time.
        try {
            if (class_exists('\App\Events\CartUpdated')) {
                event(new \App\Events\CartUpdated(
                    $customerId ? null : $encryptedCookie,
                    'bulk_added',
                    $results[0] ?? null,
                    $customerId
                ));
            }
        } catch (\Exception $e) {
            \Log::error('AI Bulk Add Cart event failed', ['error' => $e->getMessage()]);
        }

        $response = response()->json([
            'success' => true,
            'added_count' => count($results),
            'message' => 'Products added to cart successfully.',
        ]);

        // Persist the guest cookie so the next storefront request sees the cart.
        if (!$customerId && $isNewCookie) {
            $response->cookie('guest_id', $encryptedCookie, 60 * 24 * 30);
        }

        return $response;
    }

    /**
     * Resolve the guest cart identity, mirroring CartAPIController.
     *
     * @return array{0:?string,1:?string,2:bool} [cookieId, encryptedCookie, isNewCookie]
     */
    private function resolveGuestCartIdentity(Request $request, $customerId): array
    {
        if ($customerId) {
            return [null, null, false];
        }

        $cookie = $request->cookie('guest_id') ?? $request->input('cookie');

        if ($cookie) {
            try {
                return [\Illuminate\Support\Facades\Crypt::decrypt($cookie), $cookie, false];
            } catch (\Exception $e) {
                // Fall through — broken cookie, regenerate.
            }
        }

        $cookieId = \Illuminate\Support\Str::random(20);
        return [$cookieId, \Illuminate\Support\Facades\Crypt::encrypt($cookieId), true];
    }

    /**
     * Broadcast typing status.
     */
    public function typing(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'role'      => 'required|string|in:user,assistant',
            'is_typing' => 'required|boolean',
        ]);

        event(new \Anwar\GunmaAgent\Events\UserTyping(
            $sessionId,
            $validated['role'],
            $validated['is_typing']
        ));

        return response()->json(['status' => 'success']);
    }

    /**
     * Link guest chat session to a registered customer after login.
     * POST /api/admin/chat/link-session
     */
    public function linkSession(Request $request): JsonResponse
    {
        $request->validate([
            'visitor_id'  => 'required|string|max:64',
            'customer_id' => 'required|integer',
        ]);

        // Only allow binding the currently-authenticated customer, unless the
        // caller is an authenticated admin/staff user.
        $isStaff = false;
        foreach (config('gunma-agent.admin_guards', ['web', 'sanctum']) as $guard) {
            try {
                if (auth()->guard($guard)->check()) { $isStaff = true; break; }
            } catch (\Exception) { /* guard absent */ }
        }

        $authCustomerId = auth()->id();
        if (! $isStaff && (int) $authCustomerId !== (int) $request->customer_id) {
            abort(403, 'You can only link sessions to your own account.');
        }

        $customer = null;
        $model = config('gunma-agent.models.customer');
        if ($model && class_exists($model)) {
            $customer = $model::find($request->customer_id);
        }

        if (! $customer) {
            return response()->json(['error' => 'Customer not found.'], 404);
        }

        $name  = $customer->name ?? $customer->first_name ?? null;
        $email = $customer->email ?? null;

        $sessions = ChatSession::where('visitor_id', $request->visitor_id)
            ->whereNull('customer_id')
            ->get();

        foreach ($sessions as $session) {
            $session->update([
                'customer_id'   => $request->customer_id,
                'customer_name' => $name ?? $session->customer_name,
                'customer_email'=> $email ?? $session->customer_email,
            ]);

            event(new \Anwar\GunmaAgent\Events\SessionLinked(
                sessionId: $session->id,
                customerName: $name,
                customerEmail: $email,
            ));
        }

        return response()->json([
            'linked_sessions' => count($sessions),
            'customer_id'       => $request->customer_id,
            'customer_name'     => $name,
        ]);
    }

    /**
     * Submit customer feedback for a chat session.
     * POST /api/admin/chat/sessions/{session}/feedback
     */
    public function feedback(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $session = ChatSession::find($sessionId);
        if (! $session) {
            return response()->json(['error' => 'Chat session not found.'], 404);
        }

        DB::table('chat_feedback')->updateOrInsert(
            ['session_id' => $sessionId],
            [
                'rating'     => $validated['rating'],
                'comment'    => $validated['comment'] ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'session_id' => $sessionId,
            'rating'     => $validated['rating'],
        ]);
    }

    /**
     * Set the display name/email for a guest chat session (pre-chat form).
     * PUT /api/chat/sessions/{session}/profile
     */
    public function updateGuestProfile(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255',
        ]);

        $session = ChatSession::find($sessionId);
        if (! $session) {
            return response()->json(['error' => 'Chat session not found.'], 404);
        }

        $this->assertSessionOwnership($request, $session);

        $session->update([
            'customer_name'  => $validated['name'],
            'customer_email' => $validated['email'],
        ]);

        return response()->json([
            'session_id'     => $sessionId,
            'customer_name'  => $validated['name'],
            'customer_email' => $validated['email'],
        ]);
    }
}
