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
    'ollama_url'         => env('GUNMA_OLLAMA_URL', 'http://localhost:11435'),
    'ollama_embed_model' => env('GUNMA_OLLAMA_EMBED_MODEL', 'nomic-embed-text'),

    /*
    |--------------------------------------------------------------------------
    | Qdrant Vector Database
    |--------------------------------------------------------------------------
    */
    'qdrant_url'         => env('GUNMA_QDRANT_URL', 'http://localhost:6333'),
    'qdrant_collections' => [
        'products' => env('GUNMA_COLLECTION_PRODUCTS', 'products'),
        'recipes'  => env('GUNMA_COLLECTION_RECIPES', 'recipes'),
        'kb'       => env('GUNMA_COLLECTION_KB', 'gunmahal_kb'),
        'memories' => env('GUNMA_COLLECTION_MEMORIES', 'chat_memories'),
        'cache'    => env('GUNMA_COLLECTION_CACHE', 'chat_cache'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Cache Settings
    |--------------------------------------------------------------------------
    | Threshold for semantic cache matching (0.0 to 1.0).
    | Higher means more precise matches required.
    */
    'semantic_cache_enabled'   => env('GUNMA_SEMANTIC_CACHE_ENABLED', true),
    'semantic_cache_threshold' => env('GUNMA_SEMANTIC_CACHE_THRESHOLD', 0.95),

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
    | Host App Model Resolution
    |--------------------------------------------------------------------------
    | Configurable model class names for tool executor.
    | Allows the package to work without hard-coded dependencies on the host app.
    */
    'models' => [
        'order'   => env('GUNMA_MODEL_ORDER', \App\Models\Order::class),
        'product' => env('GUNMA_MODEL_PRODUCT', \App\Models\Product::class),
        'cart'    => env('GUNMA_MODEL_CART', \App\Models\Cart::class),
        'stock'   => env('GUNMA_MODEL_STOCK', \App\Models\Stock::class),
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
];
