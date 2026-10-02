<?php

namespace App\Services\Bot;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\FileUpload\InputFile;
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
     * @param  array<int, array<int, array{text: string, callback: string}>>|null  $keyboard  Inline keyboard rows.
     *
     * @throws TelegramSDKException
     */
    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null, ?array $keyboard = null): Message
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        $this->applyFormatting($params, $parseMode, $keyboard);

        return $this->withRetries(fn () => $this->telegram->sendMessage($params));
    }

    /**
     * Send a photo with a caption to the given chat.
     *
     * @param  string  $filePath  Absolute path to the image file on the server.
     * @param  string|null  $parseMode  Telegram parse mode for the caption; null for plain text.
     * @param  array<int, array<int, array{text: string, callback: string}>>|null  $keyboard  Inline keyboard rows.
     *
     * @throws TelegramSDKException
     */
    public function sendPhoto(int|string $chatId, string $filePath, string $caption, ?string $parseMode = null, ?array $keyboard = null): Message
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => InputFile::create($filePath, basename($filePath)),
            'caption' => $caption,
        ];

        $this->applyFormatting($params, $parseMode, $keyboard);

        return $this->withRetries(fn () => $this->telegram->sendPhoto($params));
    }

    /**
     * Edit a previously sent message in place (e.g. refresh the menu).
     *
     * @param  string|null  $parseMode  Telegram parse mode for the new text; null for plain text.
     * @param  array<int, array<int, array{text: string, callback: string}>>|null  $keyboard  Inline keyboard rows.
     *
     * @throws TelegramSDKException
     */
    public function editMessage(int $chatId, int $messageId, string $text, ?string $parseMode = null, ?array $keyboard = null): Message
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];

        $this->applyFormatting($params, $parseMode, $keyboard);

        return $this->withRetries(fn () => $this->telegram->editMessageText($params));
    }

    public function answerCallback(int $callbackId): void
    {
        try {
            $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackId]);
        } catch (TelegramSDKException $exception) {
            Log::channel('single')->warning('Failed to acknowledge callback query', [
                'callback_id' => $callbackId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Add parse mode and inline keyboard to a request parameter bag.
     *
     * @param  array<string, mixed>  $params
     * @param  array<int, array<int, array{text: string, callback: string}>>|null  $keyboard
     */
    private function applyFormatting(array &$params, ?string $parseMode, ?array $keyboard): void
    {
        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($this->keyboard($keyboard), JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Convert the compact keyboard definition into Telegram's reply markup.
     *
     * @param  array<int, array<int, array{text: string, callback: string}>>  $keyboard
     * @return array{inline_keyboard: list<list<array{string, string}>>}
     */
    private function keyboard(array $keyboard): array
    {
        return [
            'inline_keyboard' => array_map(
                static fn (array $row) => array_map(
                    static fn (array $button) => [
                        'text' => $button['text'],
                        'callback_data' => $button['callback'],
                    ],
                    $row,
                ),
                $keyboard,
            ),
        ];
    }

    /**
     * Run a Telegram API call, retrying on transport-level failures.
     *
     * @throws TelegramSDKException
     */
    private function withRetries(callable $attempt): Message
    {
        for ($i = 1; $i < self::MAX_ATTEMPTS; $i++) {
            try {
                return $attempt();
            } catch (TelegramSDKException) {
                sleep(1);
            }
        }

        return $attempt();
    }
}
