<?php

declare(strict_types=1);

use Emeq\ExactApi\Exceptions\RateLimitException;
use Emeq\ExactApi\Http\RateLimit\ExactRateGate;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

function makeRateGate(
    int $windowSeconds = 60,
    int $safetyMargin = 2,
    int $maxWaitSeconds = 10,
    int $fallbackLimit = 60,
    int $maxTotalWaitSeconds = 8,
): ExactRateGate {
    return new ExactRateGate(
        cacheFactory: app(CacheFactory::class),
        windowSeconds: $windowSeconds,
        safetyMargin: $safetyMargin,
        maxWaitSeconds: $maxWaitSeconds,
        fallbackLimit: $fallbackLimit,
        maxTotalWaitSeconds: $maxTotalWaitSeconds,
    );
}

function freezeAtWindowStart(): void
{
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000 - (1_800_000_000 % 60)));
}

it('does not wait while under budget', function (): void {
    $gate = makeRateGate(fallbackLimit: 60, safetyMargin: 2);

    $start = microtime(true);

    for ($i = 0; $i < 5; $i++) {
        $gate->throttle('4471372');
    }

    expect(microtime(true) - $start)->toBeLessThan(0.5);
});

it('waits, bounded by maxWaitSeconds, once the budget is exhausted', function (): void {
    $gate = makeRateGate(fallbackLimit: 1, safetyMargin: 0, maxWaitSeconds: 1);

    $gate->throttle('4471372'); // verbruikt het enige toegestane slot, wacht niet

    $start = microtime(true);
    $gate->throttle('4471372'); // budget op, moet wachten (bounded op 1s)
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeGreaterThanOrEqual(0.9)
        ->and($elapsed)->toBeLessThan(2.0);
});

it('isolates the budget per division', function (): void {
    $gate = makeRateGate(fallbackLimit: 1, safetyMargin: 0, maxWaitSeconds: 1);

    $gate->throttle('division-a'); // verbruikt division-a's enige slot

    $start = microtime(true);
    $gate->throttle('division-b'); // andere division, eigen budget — geen wacht
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(0.5);
});

it('records the live X-RateLimit-Minutely-Limit and uses it instead of the fallback', function (): void {
    $gate = makeRateGate(fallbackLimit: 60, safetyMargin: 0, maxWaitSeconds: 1);

    $gate->recordLimit('4471372', ['X-RateLimit-Minutely-Limit' => '1']);

    $gate->throttle('4471372'); // verbruikt het enige toegestane slot (limit=1, niet 60)

    $start = microtime(true);
    $gate->throttle('4471372');
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeGreaterThanOrEqual(0.9);
});

it('ignores a missing or non-numeric rate-limit header without error', function (): void {
    $gate = makeRateGate();

    $gate->recordLimit('4471372', []);
    $gate->recordLimit('4471372', ['X-RateLimit-Minutely-Limit' => 'not-a-number']);

    expect(true)->toBeTrue(); // geen exception = geslaagd
});

it('sleeps the bounded wait while the total wait budget still covers it', function (): void {
    Sleep::fake();
    freezeAtWindowStart();
    $gate = makeRateGate(fallbackLimit: 1, safetyMargin: 0, maxWaitSeconds: 3, maxTotalWaitSeconds: 8);

    $gate->throttle('4471372');
    $gate->throttle('4471372');

    Sleep::assertSequence([Sleep::for(3)->seconds()]);
});

it('throws a RateLimitException instead of sleeping once the total wait budget is spent', function (): void {
    Sleep::fake();
    freezeAtWindowStart();
    $gate = makeRateGate(fallbackLimit: 1, safetyMargin: 0, maxWaitSeconds: 3, maxTotalWaitSeconds: 8);

    $gate->throttle('4471372');
    $gate->throttle('4471372');
    $gate->throttle('4471372');

    expect(fn () => $gate->throttle('4471372'))->toThrow(
        fn (RateLimitException $e) => expect($e->retryAfterSeconds)->toBe(60),
    );

    Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(3)->seconds()]);
});

it('gives a new gate instance a fresh total wait budget', function (): void {
    Sleep::fake();
    freezeAtWindowStart();
    $spent = makeRateGate(fallbackLimit: 1, safetyMargin: 0, maxWaitSeconds: 3, maxTotalWaitSeconds: 3);
    $spent->throttle('4471372');
    $spent->throttle('4471372');

    makeRateGate(fallbackLimit: 1, safetyMargin: 0, maxWaitSeconds: 3, maxTotalWaitSeconds: 3)->throttle('4471372');

    Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(3)->seconds()]);
});

it('throws without sleeping under the defaults, because max_total_wait caps the per-call wait', function (): void {
    Sleep::fake();
    freezeAtWindowStart();
    $gate = makeRateGate(fallbackLimit: 1, safetyMargin: 0);

    $gate->throttle('4471372');

    expect(fn () => $gate->throttle('4471372'))->toThrow(
        fn (RateLimitException $e) => expect($e->retryAfterSeconds)->toBe(60),
    );

    Sleep::assertNeverSlept();
});
