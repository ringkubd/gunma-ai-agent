# Gunma AI Agent — Package Integration Guide

> **Packages covered:** `anwar/gunma-ai-agent` (Laravel) · `gunma-chat-widget` (React) · `gunma-agent-dashboard` (React)

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Backend Package — `gunma-ai-agent`](#2-backend-package--gunma-ai-agent)
   - [Installation](#21-installation)
   - [Configuration Reference](#22-configuration-reference)
   - [Authentication — How It Works](#23-authentication--how-it-works)
   - [Route Map](#24-route-map)
3. [Chat Widget — `gunma-chat-widget`](#3-chat-widget--gunma-chat-widget)
   - [Installation](#31-installation)
   - [Guest Usage](#32-guest-usage)
   - [Authenticated Customer Usage](#33-authenticated-customer-usage)
   - [Full Config Reference](#34-full-config-reference)
4. [Admin Dashboard — `gunma-agent-dashboard`](#4-admin-dashboard--gunma-agent-dashboard)
   - [Installation](#41-installation)
   - [Auth Patterns](#42-auth-patterns)
   - [Full Options Reference](#43-full-options-reference)
5. [Cross-Package Auth Flow Diagrams](#5-cross-package-auth-flow-diagrams)
6. [Cookbook — Common Scenarios](#6-cookbook--common-scenarios)
7. [Environment Variable Reference](#7-environment-variable-reference)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        Host Laravel App                         │
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │              anwar/gunma-ai-agent  (PHP package)         │  │
│  │                                                          │  │
│  │  routes/api.php ──► ChatController                       │  │
│  │       │                    │                             │  │
│  │  ResolveCustomer      AgentOrchestrator                 │  │
│  │  Middleware            (OpenAI + Qdrant)                 │  │
│  │  (tries guards:                │                         │  │
│  │   customer/sanctum/web)   SSE stream                     │  │
│  │       │                        │                         │  │
│  │  auth()->user()          Broadcasting                    │  │
│  │  (or null for guest)     (Pusher/Soketi)                │  │
│  └──────────────────────────────────────────────────────────┘  │
│            ▲                       ▲                            │
│            │ HTTP + SSE            │ WebSocket                  │
│            │                       │                            │
│   ┌────────┴────────┐   ┌──────────┴─────────┐                │
│   │ gunma-chat-     │   │  gunma-agent-       │                │
│   │ widget (React)  │   │  dashboard (React)  │                │
│   │                 │   │                     │                │
│   │ Guest ✓         │   │  Admin only         │                │
│   │ Authenticated ✓ │   │  (web/sanctum auth) │                │
│   └─────────────────┘   └─────────────────────┘                │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Backend Package — `gunma-ai-agent`

### 2.1 Installation

```bash
composer require anwar/gunma-ai-agent

php artisan vendor:publish --tag=gunma-agent-config
php artisan vendor:publish --tag=gunma-agent-migrations
php artisan migrate
```

### 2.2 Configuration Reference

All keys are in `config/gunma-agent.php`. Every value can be overridden via `.env`.

| Config Key | Env Variable | Default | Description |
|---|---|---|---|
| `route_prefix` | `GUNMA_ROUTE_PREFIX` | `api/chat` | Public chat route prefix |
| `admin_route_prefix` | `GUNMA_ADMIN_PREFIX` | `api/admin/chat` | Admin route prefix |
| `middleware` | `GUNMA_MIDDLEWARE` | `api` | Middleware stack for public routes (pipe-separated in `.env`) |
| `admin_middleware` | `GUNMA_ADMIN_MIDDLEWARE` | `web` | Middleware for admin routes |
| `auth_guards` | `GUNMA_AUTH_GUARDS` | `customer\|sanctum\|web` | Guards tried by `ResolveCustomer` middleware (pipe-separated) |
| `broadcast_admin_channel` | `GUNMA_BROADCAST_ADMIN_CHANNEL` | `gunma-admin.chats` | Private channel for admin dashboard |
| `broadcast_chat_prefix` | `GUNMA_BROADCAST_CHAT_PREFIX` | `gunma-chat` | Channel prefix for per-session chat rooms |

### 2.3 Authentication — How It Works

The package uses a **`ResolveCustomer` middleware** on all public chat routes. It is **non-blocking** — guests are never rejected; authenticated users are silently identified.

#### The Resolution Flow

```
Incoming request
      │
      ▼
Does request have Authorization: Bearer <token> header?
      │
      ├─ YES ─► Try each guard in auth_guards order:
      │            1. 'customer' guard (Laravel Passport)
      │            2. 'sanctum' guard (Sanctum token)
      │            3. 'api' guard (custom token driver)
      │         If a guard succeeds → auth()->shouldUse(that guard)
      │                              → auth()->user() is available
      │
      ├─ NO, but has session cookie ─► Try session-based guards:
      │            1. 'web' guard
      │            2. 'sanctum' guard (Sanctum session)
      │         If a guard succeeds → auth()->shouldUse(that guard)
      │
      └─ NO auth at all ─► auth()->user() returns null → Guest mode
```

#### What Changes When a Customer is Authenticated

| Feature | Guest | Authenticated Customer |
|---|---|---|
| Session creation | `visitor_id` based | `customer_id` + `visitor_id` |
| Display name | From `customer_name` param | Auto-read from `user->name` |
| Session resumption | By `visitor_id` | By `customer_id` (more reliable) |
| Anonymous → auth upgrade | N/A | Existing session gets `customer_id` bound |
| Cart operations | Cookie-based | Token-based (cookie ignored) |

#### Configuring Your Auth Guard

**Sanctum token authentication:**
```env
GUNMA_AUTH_GUARDS=sanctum|web
GUNMA_MIDDLEWARE=api|throttle:60,1
```

**Laravel Passport with a `customer` guard:**
```env
GUNMA_AUTH_GUARDS=customer|sanctum|web
GUNMA_MIDDLEWARE=api|throttle:60,1
```

**Session-only (SPA with Sanctum cookie):**
```env
GUNMA_AUTH_GUARDS=sanctum|web
GUNMA_MIDDLEWARE=web|throttle:60,1
```

**No authentication (pure public widget):**
```env
GUNMA_AUTH_GUARDS=
GUNMA_MIDDLEWARE=api|throttle:60,1
```

#### Accessing the User in Your Own Code

After installing the package, you can check customer identity in custom listeners or observers:

```php
// In any code running within a request
$user = auth()->user();   // null for guests

if ($user) {
    // Authenticated customer
    $customerId = $user->id;
}
```

### 2.4 Route Map

#### Public Routes (Guest + Authenticated)

| Method | Path | Description |
|---|---|---|
| `POST` | `/{prefix}/sessions` | Create or resume a chat session |
| `GET` | `/{prefix}/sessions/{id}` | Get session details + messages |
| `POST` | `/{prefix}/sessions/{id}/messages` | Send message (SSE streaming) |
| `POST` | `/{prefix}/sessions/{id}/messages/sync` | Send message (JSON, no streaming) |
| `GET` | `/{prefix}/sessions/{id}/messages` | Get message history |
| `POST` | `/{prefix}/sessions/{id}/end` | End session |
| `POST` | `/{prefix}/sessions/{id}/typing` | Broadcast typing indicator |
| `POST` | `/{prefix}/cart/bulk` | Bulk add-to-cart |
| `POST` | `/{prefix}/upload` | Upload image file |

> Default `{prefix}` = `api/chat`. Override with `GUNMA_ROUTE_PREFIX`.

#### Admin Routes (Authenticated Only)

| Method | Path | Description |
|---|---|---|
| `GET` | `/{admin_prefix}/sessions` | List all sessions |
| `GET` | `/{admin_prefix}/sessions/{id}` | Get session detail |
| `POST` | `/{admin_prefix}/sessions/{id}/toggle-ai` | Toggle AI for session |
| `POST` | `/{admin_prefix}/sessions/{id}/messages` | Send manual message |
| `POST` | `/{admin_prefix}/sessions/{id}/typing` | Broadcast typing |
| `GET` | `/{admin_prefix}/stats` | Dashboard statistics |
| `GET` | `/{admin_prefix}/tickets` | List support tickets |
| `POST` | `/{admin_prefix}/tickets/{id}/status` | Update ticket status |

> Default `{admin_prefix}` = `api/admin/chat`. Override with `GUNMA_ADMIN_PREFIX`.
> Admin middleware defaults to `web`. For Sanctum SPA: `GUNMA_ADMIN_MIDDLEWARE=web|auth:sanctum`.

---

## 3. Chat Widget — `gunma-chat-widget`

### 3.1 Installation

```bash
npm install gunma-chat-widget
```

```tsx
import 'gunma-chat-widget/styles.css';
import { ChatWidget } from 'gunma-chat-widget';
```

### 3.2 Guest Usage

No authentication needed. The widget creates an anonymous session using a browser-generated `visitor_id`.

```tsx
<ChatWidget
  apiUrl="https://api.yourdomain.com"
  brandName="Your Store"
  welcomeMessage="Hi! How can I help you today?"
/>
```

**How it works internally:**
- A `visitor_id` is generated and stored in `localStorage['gunma_visitor_id']`
- Session ID is persisted in `localStorage['gunma_session_id']`
- For cart operations, the host app cookie is read from `localStorage['gunma_cookie']`
- No `Authorization` header is sent → backend serves as guest

### 3.3 Authenticated Customer Usage

Pass the customer's token so the backend can link the chat to their account.

#### Option A: Direct token (simplest)

```tsx
// Read from wherever your auth system stores it
const token = localStorage.getItem('customer_token');

<ChatWidget
  apiUrl="https://api.yourdomain.com"
  apiToken={token ?? undefined}
  brandName="Your Store"
/>
```

#### Option B: Dynamic resolver (Redux / Zustand / Context)

```tsx
import { useSelector } from 'react-redux';

function MyApp() {
  const authToken = useSelector(state => state.auth.token);

  return (
    <ChatWidget
      apiUrl="https://api.yourdomain.com"
      getToken={() => authToken}  // re-evaluated on each request
      brandName="Your Store"
    />
  );
}
```

#### Option C: Custom localStorage key

```tsx
<ChatWidget
  apiUrl="https://api.yourdomain.com"
  storage={{ tokenKeys: ['customer_access_token', 'auth_token'] }}
  brandName="Your Store"
/>
```

#### Option D: Sanctum SPA (session cookie, no token)

When using Sanctum with cookie-based sessions (same domain), no token is needed. The CSRF cookie is automatically handled:

```tsx
<ChatWidget
  apiUrl="https://api.yourdomain.com"
  // No apiToken needed — session cookie is sent automatically (withCredentials)
  brandName="Your Store"
/>
```

> **Backend requirement:** Set `GUNMA_MIDDLEWARE=web` (not `api`) so the session middleware is active.

### 3.4 Full Config Reference

```ts
interface ChatWidgetConfig {
  // ── Required ────────────────────────────────────────────────
  apiUrl: string;               // Backend base URL

  // ── Branding ────────────────────────────────────────────────
  brandName?: string;           // Widget header title
  brandColor?: string;          // Primary color (hex/hsl)
  welcomeMessage?: string;      // First message shown to user
  placeholder?: string;         // Input placeholder text
  position?: 'bottom-right' | 'bottom-left';  // Default: 'bottom-right'
  theme?: 'light' | 'dark' | 'auto';          // Default: 'auto'
  zIndex?: number;              // CSS z-index (default: 9999)
  websiteUrl?: string;          // Used for product link generation

  // ── Identity ─────────────────────────────────────────────────
  visitorId?: string;           // Override auto-generated visitor ID
  customerName?: string;        // Display name override
  channel?: 'web' | 'admin';   // Defaults to 'web'
  cookieId?: string;            // Guest cart cookie override

  // ── Authentication (choose one) ──────────────────────────────
  apiToken?: string;            // Explicit Bearer token
  getToken?: () => string | null; // Dynamic token resolver

  // ── Route Configuration ───────────────────────────────────────
  routes?: {
    prefix?: string;            // Must match GUNMA_ROUTE_PREFIX. Default: 'api/chat'
    csrfCookie?: string;        // CSRF endpoint. Default: '/sanctum/csrf-cookie'
  };

  // ── Storage Key Overrides ─────────────────────────────────────
  storage?: {
    visitorIdKey?: string;      // Default: 'gunma_visitor_id'
    sessionIdKey?: string;      // Default: 'gunma_session_id'
    tokenKeys?: string[];       // Default: ['tk', 'token']
    // Note: cookieKey is on useCartActions config
  };

  // ── Real-Time (Pusher / Soketi) ───────────────────────────────
  pusher?: {
    key: string;                // Pusher app key (REQUIRED for real-time)
    cluster?: string;           // e.g. 'eu', 'ap3'
    wsHost?: string;            // Self-hosted Soketi host
    wsPort?: number;            // Default: 6001
    forceTLS?: boolean;         // Default: false
    authEndpoint?: string;      // Default: '/api/broadcasting/auth'
  };

  // ── Cart Integration ──────────────────────────────────────────
  cartUrl?: string;             // Host app single-product cart endpoint
                                // Example: 'https://mystore.com/customer/Frontend/Carts'
                                // Required for "Add to Cart" buttons in chat
}
```

---

## 4. Admin Dashboard — `gunma-agent-dashboard`

### 4.1 Installation

```bash
npm install gunma-agent-dashboard
```

```tsx
import 'gunma-agent-dashboard/styles.css';
import { AgentDashboard } from 'gunma-agent-dashboard';
// or use the hook directly:
import { useMonitor } from 'gunma-agent-dashboard';
```

### 4.2 Auth Patterns

The dashboard calls **admin-only routes** which require authenticated middleware on the backend. The hook sends the admin token on every request.

#### Session-based (Sanctum SPA)

```tsx
// Works when admin is logged in via the same domain session
<AgentDashboard
  apiUrl="https://api.yourdomain.com"
  pusher={{ key: 'your-pusher-key' }}
/>
```

> No token needed — session cookie is sent automatically (`withCredentials: true`).

#### Token-based (Sanctum Personal Access Token)

```tsx
<AgentDashboard
  apiUrl="https://api.yourdomain.com"
  auth={{ tokenKeys: ['admin_token'] }}
  pusher={{ key: 'your-pusher-key', cluster: 'eu' }}
/>
```

#### Dynamic token (Redux/Zustand)

```tsx
const token = useSelector(state => state.admin.token);

<AgentDashboard
  apiUrl="https://api.yourdomain.com"
  auth={{ getToken: () => token }}
  pusher={{ key: 'your-pusher-key' }}
/>
```

### 4.3 Full Options Reference

```ts
interface UseMonitorOptions {
  // ── Polling ────────────────────────────────────────────────────
  pollInterval?: number;        // Session list refresh interval (ms). Default: 15000. 0 = disabled.

  // ── Authentication ─────────────────────────────────────────────
  auth?: {
    tokenKeys?: string[];       // localStorage keys to try. Default: ['token', 'tk']
    getToken?: () => string | null; // Dynamic resolver (overrides tokenKeys)
  };

  // ── Real-Time (Pusher / Soketi) ────────────────────────────────
  pusher?: {
    key: string;                // REQUIRED for real-time. Omit to disable WebSockets.
    cluster?: string;
    wsHost?: string;
    wsPort?: number;            // Default: 6001
    forceTLS?: boolean;         // Default: false
    authEndpoint?: string;      // Default: '/api/broadcasting/auth'
  };

  // ── Broadcasting ───────────────────────────────────────────────
  broadcastChannel?: string;    // Must match GUNMA_BROADCAST_ADMIN_CHANNEL.
                                // Default: 'gunma-admin.chats'

  // ── Route Overrides ────────────────────────────────────────────
  routes?: {
    prefix?: string;            // Must match GUNMA_ADMIN_PREFIX. Default: '/api/admin/chat'
    sessions?: string;          // Full override for sessions path
    tickets?: string;           // Full override for tickets path
    stats?: string;             // Full override for stats path
    endSession?: string;        // Public session end path. Default: '/api/chat/sessions'
    csrfCookie?: string;        // Default: '/sanctum/csrf-cookie'
  };
}
```

---

## 5. Cross-Package Auth Flow Diagrams

### Guest Chat Flow

```
Browser (no token)
    │
    ├─ POST /api/chat/sessions  { visitor_id: "v_123..." }
    │       │
    │  ResolveCustomer middleware
    │  → No Authorization header, no session cookie
    │  → auth()->user() = null
    │       │
    │  ChatController::createSession()
    │  → customer_id = null
    │  → Creates session with visitor_id only
    │       │
    │  ◄── { session: { id: "...", visitor_id: "v_123..." } }
    │
    ├─ POST /api/chat/sessions/{id}/messages  { message: "..." }
    │       │
    │  SSE stream response ─────────────────────────────────►
    │                                                  Browser renders
```

### Authenticated Customer Chat Flow

```
Browser (has token in localStorage['tk'])
    │
    ├─ POST /api/chat/sessions
    │   Headers: { Authorization: "Bearer eyJ..." }
    │       │
    │  ResolveCustomer middleware
    │  → Finds Authorization header
    │  → Tries 'customer' guard → success
    │  → auth()->shouldUse('customer')
    │  → auth()->user() = Customer { id: 42, name: "Alice" }
    │       │
    │  ChatController::createSession()
    │  → customer_id = 42
    │  → customer_name auto-resolved = "Alice"
    │  → Searches existing session by customer_id first
    │  → Creates or upgrades session
    │       │
    │  ◄── { session: { id: "...", customer_id: 42, customer_name: "Alice" } }
```

### Admin Dashboard Auth Flow (Sanctum SPA)

```
Admin Browser (session cookie from same domain)
    │
    ├─ GET /sanctum/csrf-cookie  (useMonitor initialization)
    │  ◄── Set-Cookie: XSRF-TOKEN=...
    │
    ├─ GET /api/admin/chat/sessions
    │   Headers: {
    │     Cookie: "laravel_session=...",
    │     X-XSRF-TOKEN: "..."
    │   }
    │       │
    │  admin_middleware: ['web', 'auth']
    │  → Session resolved → admin user identified
    │       │
    │  ◄── { data: [...sessions] }
    │
    ├─ WebSocket: CONNECT → pusher-server
    ├─ POST /api/broadcasting/auth
    │   Headers: { Cookie: "...", X-XSRF-TOKEN: "..." }
    │   → Authorizes 'gunma-admin.chats' private channel
    │  ◄── { auth: "..." }
    │
    └─ Private channel subscribed → real-time events flowing
```

---

## 6. Cookbook — Common Scenarios

### Scenario A: Next.js SaaS — Sanctum SPA auth

```tsx
// Both widget and dashboard on same domain as backend
// No tokens needed — session cookies flow automatically

// Widget (customer-facing):
<ChatWidget
  apiUrl={process.env.NEXT_PUBLIC_API_URL}
  brandName="My Store AI"
  pusher={{
    key: process.env.NEXT_PUBLIC_PUSHER_KEY!,
    wsHost: process.env.NEXT_PUBLIC_PUSHER_HOST,
    wsPort: Number(process.env.NEXT_PUBLIC_PUSHER_PORT),
  }}
/>

// Dashboard (admin):
<AgentDashboard
  apiUrl={process.env.NEXT_PUBLIC_API_URL}
  pusher={{
    key: process.env.NEXT_PUBLIC_PUSHER_KEY!,
    wsHost: process.env.NEXT_PUBLIC_PUSHER_HOST,
    wsPort: Number(process.env.NEXT_PUBLIC_PUSHER_PORT),
  }}
/>
```

```env
# Backend .env
GUNMA_MIDDLEWARE=web|throttle:60,1
GUNMA_AUTH_GUARDS=sanctum|web
GUNMA_ADMIN_MIDDLEWARE=web|auth
```

---

### Scenario B: Decoupled API — Laravel Passport customers

```tsx
// Customer frontend (separate domain, Passport tokens)
const token = localStorage.getItem('passport_token');

<ChatWidget
  apiUrl="https://api.mystore.com"
  apiToken={token ?? undefined}
  routes={{ prefix: 'api/chat' }}
  pusher={{ key: 'abc123', cluster: 'eu', forceTLS: true }}
  cartUrl="https://api.mystore.com/customer/Frontend/Carts"
  storage={{ cookieKey: 'my_guest_cookie' }}
/>
```

```env
# Backend .env
GUNMA_MIDDLEWARE=api|throttle:60,1
GUNMA_AUTH_GUARDS=customer|sanctum
GUNMA_ADMIN_MIDDLEWARE=api|auth:sanctum
```

---

### Scenario C: Embedded widget in a non-Next.js app (Vanilla JS)

```html
<script>
  window.__gunmaConfig = {
    apiUrl: 'https://api.mystore.com',
    apiToken: document.querySelector('meta[name="customer-token"]')?.content,
    brandName: 'Support Chat',
    pusher: { key: 'abc123', cluster: 'ap3' },
  };
</script>
```

```tsx
// In your React mount point
<ChatWidget {...window.__gunmaConfig} />
```

---

### Scenario D: Different backend route prefixes

```env
# Backend .env — custom prefixes
GUNMA_ROUTE_PREFIX=api/v2/chat
GUNMA_ADMIN_PREFIX=api/v2/dashboard/chat
GUNMA_BROADCAST_ADMIN_CHANNEL=myapp.admin.chats
GUNMA_BROADCAST_CHAT_PREFIX=myapp.chat
```

```tsx
// Widget must match GUNMA_ROUTE_PREFIX
<ChatWidget
  apiUrl="https://api.mystore.com"
  routes={{ prefix: 'api/v2/chat' }}
/>

// Dashboard must match GUNMA_ADMIN_PREFIX and GUNMA_BROADCAST_ADMIN_CHANNEL
<AgentDashboard
  apiUrl="https://api.mystore.com"
  broadcastChannel="myapp.admin.chats"
  routes={{ prefix: '/api/v2/dashboard/chat' }}
/>
```

---

## 7. Environment Variable Reference

### Backend (`.env`)

| Variable | Default | Description |
|---|---|---|
| `GUNMA_OPENAI_API_KEY` | — | OpenAI API key |
| `GUNMA_OPENAI_MODEL` | `gpt-4o-mini` | Chat completion model |
| `GUNMA_OPENAI_BASE_URL` | `https://api.openai.com/v1` | OpenAI-compatible base URL |
| `GUNMA_OLLAMA_URL` | `http://localhost:11435` | Ollama embeddings server |
| `GUNMA_QDRANT_URL` | `http://localhost:6333` | Qdrant vector database |
| `GUNMA_ROUTE_PREFIX` | `api/chat` | Public chat routes prefix |
| `GUNMA_ADMIN_PREFIX` | `api/admin/chat` | Admin routes prefix |
| `GUNMA_MIDDLEWARE` | `api` | Public route middleware (pipe-separated) |
| `GUNMA_ADMIN_MIDDLEWARE` | `web` | Admin route middleware (pipe-separated) |
| `GUNMA_AUTH_GUARDS` | `customer\|sanctum\|web` | Guard resolution order (pipe-separated) |
| `GUNMA_BROADCAST_ADMIN_CHANNEL` | `gunma-admin.chats` | Admin WebSocket channel name |
| `GUNMA_BROADCAST_CHAT_PREFIX` | `gunma-chat` | Per-session WebSocket channel prefix |
| `GUNMA_RATE_LIMIT` | `30` | Messages per minute per visitor |
| `GUNMA_SESSION_TTL` | `86400` | Chat session Redis TTL (seconds) |
| `GUNMA_WEBSITE_URL` | — | Used for product link generation |
| `GUNMA_CORS_ORIGINS` | `*` | Allowed CORS origins (comma-separated) |
| `GUNMA_MODEL_ORDER` | `App\Models\Order` | Host app Order model class |
| `GUNMA_MODEL_PRODUCT` | `App\Models\Product` | Host app Product model class |
| `GUNMA_MODEL_CART` | `App\Models\Cart` | Host app Cart model class |

### Frontend (`.env.local` / build-time)

The frontend packages do **not** read `process.env` directly anymore. All configuration is passed via props to ensure framework-agnostic compatibility. Use your framework's own env loading:

```tsx
// Next.js example
<ChatWidget
  apiUrl={process.env.NEXT_PUBLIC_API_URL!}
  pusher={{
    key:    process.env.NEXT_PUBLIC_PUSHER_KEY!,
    wsHost: process.env.NEXT_PUBLIC_PUSHER_HOST,
    wsPort: Number(process.env.NEXT_PUBLIC_PUSHER_PORT ?? 6001),
  }}
/>
```

> **Why no `process.env` in packages?**
> Reading `process.env` inside a published npm package locks consumers to specific env key names and requires their bundler to inject them. Passing configuration via props makes the package framework-agnostic (Next.js, Vite, Remix, vanilla React).
