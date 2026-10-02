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
     * @param  array<int, array<int, array{text: string, callback: string}>>|null  $keyboard  Inline keyboard rows; each button is a label plus callback data.
     *
     * @throws TelegramSDKException
     */
    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null, ?array $keyboard = null): Message;

    /**
     * Send a photo with a caption to the given chat.
     *
     * @param  string  $filePath  Absolute path to the image file on the server.
     * @param  string|null  $parseMode  Telegram parse mode for the caption; null for plain text.
     * @param  array<int, array<int, array{text: string, callback: string}>>|null  $keyboard  Inline keyboard rows.
     *
     * @throws TelegramSDKException
     */
    public function sendPhoto(int|string $chatId, string $filePath, string $caption, ?string $parseMode = null, ?array $keyboard = null): Message;

    /**
     * Edit a previously sent message in place (e.g. refresh the menu).
     *
     * @param  string|null  $parseMode  Telegram parse mode for the new text; null for plain text.
     * @param  array<int, array<int, array{text: string, callback: string}>>|null  $keyboard  Inline keyboard rows.
     *
     * @throws TelegramSDKException
     */
    public function editMessage(int $chatId, int $messageId, string $text, ?string $parseMode = null, ?array $keyboard = null): Message;

    /**
     * Acknowledge a callback query so the spinner on the pressed button goes away.
     */
    public function answerCallback(int $callbackId): void;
}
