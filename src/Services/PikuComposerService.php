<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PikuComposerService — writes a short, natural, language-matched one-liner
 * for the activity-driven doodle ("smart Piku").
 *
 * This is the "best-quality" path: it reuses the configured LLM (the same
 * ollama.com cloud model the chat agent uses) plus the customer's live context
 * (what product they are focused on, their search keyword, engagement state)
 * to say something genuinely contextual instead of a canned line.
 *
 * Guardrails:
 *  - hard ≤ 22 words, no lists, no PII, no prices unless asked
 *  - always in the customer's language
 *  - 6h response cache per (intent, product, keyword, lang) — repeats are free
 *  - never throws; returns null so the caller falls back to pre-composed cues
 */
class PikuComposerService
{
    public function __construct(private readonly AgentSettingsService $settings) {}

    /**
     * @param  array{type?:string,intent?:string,product_id?:int|null,product_title?:string|null,keyword?:string|null,state?:string|null,dwell_seconds?:int|null,url?:string|null}  $signal
     */
    public function compose(array $signal, string $lang = 'bn'): ?string
    {
        $type = (string) ($signal['type'] ?? 'tip');
        $productId = isset($signal['product_id']) ? (int) $signal['product_id'] : 0;
        $keyword = trim((string) ($signal['keyword'] ?? ''));
        $productTitle = trim((string) ($signal['product_title'] ?? ''));

        $cacheKey = 'piku-compose:' . md5(implode('|', [$type, $signal['intent'] ?? '', $productId, $keyword, $productTitle, $lang]));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Single-flight: if an identical compose is already in progress, wait
        // briefly for its result instead of firing another LLM request. This
        // keeps concurrency flat when many shoppers hit the same context.
        $lockKey = $cacheKey . ':lock';
        try {
            $lock = Cache::lock($lockKey, 15);
            if (! $lock->get()) {
                for ($i = 0; $i < 20; $i++) {
                    usleep(150_000); // up to ~3s
                    $again = Cache::get($cacheKey);
                    if (is_string($again) && $again !== '') return $again;
                }
                return null; // give up — caller uses pre-composed cues
            }
        } catch (\Throwable) {
            $lock = null; // cache store without locks — proceed without single-flight
        }

        try {
            return $this->generate($cacheKey, $signal, $lang, $type, $productId, $keyword, $productTitle);
        } finally {
            try { $lock?->release(); } catch (\Throwable) { /* ignore */ }
        }
    }

    private function generate(string $cacheKey, array $signal, string $lang, string $type, int $productId, string $keyword, string $productTitle): ?string
    {

        $cfg = $this->llmConfig();
        if ($cfg['base_url'] === '' || $cfg['model'] === '') {
            return null;
        }

        $langName = ['bn' => 'Bengali (Banglish-friendly informal Bangla)', 'hi' => 'Hindi', 'en' => 'English'][$lang] ?? 'English';

        $ctx = [];
        if ($productTitle !== '') $ctx[] = "The customer is currently looking at: {$productTitle}.";
        if ($productId > 0 && $productTitle === '') $ctx[] = "The customer is looking at product #{$productId}.";
        if ($keyword !== '') $ctx[] = "They recently searched for: \"{$keyword}\".";
        if (! empty($signal['state'])) $ctx[] = "Their engagement state: {$signal['state']}.";
        if (! empty($signal['dwell_seconds'])) $ctx[] = "They have been on this page ~{$signal['dwell_seconds']}s.";

        $system = "You are Piku, a warm, witty grocery-store mascot for Gunma Halal Food (a Japanese Bangladeshi halal grocery). "
            . "React to what the shopper is doing right now with ONE short, natural, helpful sentence for a tiny speech bubble. "
            . "Hard rules: max 18 words; no lists; no markdown; no prices; no PII; never pushy. "
            . "Write ONLY in {$langName}. One friendly emoji is fine. "
            . "Output the sentence alone — no preamble, no explanation, no quotes, do not restate these instructions.";

        $user = "Moment:\n" . (empty($ctx) ? '- Shopper is browsing the store.' : '- ' . implode("\n- ", $ctx))
            . "\nNow write the one-line bubble reaction (in {$langName}):";

        try {
            $limiter = app(ConcurrencyLimiter::class);
            $res = $limiter->runBestEffort(function () use ($cfg, $system, $user) {
                $req = Http::timeout(8)->acceptJson();
                if ($cfg['api_key'] !== '') $req = $req->withToken($cfg['api_key']);
                return $req->post($cfg['base_url'] . '/chat/completions', [
                    'model' => $cfg['model'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'temperature' => 0.85,
                    'max_tokens' => 160,
                    // Thinking models burn the budget on hidden reasoning and
                    // leave `content` empty; disable it for these tiny lines.
                    'reasoning_effort' => 'none',
                ]);
            }, null);

            // No slot (LLM saturated) → return null immediately so the widget
            // uses a pre-composed cue instead of blocking this FPM worker.
            if ($res === null) {
                return null;
            }
        } catch (\Throwable $e) {
            Log::debug('[PikuComposer] request failed', ['error' => $e->getMessage()]);
            return null;
        }

        if (! $res->ok()) {
            Log::debug('[PikuComposer] bad response', ['status' => $res->status()]);
            return null;
        }

        $data = $res->json();
        $msg = $data['choices'][0]['message'] ?? [];
        $text = trim((string) ($msg['content'] ?? ''));
        if ($text === '' && ! empty($msg['reasoning'])) $text = trim((string) $msg['reasoning']);

        $text = $this->sanitize($text);
        if ($text === '') return null;

        Cache::put($cacheKey, $text, now()->addHours(6));
        return $text;
    }

    private function sanitize(string $text): string
    {
        // strip markdown / quotes / multi-line, clamp to a bubble-sized line
        $text = preg_replace('/[*_`#>]+/', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B\"'");

        if (mb_strlen($text) > 140) {
            $text = mb_substr($text, 0, 137) . '…';
        }
        return $text;
    }

    /** @return array{base_url:string,api_key:string,model:string} */
    private function llmConfig(): array
    {
        return [
            'base_url' => rtrim((string) $this->settings->get('llm_base_url', config('gunma-agent.llm.base_url', '')), '/'),
            'api_key'  => (string) $this->settings->get('llm_api_key', config('gunma-agent.llm.api_key', '')),
            'model'    => trim((string) $this->settings->get('llm_model', config('gunma-agent.llm.model', ''))),
        ];
    }
}
