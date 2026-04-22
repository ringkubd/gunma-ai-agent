# Gunma AI Agent (Laravel Package)

Modern, tool-calling AI agent backend for Gunma Halal Food. Orchestrates OpenAI, Qdrant, and real-time support channels.

## Core Features
- **Piku Orchestrator**: Humanoid AI agent with tool-calling capabilities.
- **Omnichannel Support**: Seamlessly handles Web Chat, Email (IMAP/Webhook), and Support Tickets.
- **Smart Order Logic**: Intelligent order status reasoning and cancellation prevention for shipped items.
- **Vector Search (Qdrant)**: High-speed semantic search for products and knowledge base.
- **Support Tickets**: Dedicated `support_tickets` table for formal complaints and claims.
- **Vision Support**: Analyzes customer-uploaded photos for damage claims.

## Installation
```bash
composer require anwar/gunma-ai-agent
```

## Setup
1. Publish migrations and config:
```bash
php artisan vendor:publish --provider="Anwar\GunmaAgent\GunmaAgentServiceProvider"
```
2. Run migrations:
```bash
php artisan migrate
```

## Architecture
- **ToolExecutor**: The brain that connects AI to your Laravel database and services.
- **AgentOrchestrator**: Manages the message loop, context window, and streaming.
- **EmailWebhookController**: Handles incoming support emails.

## License
MIT © Anwar
