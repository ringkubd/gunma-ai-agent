# Gunma AI Agent (Laravel Package)

Modern, tool-calling AI agent backend for Gunma Halal Food. Orchestrates OpenAI, Qdrant, and real-time support channels.

## Core Features
- **Piku Orchestrator**: Humanoid AI agent with tool-calling capabilities.
- **Omnichannel Support**: Seamlessly handles Web Chat, Email (IMAP/Webhook), and Support Tickets.
- **Smart Order Logic**: Intelligent order status reasoning and cancellation prevention for shipped items.
- **Vector Search (Qdrant)**: High-speed semantic search for products and knowledge base.
- **Support Tickets**: Dedicated `support_tickets` table for formal complaints and claims.
- **Vision Support**: Analyzes customer-uploaded photos for damage claims.

## Installation & Update

### Install
```bash
composer require anwar/gunma-ai-agent
```

### Update
```bash
composer update anwar/gunma-ai-agent
```

## Setup & Configuration

1. **Publish Config**:
```bash
php artisan vendor:publish --provider="Anwar\GunmaAgent\GunmaAgentServiceProvider" --tag=gunma-agent-config
```

2. **Environment Variables (.env)**:
```env
# AI & Search
GUNMA_OPENAI_API_KEY=your_key
GUNMA_QDRANT_URL=http://localhost:6333
GUNMA_WEBSITE_URL=https://yourstore.com

# Real-time (Pusher/Soketi)
PUSHER_APP_ID=...
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...
PUSHER_HOST=localhost
PUSHER_PORT=6001
PUSHER_SCHEME=http

# Model Resolution (Optional)
GUNMA_MODEL_ORDER=App\Models\Order
GUNMA_MODEL_PRODUCT=App\Models\Product
```

## Development & Pushing to GitHub
If you are modifying the package locally in the `packages/` directory:
1. **Navigate to the package**: `cd packages/gunma-ai-agent`
2. **Commit changes**: `git add . && git commit -m "your message"`
3. **Push to GitHub**: `git push origin main`
4. **Update Host App**: Run `composer update` in your main Laravel app.

## Architecture
- **ToolExecutor**: The brain that connects AI to your Laravel database and services.
- **AgentOrchestrator**: Manages the message loop, context window, and streaming.
- **Events**: Uses `MessageBroadcasted`, `AiStatusChanged`, and `UserTyping` for real-time sync via Laravel Echo.

## License
MIT © Anwar

