<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Http\Controllers;

use Anwar\GunmaAgent\Models\ChatMessage;
use Anwar\GunmaAgent\Models\ChatSession;
use Anwar\GunmaAgent\Services\AgentOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly AgentOrchestrator $agent,
    ) {}

    /* ── POST /chat/sessions — Create a new chat session ───────── */

    public function createSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visitor_id'     => 'required|string|max:64',
            'customer_name'  => 'nullable|string|max:255',
            'channel'        => 'nullable|in:web,admin,whatsapp',
            'metadata'       => 'nullable|array',
        ]);

        // Find existing active session or create new one
        $session = ChatSession::where('visitor_id', $validated['visitor_id'])
            ->where('channel', $validated['channel'] ?? 'web')
            ->active()
            ->first();

        if (! $session) {
            $session = ChatSession::create([
                'visitor_id'    => $validated['visitor_id'],
                'customer_name' => $validated['customer_name'] ?? null,
                'channel'       => $validated['channel'] ?? 'web',
                'status'        => 'active',
                'metadata'      => $validated['metadata'] ?? null,
            ]);
        }

        return response()->json([
            'session' => $session,
        ], 201);
    }

    /* ── GET /chat/sessions/{id} — Get session with messages ───── */

    public function showSession(string $id): JsonResponse
    {
        $session = ChatSession::with(['messages' => function ($query) {
            $query->whereIn('role', ['user', 'assistant'])->orderBy('created_at');
        }])->findOrFail($id);

        return response()->json([
            'session' => $session,
        ]);
    }

    /* ── POST /chat/sessions/{id}/messages — Send message (SSE) ── */

    public function sendMessage(Request $request, string $id): StreamedResponse
    {
        $session = ChatSession::findOrFail($id);

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
        $limit   = min((int) ($request->query('limit', 50)), 100);

        $messages = ChatMessage::where('session_id', $session->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->take($limit)
            ->get()
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

    public function endSession(string $id): JsonResponse
    {
        $session = ChatSession::findOrFail($id);
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
        return [
            'Content-Type'                => 'text/event-stream',
            'Cache-Control'               => 'no-cache',
            'Connection'                  => 'keep-alive',
            'X-Accel-Buffering'           => 'no',
            'Access-Control-Allow-Origin' => '*',
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
            ->paginate(20);

        return response()->json($sessions);
    }

    /**
     * Get details of a specific session.
     */
    public function getSession(string $sessionId): JsonResponse
    {
        $session = ChatSession::with(['messages' => fn($q) => $q->latest()->take(50)])
            ->findOrFail($sessionId);

        return response()->json($session);
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
     * Get basic analytics for the dashboard.
     */
    public function getStats(): JsonResponse
    {
        return response()->json([
            'total_sessions'   => ChatSession::count(),
            'active_sessions'  => ChatSession::active()->count(),
            'total_messages'   => ChatMessage::count(),
            'manual_sessions'  => ChatSession::where('is_ai_enabled', false)->count(),
        ]);
    }

    /**
     * Bulk Add to Cart (Proxy to core backend logic or direct DB access)
     */
    public function bulkAddToCart(Request $request): JsonResponse
    {
        $productIds = $request->input('product_ids', []);
        $cookie = $request->input('cookie');

        if (empty($productIds)) {
            return response()->json(['error' => 'No products provided.'], 422);
        }

        // Config-based model resolution (no hard-coded class names)
        $cartModel = config('gunma-agent.models.cart', \App\Models\Cart::class);
        $stockModel = config('gunma-agent.models.stock', \App\Models\Stock::class);

        if (!class_exists($cartModel) || !class_exists($stockModel)) {
            return response()->json(['error' => 'Cart models not available.'], 500);
        }

        $results = [];
        $customerId = auth('customer')->id();
        $cookieId = null;

        if (!$customerId && $cookie) {
            try {
                $cookieId = \Illuminate\Support\Facades\Crypt::decrypt($cookie);
            } catch (\Exception $e) {
                $cookieId = $cookie; // fallback
            }
        }

        foreach ($productIds as $id) {
            $stock = $stockModel::where('product_id', $id)->latest('id')->first();
            $price = $stock ? $stock->online_price : 0;

            $data = [
                'product_id' => $id,
                'product_option_id' => "",
                'quantity' => 1,
                'item_price' => $price,
                'discount_amount' => 0,
                'tax_percent' => 8,
            ];

            if ($customerId) {
                $data['customer_id'] = $customerId;
                $duplicate = $cartModel::where('product_id', $id)->where('customer_id', $customerId)->first();
            } else {
                $data['cookie_id'] = $cookieId;
                $duplicate = $cartModel::where('product_id', $id)->where('cookie_id', $cookieId)->first();
            }

            if ($duplicate) {
                $duplicate->increment('quantity');
                $results[] = $duplicate;
            } else {
                $results[] = $cartModel::create($data);
            }
        }

        // Broadcast event if it exists in core
        try {
            if (class_exists('\App\Events\CartUpdated')) {
                $eventCookieId = $customerId ? null : $cookieId;
                event(new \App\Events\CartUpdated($eventCookieId, 'bulk_added', ['count' => count($results)], $customerId));
            }
        } catch (\Exception $e) {
            \Log::error('AI Bulk Add Cart event failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'added_count' => count($results),
            'message' => 'Products added to cart successfully.'
        ]);
    }
}
