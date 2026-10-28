<?php

declare(strict_types=1);

namespace Emeq\ExactApi\Http\RateLimit;

use Emeq\ExactApi\Exceptions\RateLimitException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

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
 *
 * De totale wachttijd per instance is begrensd op `$maxTotalWaitSeconds`; past
 * de volgende wacht daar niet meer in, dan volgt een `RateLimitException`.
 */
final class ExactRateGate
{
    private int $waitedSeconds = 0;

    public function __construct(
        private readonly CacheFactory $cacheFactory,
        private readonly ?string $store = null,
        private readonly string $prefix = 'exact_rate_',
        private readonly int $windowSeconds = 60,
        private readonly int $safetyMargin = 2,
        private readonly int $maxWaitSeconds = 10,
        private readonly int $fallbackLimit = 60,
        private readonly int $maxTotalWaitSeconds = 8,
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
            $secondsIntoWindow = Carbon::now()->getTimestamp() % $this->windowSeconds;
            $secondsUntilReset = $this->windowSeconds - $secondsIntoWindow;
            $wait              = min($secondsUntilReset, $this->maxWaitSeconds);

            if ($this->waitedSeconds + $wait > $this->maxTotalWaitSeconds) {
                throw new RateLimitException(
                    message: 'Exact rate-gate: wachtbudget van ' . $this->maxTotalWaitSeconds . 's op, retry after ' . $secondsUntilReset . 's.',
                    retryAfterSeconds: $secondsUntilReset,
                );
            }

            $this->waitedSeconds += $wait;

            Sleep::for($wait)->seconds();
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
        return $this->prefix . $division . ':count:' . intdiv(Carbon::now()->getTimestamp(), $this->windowSeconds);
    }

    private function limitKey(string $division): string
    {
        return $this->prefix . $division . ':limit';
    }
}
