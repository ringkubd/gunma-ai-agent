<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    */
    'openai_api_key'     => env('GUNMA_OPENAI_API_KEY'),
    'openai_base_url'    => env('GUNMA_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'openai_model'       => env('GUNMA_OPENAI_MODEL', 'gpt-5.4-mini'),
    'openai_embed_model' => env('GUNMA_OPENAI_EMBED_MODEL', 'text-embedding-3-small'),

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration (Local Embeddings)
    |--------------------------------------------------------------------------
    */
    'ollama_url'         => env('GUNMA_OLLAMA_URL', 'http://localhost:11434'),
    'ollama_embed_model' => env('GUNMA_OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
    'ollama_chat_model'  => env('GUNMA_OLLAMA_CHAT_MODEL', 'gunma-halal-ai:latest'),

    /*
    |--------------------------------------------------------------------------
    | LLM Provider (runtime-switchable)
    |--------------------------------------------------------------------------
    | Any OpenAI-compatible /v1 endpoint works here: Ollama, OpenAI, DeepSeek,
    | OpenRouter, Groq, Gemini (compatibility). Values can be overridden at
    | runtime from the admin dashboard via the agent_settings table.
    |
    | Default is the low-cost Ollama Cloud DeepSeek model with an OpenAI-style
    | fallback. llm.api_key is optional for local Ollama.
    */
    'llm' => [
        'provider'         => env('GUNMA_LLM_PROVIDER', 'ollama'),
        'base_url'         => env('GUNMA_LLM_BASE_URL', 'http://127.0.0.1:11434/v1'),
        'api_key'          => env('GUNMA_LLM_API_KEY', 'ollama'),
        'model'            => env('GUNMA_LLM_MODEL', 'deepseek-v4.1-flash:cloud'),

        'fallback_enabled' => env('GUNMA_LLM_FALLBACK_ENABLED', true),
        'fallback_base_url' => env('GUNMA_LLM_FALLBACK_BASE_URL', env('GUNMA_OPENAI_BASE_URL', 'https://api.openai.com/v1')),
        'fallback_api_key'  => env('GUNMA_LLM_FALLBACK_API_KEY', env('GUNMA_OPENAI_API_KEY')),
        'fallback_model'    => env('GUNMA_LLM_FALLBACK_MODEL', env('GUNMA_OPENAI_MODEL', 'gpt-4o-mini')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Provider (runtime-switchable)
    |--------------------------------------------------------------------------
    | Must match the Qdrant collection dimensions. Ollama nomic-embed-text = 768,
    | OpenAI text-embedding-3-small = 1536. Switching provider requires a full
    | reindex (collections must be recreated at the new dimension).
    */
    'embedding' => [
        'provider' => env('GUNMA_EMBEDDING_PROVIDER', 'ollama'),
        'base_url' => env('GUNMA_EMBEDDING_BASE_URL', 'http://127.0.0.1:11434/v1'),
        'api_key'  => env('GUNMA_EMBEDDING_API_KEY', 'ollama'),
        'model'    => env('GUNMA_EMBEDDING_MODEL', 'nomic-embed-text'),
        'dims'     => (int) env('GUNMA_EMBEDDING_DIMS', 768),
        'batch_size' => (int) env('GUNMA_EMBEDDING_BATCH_SIZE', 32),
    ],

    /*
    |--------------------------------------------------------------------------
    | Qdrant Vector Database
    |--------------------------------------------------------------------------
    */
    'qdrant_url'         => env('GUNMA_QDRANT_URL', 'http://localhost:6333'),

    /*
    |--------------------------------------------------------------------------
    | Qdrant Environment Isolation
    |--------------------------------------------------------------------------
    | When beta and production share one Qdrant instance, set this to a unique
    | prefix per environment (e.g. "beta_" or "prod_"). All collection names
    | will be prefixed at runtime so data never mixes.
    */
    'qdrant_collection_prefix' => env('GUNMA_COLLECTION_PREFIX', ''),

    'qdrant_collections' => [
        'products' => env('GUNMA_COLLECTION_PRODUCTS', 'products'),
        'recipes'  => env('GUNMA_COLLECTION_RECIPES', 'recipes'),
        'kb'       => env('GUNMA_COLLECTION_KB', 'gunmahal_kb'),
        'memories' => env('GUNMA_COLLECTION_MEMORIES', 'chat_memories'),
        'cache'    => env('GUNMA_COLLECTION_CACHE', 'chat_cache'),
        'history'  => env('GUNMA_COLLECTION_HISTORY', 'purchase_history'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Cache Settings
    |--------------------------------------------------------------------------
    | Threshold for semantic cache matching (0.0 to 1.0).
    | Higher means more precise matches required.
    */
    'semantic_cache_enabled'   => env('GUNMA_SEMANTIC_CACHE_ENABLED', true),
    'semantic_cache_threshold' => env('GUNMA_SEMANTIC_CACHE_THRESHOLD', 0.88),

    /*
    |--------------------------------------------------------------------------
    | Customer Insight Analysis
    |--------------------------------------------------------------------------
    | Analyzes order history to build personalized purchase patterns.
    | Used by reorder_suggestions and frequently_bought_together tools.
    */
    'customer_insight' => [
        'min_orders_for_analysis' => 2,
        'reorder_window_days'     => 21,
        'max_suggestions'         => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Scout Integration
    |--------------------------------------------------------------------------
    | If enabled, the package will register a 'qdrant' engine for Laravel Scout.
    | You must have laravel/scout installed.
    */
    'scout_enabled' => env('GUNMA_SCOUT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Website URL (for product links and cart buttons)
    |--------------------------------------------------------------------------
    */
    'website_url'        => env('GUNMA_WEBSITE_URL', 'https://gunmahalalfood.com'),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limit'         => (int) env('GUNMA_RATE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Queue Embeddings
    |--------------------------------------------------------------------------
    | When true, product/order observers dispatch embedding work to the queue
    | instead of doing it synchronously in the request path.
    */
    'queue_embeddings'   => env('GUNMA_QUEUE_EMBEDDINGS', true),

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    | admin_guards: guards treated as admin/staff. They bypass per-session
    |   ownership checks and may link sessions to any customer.
    | enforce_session_ownership: when true, public session endpoints require
    |   the caller to own the session (customer_id or matching visitor_id).
    |   The widget must send the X-Visitor-Id header (added in Phase 3), so this
    |   defaults to false until the widget is updated; set GUNMA_ENFORCE_SESSION_OWNERSHIP=true
    |   once the widget and dashboard send X-Visitor-Id.
    | email_webhook_secret: shared secret for the incoming-email webhook. When
    |   set, requests must include a matching X-Webhook-Secret header.
    */
    'admin_guards'               => explode('|', env('GUNMA_ADMIN_GUARDS', 'web|sanctum')),
    'enforce_session_ownership'  => env('GUNMA_ENFORCE_SESSION_OWNERSHIP', false),
    'email_webhook_secret'       => env('GUNMA_EMAIL_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Agent Loop Settings
    |--------------------------------------------------------------------------
    | Maximum number of tool-call iterations before forcing a final response.
    | Prevents infinite loops from malicious or ambiguous prompts.
    */
    'max_tool_iterations' => (int) env('GUNMA_MAX_TOOL_ITERATIONS', 5),

    /*
    |--------------------------------------------------------------------------
    | Session Settings
    |--------------------------------------------------------------------------
    */
    'session_ttl'        => (int) env('GUNMA_SESSION_TTL', 86400),    // 24h Redis TTL
    'max_history'        => (int) env('GUNMA_MAX_HISTORY', 20),       // Messages in context window

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    */
    'route_prefix'       => env('GUNMA_ROUTE_PREFIX', 'api/chat'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */
    'middleware'          => explode('|', env('GUNMA_MIDDLEWARE', 'api')),

    /*
    |--------------------------------------------------------------------------
    | Auth Guard Resolution Order
    |--------------------------------------------------------------------------
    | The ResolveCustomer middleware tries these guards in order to silently
    | resolve the logged-in user for both guest and authenticated requests.
    |
    | Common values:
    |   'customer'  — custom guard in host app (e.g. Laravel Passport customers)
    |   'sanctum'   — Laravel Sanctum (token or session)
    |   'web'       — standard session-based auth
    |   'api'       — token-based (Passport or custom)
    |
    | Set GUNMA_AUTH_GUARDS=customer|sanctum in your .env to override.
    */
    'auth_guards' => explode('|', env('GUNMA_AUTH_GUARDS', 'customer|sanctum|web')),

    /*
    |--------------------------------------------------------------------------
    | Host App Model Resolution
    |--------------------------------------------------------------------------
    | Configurable model class names for tool executor.
    | Allows the package to work without hard-coded dependencies on the host app.
    */
    'models' => [
        'customer'   => env('GUNMA_MODEL_CUSTOMER', \App\Models\Customer::class),
        'order'      => env('GUNMA_MODEL_ORDER', \App\Models\Order::class),
        'product'    => env('GUNMA_MODEL_PRODUCT', \App\Models\Product::class),
        'cart'       => env('GUNMA_MODEL_CART', \App\Models\Cart::class),
        'stock'      => env('GUNMA_MODEL_STOCK', \App\Models\Stock::class),
        'post_code'  => env('GUNMA_MODEL_POST_CODE', \App\Models\PostCode::class),
        'review'     => env('GUNMA_MODEL_REVIEW', \App\Models\Review::class),
        'coupon'     => env('GUNMA_MODEL_COUPON', \App\Models\Coupon::class),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chatwoot Integration
    |--------------------------------------------------------------------------
    | Unify WhatsApp / Facebook / Instagram / Email / Website chat under one
    | Chatwoot inbox. Inbound messages are auto-answered by Piku.
    |
    | base_url       : e.g. https://support.gunmahalalfood.com
    | api_key        : Chatwoot API access token (Settings → API)
    | account_id     : Chatwoot account id (usually 1)
    | shared_secret  : simple shared secret expected in X-Webhook-Secret
    | webhook_secret : HMAC secret used to verify X-Chatwoot-Signature
    */
    'chatwoot' => [
        'base_url'       => env('CHATWOOT_BASE_URL'),
        'api_key'        => env('CHATWOOT_API_KEY'),
        'account_id'     => (int) env('CHATWOOT_ACCOUNT_ID', 1),
        'shared_secret'  => env('CHATWOOT_WEBHOOK_SECRET'),
        'webhook_secret' => env('CHATWOOT_HMAC_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CORS
    |--------------------------------------------------------------------------
    */
    'cors_origins'       => explode(',', env('GUNMA_CORS_ORIGINS', '*')),

    /*
    |--------------------------------------------------------------------------
    | Admin Panel Settings
    |--------------------------------------------------------------------------
    */
    'admin_route_prefix' => env('GUNMA_ADMIN_PREFIX', 'api/admin/chat'),
    'admin_middleware'   => explode('|', env('GUNMA_ADMIN_MIDDLEWARE', 'web')),

    /*
    |--------------------------------------------------------------------------
    | Broadcasting Channel Names
    |--------------------------------------------------------------------------
    | These must match the broadcastChannel option in useMonitor (dashboard)
    | and the channel prefix used in useChat (widget).
    */
    'broadcast_admin_channel' => env('GUNMA_BROADCAST_ADMIN_CHANNEL', 'gunma-admin.chats'),
    'broadcast_chat_prefix'   => env('GUNMA_BROADCAST_CHAT_PREFIX',   'gunma-chat'),
];
