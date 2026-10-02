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
     * Send a message to the given chat.
     *
     * Retries on transport-level failures (e.g. a flaky proxy connection)
     * before giving up.
     *
     * @param  string|null  $parseMode  Telegram parse mode ("Markdown", "MarkdownV2"); null for plain text.
     *
     * @throws TelegramSDKException
     */
    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null): Message
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        for ($attempt = 1; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->telegram->sendMessage($params);
            } catch (TelegramSDKException) {
                sleep(1);
            }
        }

        return $this->telegram->sendMessage($params);
    }
}
