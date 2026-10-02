<?php

namespace App\Services\Bot;

use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;

interface BotMessenger
{
    /**
     * Send a message to the given chat.
     *
     * @param  string|null  $parseMode  Telegram parse mode ("Markdown", "MarkdownV2"); null for plain text.
     *
     * @throws TelegramSDKException
     */
    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null): Message;
}
