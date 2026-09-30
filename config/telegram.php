<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram Bots
    |--------------------------------------------------------------------------
    |
    | The bots managed by this application. Each bot requires a token
    | issued by @BotFather.
    |
    */

    'bots' => [
        'default' => [
            'token' => env('TELEGRAM_BOT_TOKEN'),
            'webhook_url' => env('TELEGRAM_WEBHOOK_URL') ?: null,
            'allowed_updates' => null,
            'commands' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Bot
    |--------------------------------------------------------------------------
    |
    | The name of the bot used by default when resolving the SDK.
    |
    */

    'default' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Async Requests
    |--------------------------------------------------------------------------
    |
    | When set to true, all requests are made non-blocking.
    |
    */

    'async_requests' => false,

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Handler
    |--------------------------------------------------------------------------
    |
    | A custom HTTP client handler implementing \Telegram\Bot\HttpClients\
    | HttpClientInterface. Configured at runtime by
    | App\Providers\TelegramServiceProvider when a proxy is present.
    |
    */

    'http_client_handler' => null,

    /*
    |--------------------------------------------------------------------------
    | Base Bot URL
    |--------------------------------------------------------------------------
    |
    | A custom base URL of the Telegram Bot API, for example a local
    | endpoint or a mirror of the official API.
    |
    */

    'base_bot_url' => env('TELEGRAM_BASE_URL') ?: null,

    /*
    |--------------------------------------------------------------------------
    | Proxy
    |--------------------------------------------------------------------------
    |
    | An optional outbound HTTP proxy used to reach the Telegram API
    | (useful on networks where api.telegram.org is unreachable). Leave
    | empty to connect directly.
    |
    */

    'proxy' => env('TELEGRAM_PROXY'),

    /*
    |--------------------------------------------------------------------------
    | Command System
    |--------------------------------------------------------------------------
    |
    | The application routes updates through its own CommandRouter, so the
    | SDK command system stays disabled.
    |
    */

    'resolve_command_dependencies' => false,
    'commands' => [],
    'command_groups' => [],
    'shared_commands' => [],

];
