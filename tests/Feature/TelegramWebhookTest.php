<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Services\Bot\BotMessenger;
use App\TransactionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_returns_ok_for_unknown_update_shape(): void
    {
        $this->mock(BotMessenger::class);

        $response = $this->post('/telegram/webhook', [
            'update_id' => 1002,
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_start_command_registers_user_and_sends_welcome(): void
    {
        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Привет, Ivan Petrov! Отправляй записи вида «кофе 150».');

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
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, 'Привет, Ivan Petrov! Отправляй записи вида «кофе 150».');

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
        $bot->shouldReceive('sendMessage')
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
}
