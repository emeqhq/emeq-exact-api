<?php

declare(strict_types=1);

/**
 * Configuratie voor emeq/exact-api.
 */
return [

    /*
     * Base URL van de Exact Online REST API (geen trailing slash). Region-
     * specifiek: NL = start.exactonline.nl, BE = .be, DE = .de, UK = .co.uk.
     * Resource-calls hangen `/api/v1/{division}/...` hierachter.
     */
    'api_base_url' => env('EXACT_API_BASE_URL', 'https://start.exactonline.nl'),

    /*
     * Base URL van de OAuth2-endpoints. `/api/oauth2/auth` + `/api/oauth2/token`
     * worden hierachter gehangen. Zelfde host als de API op NL.
     */
    'auth_base_url' => env('EXACT_AUTH_BASE_URL', 'https://start.exactonline.nl'),

    'cache' => [
        /*
         * Cache-store voor de refresh-lock (Cache::lock). MOET een atomic-lock-
         * driver zijn (redis/database/memcached/dynamodb). Null = default store.
         */
        'lock_store' => env('EXACT_LOCK_STORE'),

        'lock_prefix' => 'exact_refresh_',

        /*
         * Seconden wachten om de per-connection refresh-lock te verkrijgen voor
         * we opgeven (een parallel request kan al aan het refreshen zijn).
         */
        'lock_wait' => 8,

        /*
         * Seconden dat de lock vastgehouden wordt (auto-release vangnet als een
         * worker sterft tijdens de refresh).
         */
        'lock_ttl' => 10,

        /*
         * Safety-margin in seconden. Exact WEIGERT een refresh zolang de access-
         * token nog geldig is ("Rate limit exceeded: access_token not expired"),
         * dus we refreshen pas ná expiry → margin = 0. Anders dan Snelstart/Mollie
         * (die proactief vóór expiry mogen refreshen).
         */
        'ttl_safety_margin' => 0,
    ],

    'token' => [
        /*
         * Key-prefix voor de default CacheTokenStore (standalone/tests). De Hub
         * bindt z'n eigen TokenStore tegen het encrypted Connection-model.
         */
        'store_prefix' => 'exact_token_',

        /*
         * TTL voor de CacheTokenStore-entry. NIET de 600s access-TTL — de bundle
         * bevat ook het langlevende refresh-token (Exact ~30 dagen), dus de cache
         * moet de bundle veel langer bewaren dan de access-token leeft.
         */
        'store_ttl' => env('EXACT_TOKEN_STORE_TTL', 2592000),
    ],

    'http' => [
        'timeout' => 30,

        'retry' => [
            'times' => 3,
            'sleep' => 1000,
            'on'    => [429, 500, 502, 503, 504],
        ],
    ],

    /*
     * Proactieve rate-gate: vertraagt vóór Exact's eigen minuut-quota geraakt
     * wordt, i.p.v. alleen te reageren op een 429. `fallback_limit` is GEEN
     * Exact-gedocumenteerde waarde (Exact publiceert geen concreet quota-getal)
     * — een conservatieve bootstrap-default totdat de eerste respons
     * `X-RateLimit-Minutely-Limit` meestuurt, waarna die de default overschrijft.
     */
    'rate_limit' => [
        'enabled' => env('EXACT_RATE_LIMIT_ENABLED', true),

        /*
         * Cache-store voor de rate-gate-tellers. Null = default store. Los van
         * `cache.lock_store` (die is voor de refresh-lock) — mag dezelfde store
         * zijn, maar hoeft niet.
         */
        'store' => env('EXACT_RATE_LIMIT_STORE'),

        'prefix' => 'exact_rate_',

        /*
         * Breedte van het budget-window in seconden — matcht Exact's "minutely"
         * quota-terminologie.
         */
        'window' => 60,

        /*
         * Aantal calls dat als marge wordt vrijgehouden vóór de limiet — stopt
         * vóór de muur, niet ertegenaan.
         */
        'safety_margin' => 2,

        /*
         * Maximale wachttijd (seconden) per throttle()-call. Voorkomt dat een
         * sync HTTP-request/Octane-worker de volle window-reset (tot 60s) blokkeert.
         */
        'max_wait' => 10,

        /*
         * Maximale totale wachttijd (seconden) over alle throttle()-calls binnen
         * één request of job. Is die op, dan gooit de gate een
         * RateLimitException met retryAfterSeconds i.p.v. verder te wachten.
         * De effectieve wacht per call is dus min(max_wait, max_total_wait);
         * met de defaults (10 en 8) bindt max_wait niet. Artisan-commands
         * krijgen geen scope-reset: het budget geldt voor de hele CLI-run.
         */
        'max_total_wait' => env('EXACT_RATE_LIMIT_MAX_TOTAL_WAIT', 8),

        /*
         * Bootstrap-default vóór de eerste echte X-RateLimit-Minutely-Limit-
         * header binnenkomt. Geen Exact-beleid, enkel een startwaarde.
         */
        'fallback_limit' => 60,
    ],

    'webhook' => [
        /*
         * App-brede HMAC-secret waarmee Exact elke inbound notificatie tekent:
         * HashCode = base64(HMAC-SHA256) over de Content-node-JSON. Per-APP,
         * niet per-Connection (Exact-eigenaardigheid). De Hub hydrateert deze
         * uit ExactSettings (encrypted at rest) naar deze key; standalone valt
         * 'ie terug op env.
         */
        'secret' => env('EXACT_WEBHOOK_SECRET'),

        /*
         * HMAC-algoritme voor de HashCode. Exact gebruikt SHA256.
         */
        'signature_algo' => 'sha256',
    ],

];
