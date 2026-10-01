<?php

namespace App\Services\Bot;

use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;

readonly class BotService implements BotMessenger
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private Api $telegram,
    ) {}

    /**
     * Send a plain-text message to the given chat.
     *
     * Retries on transport-level failures (e.g. a flaky proxy connection)
     * before giving up.
     *
     * @throws TelegramSDKException
     */
    public function sendMessage(int|string $chatId, string $text): Message
    {
        for ($attempt = 1; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
            } catch (TelegramSDKException) {
                sleep(1);
            }
        }

        return $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
