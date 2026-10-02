<?php

namespace App\Services\Bot;

use App\Currency;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Categories\CategoryException;
use App\Services\Categories\CategoryManager;
use App\Services\Parser\TransactionParseException;
use App\Services\Parser\TransactionParser;
use App\Services\Reports\ReportBuilder;
use App\Support\Markdown;
use App\Support\RussianPlural;
use App\TransactionType;
use Illuminate\Database\Eloquent\Collection;
use Telegram\Bot\Exceptions\TelegramSDKException;

readonly class CommandRouter
{
    public function __construct(
        private BotMessenger $bot,
        private TransactionParser $parser,
        private ReportBuilder $reports,
        private CategoryManager $categories,
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
            'categories' => $this->handleCategories($chatId, $from, $text),
            default => $this->recordTransaction($chatId, $from, $text),
        };
    }

    /**
     * Handle /categories: list, add, rename and delete the user's categories.
     *
     * @param  array<string, mixed>  $from
     *
     * @throws TelegramSDKException
     */
    private function handleCategories(int $chatId, array $from, string $text): void
    {
        $user = $this->userFor($from);
        $args = $this->argumentsAfter($text);

        try {
            if ($args === '') {
                $this->bot->sendMessage($chatId, $this->formatCategoryList($this->categories->all($user)), 'Markdown');

                return;
            }

            [$action, $rest] = array_pad(explode(' ', $args, 2), 2, '');

            match (mb_strtolower($action)) {
                'add' => $this->categoriesAdd($user, $rest, $chatId),
                'rename' => $this->categoriesRename($user, $rest, $chatId),
                'delete' => $this->categoriesDelete($user, $rest, $chatId),
                default => $this->bot->sendMessage($chatId, $this->categoryUsage()),
            };
        } catch (CategoryException $exception) {
            $this->bot->sendMessage($chatId, $exception->getMessage());
        }
    }

    /**
     * /categories add Название, ключ1, ключ2, ...
     *
     * @throws CategoryException
     * @throws TelegramSDKException
     */
    private function categoriesAdd(User $user, string $args, int $chatId): void
    {
        $tokens = $this->tokens($args);

        if ($tokens === []) {
            $this->bot->sendMessage($chatId, $this->categoryUsage());

            return;
        }

        $name = $tokens[0];
        $keywords = array_slice($tokens, 1);

        $category = $this->categories->create($user, $name, $keywords);

        $this->bot->sendMessage($chatId, $this->categoryCreated($category));
    }

    /**
     * /categories rename Старое, Новое[, ключ1, ключ2, ...]
     *
     * @throws CategoryException
     * @throws TelegramSDKException
     */
    private function categoriesRename(User $user, string $args, int $chatId): void
    {
        $tokens = $this->tokens($args);

        if (count($tokens) < 2) {
            $this->bot->sendMessage($chatId, $this->categoryUsage());

            return;
        }

        $oldName = $tokens[0];
        $newName = $tokens[1];
        $keywords = array_slice($tokens, 2);

        $category = $this->categories->rename($user, $oldName, $newName, $keywords);

        $this->bot->sendMessage($chatId, sprintf('✅ Категория «%s» переименована в «%s». Ключевые слова: %s.', $oldName, $category->name, $this->keywordsLabel($category)));
    }

    /**
     * /categories delete Название
     *
     * @throws CategoryException
     * @throws TelegramSDKException
     */
    private function categoriesDelete(User $user, string $args, int $chatId): void
    {
        $tokens = $this->tokens($args);

        if ($tokens === []) {
            $this->bot->sendMessage($chatId, $this->categoryUsage());

            return;
        }

        $affected = $this->categories->delete($user, $tokens[0]);

        $message = $affected > 0
            ? sprintf('🗑 Категория «%s» удалена. Без категории: %d %s.', $tokens[0], $affected, RussianPlural::of($affected, 'запись', 'записи', 'записей'))
            : sprintf('🗑 Категория «%s» удалена.', $tokens[0]);

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * The raw argument string following the first token of the message.
     */
    private function argumentsAfter(string $text): string
    {
        return trim(explode(' ', trim($text), 2)[1] ?? '');
    }

    /**
     * Split a comma-separated argument string into clean tokens.
     *
     * @return list<string>
     */
    private function tokens(string $args): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $args)),
            static fn (string $token): bool => $token !== '',
        ));
    }

    /**
     * @param  Collection<int, Category>  $categories
     */
    private function formatCategoryList(Collection $categories): string
    {
        if ($categories->isEmpty()) {
            return implode("\n", [
                '🗂 *Мои категории:*',
                '',
                'Пока пусто. Добавьте: /categories add Кофе, кофе, латте',
            ]);
        }

        $lines = ['🗂 *Мои категории:*', ''];

        foreach ($categories as $category) {
            $icon = $category->type === TransactionType::Income ? '💵' : '💸';
            $keywords = $category->keywords === []
                ? 'без ключевых слов'
                : implode(', ', array_map(Markdown::escape(...), $category->keywords));

            $lines[] = sprintf('%s %s: %s', $icon, Markdown::escape($category->name), $keywords);
        }

        return implode("\n", $lines);
    }

    private function categoryCreated(Category $category): string
    {
        $keywords = $category->keywords === [] ? '' : ', '.implode(', ', $category->keywords);

        return sprintf('✅ Категория «%s» создана%s.', $category->name, $keywords);
    }

    private function keywordsLabel(Category $category): string
    {
        return $category->keywords === [] ? 'нет' : implode(', ', $category->keywords);
    }

    private function categoryUsage(): string
    {
        return implode("\n", [
            'Управление категориями:',
            '/categories — список',
            '/categories add Кофе, кофе, латте — создать',
            '/categories rename Кофе, Капучино, эспрессо — переименовать (и новые ключевые слова)',
            '/categories delete Кофе — удалить',
        ]);
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
