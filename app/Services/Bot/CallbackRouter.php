<?php

namespace App\Services\Bot;

use App\Currency;
use App\Models\User;
use App\Services\Categories\CategoryFormatter;
use App\Services\Categories\CategoryManager;
use App\Services\Reports\ReportBuilder;
use Telegram\Bot\Exceptions\TelegramSDKException;

readonly class CallbackRouter
{
    public function __construct(
        private BotMessenger $bot,
        private ReportBuilder $reports,
        private CategoryManager $categories,
        private PendingAction $pending,
    ) {}

    /**
     * Handle an inline keyboard button press.
     *
     * @param  array<string, mixed>  $callbackQuery
     *
     * @throws TelegramSDKException
     */
    public function handle(array $callbackQuery): void
    {
        $this->bot->answerCallback((int) $callbackQuery['id']);

        $from = $callbackQuery['from'] ?? [];
        $user = $this->userFor($from);
        $this->pending->clear($user->telegram_id);

        $message = $callbackQuery['message'] ?? null;
        $chatId = is_array($message) && isset($message['chat']['id'])
            ? (int) $message['chat']['id']
            : (int) $from['id'];
        $data = (string) ($callbackQuery['data'] ?? '');

        match ($data) {
            'menu' => $this->showMenu($chatId, $message, $user),
            'record' => $this->bot->sendMessage($chatId, $this->recordPrompt()),
            'stats' => $this->sendStats($user, $chatId),
            'history' => $this->sendHistory($user, $chatId),
            'cats' => $this->showCategories($user, $chatId),
            'cats_add' => $this->promptCategoryAction($user, $chatId, 'cats_add', '➕ Отправь название и ключевые слова через запятую, например: «Кофе, кофе, латте, капучино»'),
            'cats_rename' => $this->promptCategoryAction($user, $chatId, 'cats_rename', '✏️ Отправь старое и новое название (и новые ключевые слова), например: «Кофе, Капучино, эспрессо»'),
            'cats_delete' => $this->promptCategoryAction($user, $chatId, 'cats_delete', '🗑 Отправь название категории, например: «Кофе»'),
            default => null,
        };
    }

    /**
     * Show the main menu, editing the pressed message in place when possible.
     *
     * @param  array<string, mixed>|null  $message  the message the button belongs to; null when it is too old
     *
     * @throws TelegramSDKException
     */
    private function showMenu(int $chatId, ?array $message, User $user): void
    {
        $messageId = $message['message_id'] ?? null;

        if (is_int($messageId)) {
            try {
                $this->bot->editMessage($chatId, $messageId, Menu::welcome($user->name), null, Menu::main());

                return;
            } catch (TelegramSDKException) {
                // The message is too old or was deleted — send a fresh menu instead.
            }
        }

        $this->bot->sendPhoto($chatId, resource_path('images/menu.png'), Menu::welcome($user->name), null, Menu::main());
    }

    /**
     * @throws TelegramSDKException
     */
    private function showCategories(User $user, int $chatId): void
    {
        $this->bot->sendMessage($chatId, CategoryFormatter::list($this->categories->all($user)), 'Markdown', Menu::categories());
    }

    /**
     * @throws TelegramSDKException
     */
    private function promptCategoryAction(User $user, int $chatId, string $action, string $text): void
    {
        $this->pending->set($user->telegram_id, $action);

        $this->bot->sendMessage($chatId, $text);
    }

    /**
     * @throws TelegramSDKException
     */
    private function sendStats(User $user, int $chatId): void
    {
        $stats = $this->reports->weekStats($user);

        $this->bot->sendMessage($chatId, $this->reports->formatWeekStats($stats, $user->currency), 'Markdown');
    }

    /**
     * @throws TelegramSDKException
     */
    private function sendHistory(User $user, int $chatId): void
    {
        $transactions = $this->reports->recentTransactions($user);

        $this->bot->sendMessage($chatId, $this->reports->formatHistory($transactions, $user->currency), 'Markdown');
    }

    private function recordPrompt(): string
    {
        return implode("\n", [
            '📝 Отправь запись текстом, например:',
            '',
            '«кофе 150»',
            '«такси 350»',
            '«зарплата 120000»',
            '',
            'Категория определяется по ключевым словам автоматически.',
        ]);
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
}
