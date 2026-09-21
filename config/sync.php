<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | Which database connection holds the sync tables. Null uses the default.
    | A space accepts one concurrent writer by design, so a busy deployment
    | usually wants this pointed at a connection sized for that.
    |
    */

    'connection' => env('SYNC_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | View sync
    |--------------------------------------------------------------------------
    |
    | The schema version and epoch bind every client cursor. Change either and
    | clients are told to reset rather than silently reading a feed that no
    | longer means what their local state assumes.
    |
    | The bootstrap strategy is 'keyset' by default: it stores nothing, so any
    | worker can serve any page, which is what a queued or load-balanced app
    | needs. Use 'frozen' only for a single long-lived process that wants
    | byte-identical page retries.
    |
    */

    'schema_version' => env('SYNC_SCHEMA_VERSION', 'v1'),

    'epoch' => env('SYNC_EPOCH', 'epoch-1'),

    'bootstrap' => [
        'strategy' => env('SYNC_BOOTSTRAP_STRATEGY', 'keyset'),
        'page_size' => (int) env('SYNC_BOOTSTRAP_PAGE_SIZE', 100),

        /*
         * Signs stateless bootstrap tokens. Defaults to the application key, so
         * rotating APP_KEY invalidates open bootstraps: clients restart them,
         * which is the safe outcome.
         */
        'secret' => env('SYNC_BOOTSTRAP_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Nothing is pruned automatically. When you do prune, keep enough history
    | for your slowest client: a cursor below the horizon is told to
    | re-bootstrap rather than silently skipping the commits it missed.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | Off by default. The endpoints carry no authentication of their own: put
    | your own guard in `middleware`, and declare every syncable type in
    | `types`. A type that is not listed is refused, because a type nobody
    | declared is a type nobody decided the authorization rules for.
    |
    */

    'api' => [
        'enabled' => env('SYNC_API_ENABLED', false),
        'prefix' => env('SYNC_API_PREFIX', 'sync'),
        'middleware' => ['api'],

        /** entity type => class-string<Cbox\Sync\Laravel\Api\Contracts\SyncableType> */
        'types' => [],

        'max_body_bytes' => 256 * 1024,
        'max_operations' => 64,
        'max_page_size' => 500,
        'max_commits' => 500,
    ],

    'retention' => [
        // The default for `php artisan sync:prune <space>`. Nothing prunes on its
        // own: how much history a tenant still owes its slowest device is a
        // question only you can answer, so schedule the command.
        'keep_commits' => (int) env('SYNC_KEEP_COMMITS', 10_000),
    ],

];
