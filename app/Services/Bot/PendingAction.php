<?php

namespace App\Services\Bot;

use Illuminate\Support\Facades\Cache;

final class PendingAction
{
    private const string KEY_PREFIX = 'bot:pending-action:';

    private const int TTL_SECONDS = 900;

    /**
     * Remember that the user's next text message must be interpreted as a category action.
     */
    public function set(int $telegramId, string $action): void
    {
        Cache::put(self::KEY_PREFIX.$telegramId, $action, self::TTL_SECONDS);
    }

    /**
     * The pending category action for the user, if any.
     */
    public function get(int $telegramId): ?string
    {
        return Cache::get(self::KEY_PREFIX.$telegramId);
    }

    /**
     * Drop the pending category action for the user.
     */
    public function clear(int $telegramId): void
    {
        Cache::forget(self::KEY_PREFIX.$telegramId);
    }
}
