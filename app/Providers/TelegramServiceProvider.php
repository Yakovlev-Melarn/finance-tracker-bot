<?php

namespace App\Providers;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Telegram\Bot\HttpClients\GuzzleHttpClient;

class TelegramServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $proxy = config('telegram.proxy');

        if (blank($proxy)) {
            return;
        }

        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'proxy' => [
                'http' => $proxy,
                'https' => $proxy,
            ],
        ]);

        config(['telegram.http_client_handler' => new GuzzleHttpClient($client)]);
    }
}
