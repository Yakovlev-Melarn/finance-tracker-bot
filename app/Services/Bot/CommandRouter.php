<?php

namespace App\Services\Bot;

use App\Currency;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Budgets\BudgetException;
use App\Services\Budgets\BudgetManager;
use App\Services\Categories\CategoryException;
use App\Services\Categories\CategoryFormatter;
use App\Services\Categories\CategoryManager;
use App\Services\Parser\TransactionParseException;
use App\Services\Parser\TransactionParser;
use App\Services\Recurring\RecurringException;
use App\Services\Recurring\RecurringManager;
use App\Services\Reports\ReportBuilder;
use App\Support\Markdown;
use App\Support\RussianPlural;
use App\TransactionType;
use Illuminate\Support\Carbon;
use Telegram\Bot\Exceptions\TelegramSDKException;

readonly class CommandRouter
{
    private const array MONTHS = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];

    public function __construct(
        private BotMessenger $bot,
        private TransactionParser $parser,
        private ReportBuilder $reports,
        private CategoryManager $categories,
        private BudgetManager $budgets,
        private RecurringManager $recurring,
        private CallbackRouter $callbacks,
        private PendingAction $pending,
    ) {}

    /**
     * Route an incoming Telegram update to the appropriate handler.
     *
     * @param  array{message?: array<string, mixed>, callback_query?: array<string, mixed>}  $update
     *
     * @throws TelegramSDKException
     */
    public function handle(array $update): void
    {
        $callback = $update['callback_query'] ?? null;

        if (is_array($callback)) {
            $this->callbacks->handle($callback);

            return;
        }

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
            'budget' => $this->handleBudgets($chatId, $from, $text),
            'recurring' => $this->handleRecurring($chatId, $from, $text),
            default => $this->handleText($chatId, $from, $text),
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
                $this->bot->sendMessage($chatId, CategoryFormatter::list($this->categories->all($user)), 'Markdown', Menu::categories());

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
     * Handle /budget: list, set, show and delete monthly category budgets.
     *
     * @param  array<string, mixed>  $from
     *
     * @throws TelegramSDKException
     */
    private function handleBudgets(int $chatId, array $from, string $text): void
    {
        $user = $this->userFor($from);
        $args = $this->argumentsAfter($text);

        try {
            if ($args === '') {
                $this->bot->sendMessage($chatId, $this->formatBudgetList($user), 'Markdown');

                return;
            }

            $tokens = explode(' ', $args);

            match (mb_strtolower($tokens[0])) {
                'delete' => $this->budgetDelete($user, $tokens[1] ?? '', $chatId),
                default => $this->budgetSetOrShow($user, $tokens, $chatId),
            };
        } catch (BudgetException|CategoryException $exception) {
            $this->bot->sendMessage($chatId, $exception->getMessage());
        }
    }

    /**
     * /budget Название [сумма]
     *
     * @param  list<string>  $tokens
     *
     * @throws BudgetException
     * @throws CategoryException
     * @throws TelegramSDKException
     */
    private function budgetSetOrShow(User $user, array $tokens, int $chatId): void
    {
        $category = $this->categories->findByName($user, $tokens[0]);

        if (count($tokens) >= 2) {
            $amount = $this->parseBudgetAmount($tokens[1]);
            $this->budgets->set($user, $category, $amount);

            $this->bot->sendMessage($chatId, sprintf('✅ Бюджет «%s» установлен: %s %s в месяц.', $category->name, number_format($amount, 2, '.', ''), $user->currency->value));

            return;
        }

        $budget = $user->budgets()->where('category_id', $category->id)->first();

        if ($budget === null) {
            $this->bot->sendMessage($chatId, sprintf('Бюджет «%s» не установлен. Пример: /budget %s 2000', $category->name, $category->name));

            return;
        }

        $this->bot->sendMessage($chatId, $this->budgetProgressLine($user, $category, (float) $budget->amount), 'Markdown');
    }

    /**
     * /budget delete Название
     *
     * @throws BudgetException
     * @throws CategoryException
     * @throws TelegramSDKException
     */
    private function budgetDelete(User $user, string $name, int $chatId): void
    {
        if ($name === '') {
            throw new BudgetException('Укажите название категории, например: /budget delete Кофе');
        }

        $category = $this->categories->findByName($user, $name);
        $this->budgets->remove($user, $category);

        $this->bot->sendMessage($chatId, sprintf('🗑 Бюджет «%s» удалён.', $category->name));
    }

    /**
     * @throws BudgetException
     */
    private function parseBudgetAmount(string $raw): float
    {
        $raw = str_replace(',', '.', trim($raw));

        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new BudgetException('Укажите сумму бюджета числом, например: /budget Кофе 2000');
        }

        return (float) $raw;
    }

    private function formatBudgetList(User $user): string
    {
        $budgets = $this->budgets->allFor($user);

        if ($budgets->isEmpty()) {
            return implode("\n", [
                '💰 *Бюджеты:*',
                '',
                'Пока нет. Пример: /budget Кофе 2000 — лимит 2000 '.$user->currency->value.' на «Кофе» в месяц.',
            ]);
        }

        $lines = [sprintf('💰 *Бюджеты (%s):*', self::MONTHS[Carbon::now()->month - 1]), ''];

        foreach ($budgets as $budget) {
            $lines[] = $this->budgetProgressLine($user, $budget->category, $budget->amount);
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    private function budgetProgressLine(User $user, Category $category, float $limit): string
    {
        $spent = $this->budgets->spent($user, $category);
        $percent = $limit > 0 ? (int) round($spent / $limit * 100) : 0;

        return sprintf('%s %s: %s / %s %s (%d%%)%s',
            $category->type === TransactionType::Income ? '💵' : '💸',
            Markdown::escape($category->name),
            number_format($spent, 2, '.', ''),
            number_format($limit, 2, '.', ''),
            $user->currency->value,
            $percent,
            "\n".$this->progressBar($percent),
        );
    }

    private function progressBar(int $percent): string
    {
        $filled = (int) min(10, (int) round($percent / 10));

        return '['.str_repeat('█', $filled).str_repeat('░', 10 - $filled).']';
    }

    /**
     * Handle /recurring: list, add and delete scheduled monthly entries.
     *
     * @param  array<string, mixed>  $from
     *
     * @throws TelegramSDKException
     */
    private function handleRecurring(int $chatId, array $from, string $text): void
    {
        $user = $this->userFor($from);
        $args = $this->argumentsAfter($text);

        try {
            if ($args === '') {
                $this->bot->sendMessage($chatId, $this->formatRecurringList($user), 'Markdown');

                return;
            }

            $tokens = explode(' ', $args);

            match (mb_strtolower($tokens[0])) {
                'add' => $this->recurringAdd($user, $tokens, $chatId),
                'delete' => $this->recurringDelete($user, $tokens, $chatId),
                default => $this->bot->sendMessage($chatId, $this->recurringUsage()),
            };
        } catch (RecurringException $exception) {
            $this->bot->sendMessage($chatId, $exception->getMessage());
        }
    }

    /**
     * /recurring add Название [слово ...] сумма число [доход]
     *
     * @param  list<string>  $tokens
     *
     * @throws RecurringException
     * @throws TelegramSDKException
     */
    private function recurringAdd(User $user, array $tokens, int $chatId): void
    {
        $type = TransactionType::Expense;
        $rest = array_slice($tokens, 1);

        if ($rest !== [] && in_array(mb_strtolower(end($rest)), ['доход', 'доходы', 'income'], true)) {
            $type = TransactionType::Income;
            $rest = array_slice($rest, 0, -1);
        }

        if (count($rest) < 3) {
            throw new RecurringException($this->recurringUsage());
        }

        $name = implode(' ', array_slice($rest, 0, -2));
        $amount = $this->parseRecurringAmount($rest[count($rest) - 2]);
        $day = $this->parseRecurringDay($rest[count($rest) - 1]);

        $entry = $this->recurring->add($user, $name, $amount, $day, $type);

        $direction = $type === TransactionType::Income ? 'доход' : 'расход';

        $this->bot->sendMessage($chatId, sprintf('✅ Регулярная запись «%s» создана: %s %s, %d-е число (%s).', $entry->name, number_format((float) $entry->amount, 2, '.', ''), $user->currency->value, $entry->day, $direction));
    }

    /**
     * /recurring delete Название
     *
     * @param  list<string>  $tokens
     *
     * @throws RecurringException
     * @throws TelegramSDKException
     */
    private function recurringDelete(User $user, array $tokens, int $chatId): void
    {
        $name = trim(implode(' ', array_slice($tokens, 1)));

        if ($name === '') {
            throw new RecurringException('Укажите название записи, например: /recurring delete Подписка');
        }

        $this->recurring->remove($user, $name);

        $this->bot->sendMessage($chatId, sprintf('🗑 Регулярная запись «%s» удалена.', $name));
    }

    /**
     * @throws RecurringException
     */
    private function parseRecurringAmount(string $raw): float
    {
        $raw = str_replace(',', '.', trim($raw));

        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new RecurringException('Укажите сумму числом, например: /recurring add Подписка 500 1');
        }

        return (float) $raw;
    }

    /**
     * @throws RecurringException
     */
    private function parseRecurringDay(string $raw): int
    {
        if (! is_numeric($raw) || (int) $raw != (float) $raw || (int) $raw < 1 || (int) $raw > 31) {
            throw new RecurringException('Число месяца должно быть числом от 1 до 31.');
        }

        return (int) $raw;
    }

    /**
     */
    private function formatRecurringList(User $user): string
    {
        $entries = $this->recurring->allFor($user);

        if ($entries->isEmpty()) {
            return implode("\n", [
                '🔁 *Регулярные записи:*',
                '',
                'Пока нет. Пример: /recurring add Подписка 500 1 — 500 '.$user->currency->value.' 1-го числа каждого месяца.',
            ]);
        }

        $lines = ['🔁 *Регулярные записи:*', ''];

        foreach ($entries as $entry) {
            $direction = $entry->type === TransactionType::Income ? 'доход' : 'расход';

            $lines[] = sprintf('%s — %s %s, %d-е число (%s)%s',
                Markdown::escape($entry->name),
                number_format((float) $entry->amount, 2, '.', ''),
                $user->currency->value,
                $entry->day,
                $direction,
                $entry->category !== null ? ', '.$entry->category->name : '',
            );
        }

        return implode("\n", $lines);
    }

    private function recurringUsage(): string
    {
        return implode("\n", [
            'Управление регулярными записями:',
            '/recurring — список',
            '/recurring add Подписка 500 1 — создать (название, сумма, число месяца)',
            '/recurring add Зарплата 120000 5 доход — создать доходную',
            '/recurring delete Подписка — удалить',
            '',
            'Числа 30 и 31 в коротких месяцах не выполняются.',
        ]);
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
     * Register the user behind the /start command and show them the main menu.
     *
     * @param  array<string, mixed>  $from
     *
     * @throws TelegramSDKException
     */
    private function registerUser(int $chatId, array $from): void
    {
        $user = $this->userFor($from);

        $this->bot->sendPhoto($chatId, resource_path('images/menu.png'), Menu::welcome($user->name), null, Menu::main());
    }

    /**
     * Handle free text: a pending category action first, otherwise a transaction.
     *
     * @param  array<string, mixed>  $from
     *
     * @throws TelegramSDKException
     */
    private function handleText(int $chatId, array $from, string $text): void
    {
        $user = $this->userFor($from);
        $action = $this->pending->get($user->telegram_id);

        if ($action === null) {
            $this->recordTransaction($chatId, $user, $text);

            return;
        }

        $this->pending->clear($user->telegram_id);

        try {
            match ($action) {
                'cats_add' => $this->categoriesAdd($user, $text, $chatId),
                'cats_rename' => $this->categoriesRename($user, $text, $chatId),
                'cats_delete' => $this->categoriesDelete($user, $text, $chatId),
                default => $this->recordTransaction($chatId, $user, $text),
            };
        } catch (CategoryException $exception) {
            $this->bot->sendMessage($chatId, $exception->getMessage()."\n\nНажми «🏠 В меню», чтобы вернуться в меню.", null, Menu::main());
        }
    }

    /**
     * Parse a free-text message into a transaction, store it and confirm.
     *
     * @throws TelegramSDKException
     */
    private function recordTransaction(int $chatId, User $user, string $text): void
    {
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

        $alert = $this->budgets->alertAfterTransaction($user, $transaction);

        if ($alert !== null) {
            $this->bot->sendMessage($chatId, $alert);
        }
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
