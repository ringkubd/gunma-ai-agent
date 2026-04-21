# Gunma AI Agent (Laravel Core)

The core brain of the Gunma Halal Food AI ecosystem. This package handles natural language processing, vector search (Qdrant), tool execution (Order/Cart/Customer), and real-time monitoring.

## Features
- **RAG & Vector Search**: Deep integration with Qdrant for products, recipes, and knowledge base.
- **Smart Tools**: Real-time order status, customer profile lookup, and direct "Add to Cart" functionality.
- **Human Takeover**: Stop AI responses and respond manually from a dashboard.
- **Real-time Streaming**: SSE (Server-Sent Events) for low-latency AI responses.
- **Scout Support**: Custom Qdrant engine for Laravel Scout.

## Installation

1. Require the package (if using local path):
```json
"repositories": [
    {
        "type": "path",
        "url": "./packages/gunma-ai-agent"
    }
],
"require": {
    "anwar/gunma-ai-agent": "dev-main"
}
```

2. Run the install command:
```bash
php artisan gunma:install
```

3. Run migrations:
```bash
php artisan migrate
```

4. Configure your `.env`:
```env
GUNMA_OPENAI_API_KEY=sk-...
GUNMA_QDRANT_URL=http://localhost:6333
GUNMA_WEBSITE_URL=https://your-frontend.com
```

## Commands
- `php artisan gunma:index Product`: Deep-index products into Qdrant.
- `php artisan gunma:setup-collections`: Initialize Qdrant collections.

## Admin Dashboard
Access the monitor API at `/api/admin/chat/sessions`. You can configure the middleware (e.g., `auth:admin`) in `config/gunma-agent.php`.
