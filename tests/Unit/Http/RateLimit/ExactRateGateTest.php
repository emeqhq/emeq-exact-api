<?php

declare(strict_types=1);

use Emeq\ExactApi\Http\RateLimit\ExactRateGate;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

function makeRateGate(
    int $windowSeconds = 60,
    int $safetyMargin = 2,
    int $maxWaitSeconds = 10,
    int $fallbackLimit = 60,
): ExactRateGate {
    return new ExactRateGate(
        cacheFactory: app(CacheFactory::class),
        windowSeconds: $windowSeconds,
        safetyMargin: $safetyMargin,
        maxWaitSeconds: $maxWaitSeconds,
        fallbackLimit: $fallbackLimit,
    );
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
