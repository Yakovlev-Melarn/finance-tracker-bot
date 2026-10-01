<?php

namespace App\Services\Bot;

use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;

interface BotMessenger
{
    /**
     * Send a plain-text message to the given chat.
     *
     * @throws TelegramSDKException
     */
    public function sendMessage(int|string $chatId, string $text): Message;
}
