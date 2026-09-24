<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Redis-backed semaphore that limits concurrent outbound LLM requests.
 *
 * Ollama Cloud permits only a handful of simultaneous requests; exceeding it
 * returns errors. Instead of failing, callers acquire a slot (waiting briefly
 * for one to free up) and release it when done.
 */
class ConcurrencyLimiter
{
    private const KEY = 'gunma_llm_concurrency';

    public function __construct(
        private readonly int $maxConcurrency,
        private readonly int $waitSeconds,
        private readonly int $slotTtl,
    ) {}

    /**
     * Run a callable while holding a concurrency slot. Falls back to running
     * without a slot if the limiter itself is unavailable (never blocks chat).
     */
    public function run(callable $fn)
    {
        if (! $this->acquire()) {
            // Could not get a slot in time — proceed anyway rather than fail.
            Log::warning('[ConcurrencyLimiter] No slot acquired in time; running unthrottled');
            return $fn();
        }

        try {
            return $fn();
        } finally {
            $this->release();
        }
    }

    /**
     * Try to acquire a slot, waiting up to waitSeconds. Returns true on success.
     */
    public function acquire(): bool
    {
        if ($this->maxConcurrency <= 0) {
            return true; // limiter disabled
        }

        $deadline = microtime(true) + $this->waitSeconds;

        do {
            try {
                $current = (int) Cache::get(self::KEY, 0);

                if ($current < $this->maxConcurrency) {
                    // Atomic-ish increment; re-read to detect races.
                    $new = Cache::increment(self::KEY, 1);
                    if ($new === false) {
                        // Store may not support increment; use put with lock-free best effort.
                        Cache::put(self::KEY, $current + 1, $this->slotTtl);
                        $new = $current + 1;
                    }
                    // Ensure the key expires so a crash cannot leak capacity.
                    if ($new <= 1) {
                        Cache::put(self::KEY, $new, $this->slotTtl);
                    }
                    if ($new <= $this->maxConcurrency) {
                        return true;
                    }
                    // Lost the race — release the extra slot we added.
                    $this->release();
                }

                usleep(250_000); // 250ms
            } catch (\Throwable $e) {
                Log::warning('[ConcurrencyLimiter] acquire failed, proceeding', ['error' => $e->getMessage()]);
                return true;
            }
        } while (microtime(true) < $deadline);

        return false;
    }

    public function release(): void
    {
        try {
            $current = (int) Cache::get(self::KEY, 0);
            if ($current > 0) {
                $new = Cache::decrement(self::KEY, 1);
                if ($new === false) {
                    Cache::put(self::KEY, max(0, $current - 1), $this->slotTtl);
                }
            } else {
                Cache::forget(self::KEY);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function current(): int
    {
        try {
            return (int) Cache::get(self::KEY, 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
