<?php

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\Bot\BotMessenger;
use App\TransactionType;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Tests\TestCase;

class RecurringTransactionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function dueEntry(User $user, string $name, float $amount, TransactionType $type = TransactionType::Expense): RecurringTransaction
    {
        return RecurringTransaction::create([
            'user_id' => $user->id,
            'name' => $name,
            'amount' => $amount,
            'day' => Carbon::now()->day,
            'type' => $type,
        ]);
    }

    public function test_records_due_entry_and_notifies_the_user(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $user = User::factory()->create(['telegram_id' => 42]);
        $entry = $this->dueEntry($user, 'Подписка', 500.0);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '🔁 Подписка: 500.00 RUB (расход).');

        $this->artisan('recurring:run')
            ->assertSuccessful();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'amount' => 500.0,
            'type' => 'expense',
            'comment' => 'Подписка',
        ]);
        $this->assertSame('2026-10-05', $entry->fresh()->last_run_date->format('Y-m-d'));
    }

    public function test_records_income_entry_as_income(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $user = User::factory()->create(['telegram_id' => 42]);
        $this->dueEntry($user, 'Зарплата', 120000.0, TransactionType::Income);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '🔁 Зарплата: 120000.00 RUB (доход).');

        $this->artisan('recurring:run')
            ->assertSuccessful();

        $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'type' => 'income', 'comment' => 'Зарплата']);
    }

    public function test_does_nothing_when_no_entry_is_due(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $user = User::factory()->create(['telegram_id' => 42]);
        $user->recurringTransactions()->create([
            'name' => 'Подписка',
            'amount' => 500.0,
            'day' => 6,
            'type' => TransactionType::Expense,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')->never();

        $this->artisan('recurring:run')
            ->assertSuccessful();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_does_not_record_entry_twice_on_the_same_day(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $user = User::factory()->create(['telegram_id' => 42]);
        $this->dueEntry($user, 'Подписка', 500.0);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')->once()->with(42, Mockery::any());
        $bot->shouldReceive('sendMessage')->never();

        $this->artisan('recurring:run')->assertSuccessful();
        $this->artisan('recurring:run')->assertSuccessful();

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_sends_budget_alert_when_recurring_expense_crosses_budget(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $user = User::factory()->create(['telegram_id' => 42]);
        $category = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Подписка',
            'keywords' => ['подписка'],
            'type' => TransactionType::Expense,
        ]);
        $user->recurringTransactions()->create([
            'name' => 'Подписка',
            'amount' => 150.0,
            'day' => now()->day,
            'type' => TransactionType::Expense,
            'category_id' => $category->id,
        ]);
        $user->budgets()->create([
            'category_id' => $category->id,
            'amount' => 1000.0,
        ]);
        $user->transactions()->create([
            'category_id' => $category->id,
            'amount' => 700.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '🔁 Подписка: 150.00 RUB (расход).');
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '⚠️ Бюджет «Подписка»: 850.00 из 1000.00 RUB (85%).');

        $this->artisan('recurring:run')
            ->assertSuccessful();
    }

    public function test_continues_with_other_users_when_a_send_fails(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $first = User::factory()->create(['telegram_id' => 42]);
        $second = User::factory()->create(['telegram_id' => 43]);
        $this->dueEntry($first, 'Подписка', 500.0);
        $this->dueEntry($second, 'Интернет', 800.0);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::any())
            ->andThrow(new TelegramSDKException('connection failed'));
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(43, '🔁 Интернет: 800.00 RUB (расход).');

        $this->artisan('recurring:run')
            ->assertSuccessful();
    }

    public function test_recurring_run_is_scheduled_daily_at_nine_moscow_time(): void
    {
        $event = null;

        foreach (app(Schedule::class)->events() as $candidate) {
            if ($candidate instanceof Event && str_contains((string) $candidate->command, 'recurring:run')) {
                $event = $candidate;

                break;
            }
        }

        if (! $event instanceof Event) {
            $this->fail('The recurring run event was not found in the schedule.');
        }

        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame('Europe/Moscow', $event->timezone);
    }
}
