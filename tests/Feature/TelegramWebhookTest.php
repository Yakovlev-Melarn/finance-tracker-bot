<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bot\BotMessenger;
use App\Services\Bot\Menu;
use App\TransactionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_webhook_returns_ok_for_unknown_update_shape(): void
    {
        $this->mock(BotMessenger::class);

        $response = $this->post('/telegram/webhook', [
            'update_id' => 1002,
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_start_command_registers_user_and_shows_menu_photo(): void
    {
        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendPhoto')
            ->once()
            ->with(42, resource_path('images/menu.png'), Menu::welcome('Ivan Petrov'), null, Menu::main());

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2001,
            'message' => [
                'message_id' => 1,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                    'last_name' => 'Petrov',
                ],
                'text' => '/start',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'telegram_id' => 42,
            'name' => 'Ivan Petrov',
            'currency' => 'RUB',
        ]);
    }

    public function test_start_command_does_not_duplicate_existing_user(): void
    {
        $user = User::factory()->create(['telegram_id' => 42, 'name' => 'Ivan Petrov']);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendPhoto')
            ->once()
            ->with(42, resource_path('images/menu.png'), Menu::welcome('Ivan Petrov'), null, Menu::main());

        $this->post('/telegram/webhook', [
            'update_id' => 2002,
            'message' => [
                'message_id' => 2,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                    'last_name' => 'Petrov',
                ],
                'text' => '/start',
            ],
        ]);

        $this->assertSame(1, User::where('telegram_id', 42)->count());
        $this->assertSame($user->id, User::where('telegram_id', 42)->value('id'));
    }

    public function test_transaction_message_saves_record_and_confirms(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $category = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе', 'латте', 'капучино'],
            'type' => TransactionType::Expense,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Записал: 150.00 RUB (расход, Кофе).');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2003,
            'message' => [
                'message_id' => 3,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => 'кофе 150',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'amount' => 150.0,
            'type' => 'expense',
            'comment' => 'кофе',
            'category_id' => $category->id,
        ]);
    }

    public function test_transaction_message_matches_income_category(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $category = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Зарплата',
            'keywords' => ['зарплата', 'аванс'],
            'type' => TransactionType::Income,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Записал: 50000.00 RUB (доход, Зарплата).');

        $this->post('/telegram/webhook', [
            'update_id' => 2004,
            'message' => [
                'message_id' => 4,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => 'зарплата 50000',
            ],
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'amount' => 50000.0,
            'type' => 'income',
            'comment' => 'зарплата',
            'category_id' => $category->id,
        ]);
    }

    public function test_unparseable_message_replies_with_hint_and_saves_nothing(): void
    {
        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Не понял. Пример записи: «кофе 150».');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2005,
            'message' => [
                'message_id' => 5,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => 'привет',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_reply_failure_returns_500_to_trigger_redelivery(): void
    {
        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendPhoto')
            ->once()
            ->andThrow(new TelegramSDKException('cURL error 35: TLS connect error'));

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2006,
            'message' => [
                'message_id' => 6,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/start',
            ],
        ]);

        $response->assertStatus(500);
        $response->assertJson(['ok' => false]);
        $this->assertDatabaseHas('users', ['telegram_id' => 42]);
    }

    public function test_stats_command_sends_weekly_summary_as_markdown(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $salary = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Зарплата',
            'keywords' => ['зарплата'],
            'type' => TransactionType::Income,
        ]);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'amount' => 50000,
            'type' => TransactionType::Income,
            'created_at' => now()->subDay(),
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, '💵 Доходы: *50000.00 RUB*')), 'Markdown');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2007,
            'message' => [
                'message_id' => 7,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/stats',
            ],
        ]);

        $response->assertOk();
    }

    public function test_history_command_sends_recent_transactions_as_markdown(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $coffee = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $coffee->id,
            'amount' => 150,
            'type' => TransactionType::Expense,
            'comment' => 'кофе',
            'created_at' => now()->subDay(),
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'Кофе') && str_contains($text, '150.00 RUB')), 'Markdown');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2008,
            'message' => [
                'message_id' => 8,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/history',
            ],
        ]);

        $response->assertOk();
    }

    public function test_categories_command_lists_categories(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе', 'латте'],
            'type' => TransactionType::Expense,
        ]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Зарплата',
            'keywords' => ['зарплата'],
            'type' => TransactionType::Income,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'Кофе: кофе, латте') && str_contains($text, 'Зарплата: зарплата')), 'Markdown', Menu::categories());

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2009,
            'message' => [
                'message_id' => 9,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/categories',
            ],
        ]);

        $response->assertOk();
    }

    public function test_categories_add_creates_category_with_keywords(): void
    {
        User::factory()->create(['telegram_id' => 42]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '✅ Категория «Кофе» создана, кофе, латте, капучино.');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2010,
            'message' => [
                'message_id' => 10,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/categories add Кофе, кофе, латте, капучино',
            ],
        ]);

        $response->assertOk();

        $category = Category::where('name', 'Кофе')->first();

        $this->assertNotNull($category);
        $this->assertSame(['кофе', 'латте', 'капучино'], $category->keywords);
        $this->assertSame(TransactionType::Expense, $category->type);
    }

    public function test_categories_add_rejects_duplicate_category(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Категория «Кофе» уже существует.');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2011,
            'message' => [
                'message_id' => 11,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/categories add Кофе, кофе',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_categories_rename_renames_category(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '✅ Категория «Кофе» переименована в «Капучино». Ключевые слова: эспрессо.');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2012,
            'message' => [
                'message_id' => 12,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/categories rename Кофе, Капучино, эспрессо',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('categories', ['name' => 'Капучино']);
        $this->assertDatabaseMissing('categories', ['name' => 'Кофе']);
    }

    public function test_categories_delete_removes_category_and_uncategorizes_transactions(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $coffee = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $coffee->id,
            'amount' => 150,
            'type' => TransactionType::Expense,
            'created_at' => now()->subDay(),
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '🗑 Категория «Кофе» удалена. Без категории: 1 запись.');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2013,
            'message' => [
                'message_id' => 13,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/categories delete Кофе',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', ['category_id' => null]);
    }

    public function test_categories_with_unknown_action_shows_usage(): void
    {
        User::factory()->create(['telegram_id' => 42]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'Управление категориями') && str_contains($text, '/categories add')));

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2014,
            'message' => [
                'message_id' => 14,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/categories foo',
            ],
        ]);

        $response->assertOk();
    }

    public function test_callback_query_button_sends_weekly_report(): void
    {
        User::factory()->create(['telegram_id' => 42]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once()->with(5001);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'Доходы')), 'Markdown');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2015,
            'callback_query' => [
                'id' => '5001',
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'message' => [
                    'message_id' => 99,
                    'chat' => [
                        'id' => 42,
                        'type' => 'private',
                    ],
                ],
                'data' => 'stats',
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_menu_button_edits_existing_message_in_place(): void
    {
        User::factory()->create(['telegram_id' => 42, 'name' => 'Ivan']);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once()->with(5002);
        $bot->shouldReceive('editMessage')
            ->once()
            ->with(42, 99, Menu::welcome('Ivan'), null, Menu::main());

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2016,
            'callback_query' => [
                'id' => '5002',
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'message' => [
                    'message_id' => 99,
                    'chat' => [
                        'id' => 42,
                        'type' => 'private',
                    ],
                ],
                'data' => 'menu',
            ],
        ]);

        $response->assertOk();
    }

    public function test_pending_category_add_creates_category_from_followup_text(): void
    {
        User::factory()->create(['telegram_id' => 42]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once()->with(5003);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '➕ Отправь название и ключевые слова через запятую, например: «Кофе, кофе, латте, капучино»');

        $this->post('/telegram/webhook', [
            'update_id' => 2017,
            'callback_query' => [
                'id' => '5003',
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'message' => [
                    'message_id' => 99,
                    'chat' => [
                        'id' => 42,
                        'type' => 'private',
                    ],
                ],
                'data' => 'cats_add',
            ],
        ])->assertOk();

        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '✅ Категория «Кофе» создана, кофе, латте.');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2018,
            'message' => [
                'message_id' => 100,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => 'Кофе, кофе, латте',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('categories', ['name' => 'Кофе']);
        $this->assertNull(Cache::get('bot:pending-action:42'));
    }

    public function test_pending_category_action_error_replies_with_menu_and_clears_pending(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once()->with(5004);
        $bot->shouldReceive('sendMessage')->once()->with(42, '🗑 Отправь название категории, например: «Кофе»');

        $this->post('/telegram/webhook', [
            'update_id' => 2019,
            'callback_query' => [
                'id' => '5004',
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'message' => [
                    'message_id' => 99,
                    'chat' => [
                        'id' => 42,
                        'type' => 'private',
                    ],
                ],
                'data' => 'cats_delete',
            ],
        ])->assertOk();

        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'не найдена') && str_contains($text, '🏠 В меню')), null, Menu::main());

        $this->post('/telegram/webhook', [
            'update_id' => 2020,
            'message' => [
                'message_id' => 101,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => 'Чай',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('categories', ['name' => 'Кофе']);
        $this->assertNull(Cache::get('bot:pending-action:42'));
    }

    public function test_budget_command_lists_empty_budgets(): void
    {
        User::factory()->create(['telegram_id' => 42]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'Пока нет') && str_contains($text, '/budget Кофе 2000')), 'Markdown');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2021,
            'message' => [
                'message_id' => 102,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/budget',
            ],
        ]);

        $response->assertOk();
    }

    public function test_budget_command_sets_budget(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '✅ Бюджет «Кофе» установлен: 2000.00 RUB в месяц.');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2022,
            'message' => [
                'message_id' => 103,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/budget Кофе 2000',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('budgets', [
            'user_id' => $user->id,
            'amount' => 2000.0,
        ]);
    }

    public function test_budget_command_shows_budget_progress(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $coffee = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);
        $user->budgets()->create([
            'category_id' => $coffee->id,
            'amount' => 2000.0,
        ]);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $coffee->id,
            'amount' => 850.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, '850.00 / 2000.00 RUB') && str_contains($text, '(43%)')), 'Markdown');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2023,
            'message' => [
                'message_id' => 104,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/budget Кофе',
            ],
        ]);

        $response->assertOk();
    }

    public function test_budget_command_shows_hint_when_budget_not_set(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Бюджет «Кофе» не установлен. Пример: /budget Кофе 2000');

        $this->post('/telegram/webhook', [
            'update_id' => 2024,
            'message' => [
                'message_id' => 105,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/budget Кофе',
            ],
        ])->assertOk();
    }

    public function test_budget_command_deletes_budget(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $coffee = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);
        $user->budgets()->create([
            'category_id' => $coffee->id,
            'amount' => 2000.0,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '🗑 Бюджет «Кофе» удалён.');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2025,
            'message' => [
                'message_id' => 106,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/budget delete Кофе',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_budget_command_rejects_invalid_amount(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Укажите сумму бюджета числом, например: /budget Кофе 2000');

        $this->post('/telegram/webhook', [
            'update_id' => 2026,
            'message' => [
                'message_id' => 107,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/budget Кофе abc',
            ],
        ])->assertOk();
    }

    public function test_budget_command_unknown_category_replies_with_hint(): void
    {
        User::factory()->create(['telegram_id' => 42]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Категория «Чай» не найдена.');

        $this->post('/telegram/webhook', [
            'update_id' => 2027,
            'message' => [
                'message_id' => 108,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => '/budget Чай 2000',
            ],
        ])->assertOk();
    }

    public function test_expense_crossing_budget_sends_warning_alert(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $coffee = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);
        $user->budgets()->create([
            'category_id' => $coffee->id,
            'amount' => 2000.0,
        ]);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $coffee->id,
            'amount' => 700.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Записал: 900.00 RUB (расход, Кофе).');
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '⚠️ Бюджет «Кофе»: 1600.00 из 2000.00 RUB (80%).');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2028,
            'message' => [
                'message_id' => 109,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => 'кофе 900',
            ],
        ]);

        $response->assertOk();
    }

    public function test_expense_overrunning_budget_sends_overrun_alert(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $coffee = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);
        $user->budgets()->create([
            'category_id' => $coffee->id,
            'amount' => 2000.0,
        ]);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $coffee->id,
            'amount' => 1900.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Записал: 300.00 RUB (расход, Кофе).');
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '🚨 Бюджет «Кофе» превышен: 2200.00 из 2000.00 RUB.');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2029,
            'message' => [
                'message_id' => 110,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => 'кофе 300',
            ],
        ]);

        $response->assertOk();
    }

    public function test_income_transaction_does_not_trigger_budget_alert(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        $coffee = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);
        $user->budgets()->create([
            'category_id' => $coffee->id,
            'amount' => 1000.0,
        ]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Зарплата',
            'keywords' => ['зарплата'],
            'type' => TransactionType::Income,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Записал: 50000.00 RUB (доход, Зарплата).');

        $response = $this->post('/telegram/webhook', [
            'update_id' => 2030,
            'message' => [
                'message_id' => 111,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => 'зарплата 50000',
            ],
        ]);

        $response->assertOk();
    }
}
