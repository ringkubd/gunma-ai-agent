<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent;

use Illuminate\Support\ServiceProvider;
use Anwar\GunmaAgent\Services\AgentOrchestrator;
use Anwar\GunmaAgent\Services\EmbeddingService;
use Anwar\GunmaAgent\Services\QdrantService;
use Anwar\GunmaAgent\Services\ToolExecutor;
use Anwar\GunmaAgent\Services\GreetingInterceptor;

class GunmaAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/gunma-agent.php', 'gunma-agent');

        // Singleton registrations
        $this->app->singleton(EmbeddingService::class, function ($app) {
            return new EmbeddingService(
                ollamaUrl:        config('gunma-agent.ollama_url'),
                ollamaModel:      config('gunma-agent.ollama_embed_model'),
                openaiKey:        config('gunma-agent.openai_api_key'),
                openaiBaseUrl:    config('gunma-agent.openai_base_url'),
                openaiEmbedModel: config('gunma-agent.openai_embed_model'),
            );
        });

        $this->app->singleton(QdrantService::class, function ($app) {
            return new QdrantService(
                qdrantUrl:        config('gunma-agent.qdrant_url'),
                embeddingService: $app->make(EmbeddingService::class),
            );
        });

        $this->app->singleton(GreetingInterceptor::class);

        $this->app->singleton(ToolExecutor::class, function ($app) {
            return new ToolExecutor(
                qdrantService: $app->make(QdrantService::class),
            );
        });

        $this->app->singleton(AgentOrchestrator::class, function ($app) {
            return new AgentOrchestrator(
                toolExecutor:        $app->make(ToolExecutor::class),
                greetingInterceptor: $app->make(GreetingInterceptor::class),
                qdrantService:       $app->make(QdrantService::class),
                openaiKey:           config('gunma-agent.openai_api_key'),
                openaiBaseUrl:       config('gunma-agent.openai_base_url'),
                openaiModel:         config('gunma-agent.openai_model'),
                websiteUrl:          config('gunma-agent.website_url'),
                maxHistory:          config('gunma-agent.max_history'),
            );
        });
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/gunma-agent.php' => config_path('gunma-agent.php'),
        ], 'gunma-agent-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'gunma-agent-migrations');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Register Commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Anwar\GunmaAgent\Commands\IndexCommand::class,
                \Anwar\GunmaAgent\Commands\SetupCollectionsCommand::class,
                \Anwar\GunmaAgent\Commands\InstallCommand::class,
            ]);
        }

        // Register Scout Engine
        if (config('gunma-agent.scout_enabled') && class_exists(\Laravel\Scout\EngineManager::class)) {
            resolve(\Laravel\Scout\EngineManager::class)->extend('qdrant', function () {
                return new \Anwar\GunmaAgent\Scout\QdrantEngine(
                    $this->app->make(QdrantService::class),
                    $this->app->make(EmbeddingService::class)
                );
            });
        }

        // Register Broadcasting Channels
        $this->registerBroadcastingChannels();
    }

    private function registerBroadcastingChannels(): void
    {
        if (! class_exists(\Illuminate\Support\Facades\Broadcast::class)) {
            return;
        }

        \Illuminate\Support\Facades\Broadcast::channel('gunma-chat.{sessionId}', function ($user, $sessionId) {
            return true; // Public or custom auth logic here
        });

        \Illuminate\Support\Facades\Broadcast::channel('gunma-admin.chats', function ($user) {
            // Ideally check if user is admin: return $user->is_admin;
            return true; 
        });
    }
}
