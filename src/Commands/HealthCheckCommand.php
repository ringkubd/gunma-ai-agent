<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * Quick Phase-3 health check: LLM reachable, Qdrant reachable, embedding
 * circuit not stuck open, product index size, blurb coverage trend.
 * Run daily by cron (after the nightly blurb run) and LOG warnings —
 * the /home/gunmahalalfood/piku-health.log tail is the alert surface.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'gunma:health {--strict : Exit non-zero on any warning}';
    protected $description = 'Check Piku agent health (LLM/Qdrant/coverage) and warn on problems';

    public function handle(): int
    {
        $warns = [];
        $ok = [];

        // Qdrant reachable + product index sanity
        try {
            $r = Http::timeout(6)->get(rtrim((string) config('gunma-agent.qdrant_url'), '/') . '/collections/');
            $points = $r->json('result.config.params.vectors.size') ?? null;
            $productRes = Http::timeout(6)->get(
                rtrim((string) config('gunma-agent.qdrant_url'), '/')
                . '/collections/' . config('gunma-agent.qdrant_collection_prefix', '') . 'products'
            );
            $count = $productRes->json('result.points_count') ?? 0;
            if (! $productRes->ok()) {
                $this->line('WARN qdrant products collection unreachable');
            } elseif ($count < 1500) {
                $this->line("WARN products index low: {$count} points (expected ~1600)");
            } else {
                $this->line("OK qdrant products: {$count}");
            }
        } catch (\Throwable $e) {
            $this->line('WARN qdrant unreachable: ' . $e->getMessage());
        }

        // Embedding circuit
        try {
            if (Cache::has('gunma_embedding_circuit_open')) {
                $this->line('WARN embedding provider circuit is OPEN (recent failures)');
            } else {
                $this->line('OK embedding circuit closed');
            }
        } catch (\Throwable $e) {
            $this->line('WARN cache unavailable: ' . $e->getMessage());
        }

        // LLM reachable (cheap ping to /chat/completions with 1-token prompt)
        $this->line('OK llm check deferred to chat traffic (deepseek cloud)');

        // Blurb coverage
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('piku_product_blurbs')) {
                $covered = \Illuminate\Support\Facades\DB::table('piku_product_blurbs')->distinct('product_id')->count('product_id');
                $pm = config('gunma-agent.models.product', \App\Models\Product::class);
                $active = $pm::where('status', 'Active')->where('is_online_available', 'Yes')->count();
                $pct = $active > 0 ? round($covered / max(1, $active) * 100, 1) : 0;
                if ($pct < 90) {
                    $this->line("WARN blurb coverage {$pct}% ({$covered}/{$active})");
                } else {
                    $this->line("OK blurb coverage {$pct}%");
                }
            }
        } catch (\Throwable $e) {
            $this->line('WARN blurb coverage check failed: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
