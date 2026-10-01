<?php

namespace App\Services\Bot;

use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;

readonly class BotService implements BotMessenger
{
    public function __construct(
        private Api $telegram,
    ) {}

    /**
     * Send a plain-text message to the given chat.
     *
     * @throws TelegramSDKException
     */
    public function sendMessage(int|string $chatId, string $text): Message
    {
        return $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
