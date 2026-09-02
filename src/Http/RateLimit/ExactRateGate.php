<?php

declare(strict_types=1);

namespace Emeq\ExactApi\Http\RateLimit;

use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Proactieve, Redis-backed rate-gate per Exact-division.
 *
 * Exact documenteert geen concreet quota-getal (geen minutely/daily-limiet in
 * de partner-docs) — `$fallbackLimit` is dus een conservatieve bootstrap-
 * default, geen Exact-beleid. Zodra een respons `X-RateLimit-Minutely-Limit`
 * meestuurt (via `recordLimit()`), overschrijft die de fallback.
 *
 * `throttle()` reserveert een slot door eerst atomisch te incrementen en pas
 * dán te toetsen — voorkomt de race waarbij meerdere gelijktijdige callers
 * allemaal "onder het budget" lezen vóór iemand incrementeert.
 */
final class ExactRateGate
{
    public function __construct(
        private readonly CacheFactory $cacheFactory,
        private readonly ?string $store = null,
        private readonly string $prefix = 'exact_rate_',
        private readonly int $windowSeconds = 60,
        private readonly int $safetyMargin = 2,
        private readonly int $maxWaitSeconds = 10,
        private readonly int $fallbackLimit = 60,
    ) {
    }

    public function throttle(string $division): void
    {
        $repository = $this->cacheFactory->store($this->store);
        $countKey   = $this->countKey($division);

        $repository->add($countKey, 0, $this->windowSeconds);
        $count = $repository->increment($countKey);

        $limit  = (int) ($repository->get($this->limitKey($division)) ?? $this->fallbackLimit);
        $budget = max(1, $limit - $this->safetyMargin);

        if ($count > $budget) {
            $secondsIntoWindow = time() % $this->windowSeconds;
            $secondsUntilReset = $this->windowSeconds - $secondsIntoWindow;

            usleep(min($secondsUntilReset, $this->maxWaitSeconds) * 1_000_000);
        }
    }

    /**
     * @param  array<string, string>  $rateLimitHeaders
     */
    public function recordLimit(string $division, array $rateLimitHeaders): void
    {
        $limit = $rateLimitHeaders['X-RateLimit-Minutely-Limit'] ?? null;

        if ( ! is_string($limit) || ! is_numeric($limit)) {
            return;
        }

        $this->cacheFactory->store($this->store)->put($this->limitKey($division), (int) $limit, 300);
    }

    private function countKey(string $division): string
    {
        return $this->prefix . $division . ':count:' . intdiv(time(), $this->windowSeconds);
    }

    private function limitKey(string $division): string
    {
        return $this->prefix . $division . ':limit';
    }
}
