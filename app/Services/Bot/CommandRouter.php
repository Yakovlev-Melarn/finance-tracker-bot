<?php

namespace App\Services\Bot;

use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Parser\TransactionParseException;
use App\Services\Parser\TransactionParser;
use App\Services\Reports\ReportBuilder;
use App\TransactionType;
use Telegram\Bot\Exceptions\TelegramSDKException;

readonly class CommandRouter
{
    public function __construct(
        private BotMessenger $bot,
        private TransactionParser $parser,
        private ReportBuilder $reports,
    ) {}

    /**
     * Route an incoming Telegram update to the appropriate handler.
     *
     * @param  array{message?: array<string, mixed>}  $update
     *
     * @throws TelegramSDKException
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;

        if (! is_array($message) || blank($message['text'] ?? null)) {
            return;
        }

        $text = (string) $message['text'];
        $chatId = (int) $message['chat']['id'];
        $from = $message['from'] ?? [];

        match ($this->command($text)) {
            'start' => $this->registerUser($chatId, $from),
            'stats' => $this->sendStats($chatId, $from),
            'history' => $this->sendHistory($chatId, $from),
            default => $this->recordTransaction($chatId, $from, $text),
        };
    }

    /**
     * Send the user's weekly summary.
     *
     * @param  array<string, mixed>  $from
     *
     * @throws TelegramSDKException
     */
    private function sendStats(int $chatId, array $from): void
    {
        $user = $this->userFor($from);

        $stats = $this->reports->weekStats($user);

        $this->bot->sendMessage($chatId, $this->reports->formatWeekStats($stats, $user->currency), 'Markdown');
    }

    /**
     * Send the user's most recent transactions.
     *
     * @param  array<string, mixed>  $from
     *
     * @throws TelegramSDKException
     */
    private function sendHistory(int $chatId, array $from): void
    {
        $user = $this->userFor($from);

        $transactions = $this->reports->recentTransactions($user);

        $this->bot->sendMessage($chatId, $this->reports->formatHistory($transactions, $user->currency), 'Markdown');
    }

    /**
     * Register the user behind the /start command and greet them.
     *
     * @param  array<string, mixed>  $from
     *
     * @throws TelegramSDKException
     */
    private function registerUser(int $chatId, array $from): void
    {
        $user = $this->userFor($from);

        $this->bot->sendMessage($chatId, sprintf('Привет, %s! Отправляй записи вида «кофе 150».', $user->name));
    }

    /**
     * Parse a free-text message into a transaction, store it and confirm.
     *
     * @param  array<string, mixed>  $from
     *
     * @throws TelegramSDKException
     */
    private function recordTransaction(int $chatId, array $from, string $text): void
    {
        $user = $this->userFor($from);

        try {
            $parsed = $this->parser->parse($text, $user->categories()->get());
        } catch (TransactionParseException) {
            $this->bot->sendMessage($chatId, 'Не понял. Пример записи: «кофе 150».');

            return;
        }

        $transaction = new Transaction([
            'user_id' => $user->id,
            'amount' => $parsed->amount,
            'type' => $parsed->type,
            'comment' => $parsed->comment,
            'category_id' => $parsed->category?->id,
        ]);

        $transaction->save();

        $this->bot->sendMessage($chatId, $this->confirmation($user, $transaction));
    }

    /**
     * Extract the command name (without the leading slash) or null.
     */
    private function command(string $text): ?string
    {
        $token = explode(' ', trim($text))[0];
        $token = explode('@', $token)[0];

        if (! str_starts_with($token, '/')) {
            return null;
        }

        return substr($token, 1);
    }

    /**
     * Find or create the user for the given Telegram "from" payload.
     *
     * @param  array<string, mixed>  $from
     */
    private function userFor(array $from): User
    {
        return User::firstOrCreate(
            ['telegram_id' => (int) $from['id']],
            [
                'name' => $this->displayName($from),
                'currency' => Currency::RUB,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function displayName(array $from): string
    {
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));

        return $name !== '' ? $name : ($from['username'] ?? 'Пользователь');
    }

    private function confirmation(User $user, Transaction $transaction): string
    {
        $direction = $transaction->type === TransactionType::Income ? 'доход' : 'расход';
        $category = $transaction->category?->name ?? 'без категории';

        return sprintf('Записал: %s %s (%s, %s).', number_format($transaction->amount, 2, '.', ''), $user->currency->value, $direction, $category);
    }
}
