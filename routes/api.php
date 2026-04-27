<?php

use Anwar\GunmaAgent\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gunma AI Agent — Chat API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the GunmaAgentServiceProvider.
| They are prefixed with the value of config('gunma-agent.route_prefix').
|
*/

$prefix     = config('gunma-agent.route_prefix', 'api/chat');
$middleware = config('gunma-agent.middleware', ['throttle:60,1']);
$corsOrigins = config('gunma-agent.cors_origins', ['*']);

// Public Chat Interface
Route::prefix($prefix)
    ->middleware(array_merge($middleware, [\Illuminate\Http\Middleware\HandleCors::class]))
    ->group(function () {
        Route::post('/upload', [ChatController::class, 'upload']);
        Route::post('/sessions', [ChatController::class, 'createSession']);
        Route::get('/sessions/{id}', [ChatController::class, 'showSession']);
        Route::post('/sessions/{id}/end', [ChatController::class, 'endSession']);
        Route::post('/sessions/{id}/messages', [ChatController::class, 'sendMessage']);
        Route::post('/sessions/{id}/messages/sync', [ChatController::class, 'sendMessageSync']);
        Route::post('/sessions/{id}/typing', [ChatController::class, 'typing']);
        Route::get('/sessions/{id}/messages', [ChatController::class, 'getMessages']);
        Route::post('/cart/bulk', [ChatController::class, 'bulkAddToCart']);
    });

// Admin Dashboard & Monitoring
$adminPrefix     = config('gunma-agent.admin_route_prefix', 'api/admin/chat');
$adminMiddleware = config('gunma-agent.admin_middleware', ['web']);

Route::prefix($adminPrefix)
    ->middleware($adminMiddleware)
    ->group(function () {
        Route::get('/stats', [ChatController::class, 'getStats']);
        Route::get('/sessions', [ChatController::class, 'listSessions']);
        Route::get('/sessions/{id}', [ChatController::class, 'getSession']);
        Route::post('/sessions/{id}/toggle-ai', [ChatController::class, 'toggleAi']);
        Route::post('/sessions/{id}/messages', [ChatController::class, 'sendManualMessage']);
        Route::post('/sessions/{id}/typing', [ChatController::class, 'typing']);
        
        // Support Tickets
        Route::get('/tickets', [ChatController::class, 'listTickets']);
        Route::post('/tickets/{id}/status', [ChatController::class, 'updateTicketStatus']);
    });

// Email Webhook (Incoming Support Emails)
Route::prefix($prefix)->post('webhook/email', [\Anwar\GunmaAgent\Http\Controllers\EmailWebhookController::class, 'handle'])
    ->middleware(\Illuminate\Http\Middleware\HandleCors::class);
