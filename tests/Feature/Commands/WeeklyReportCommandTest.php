<?php

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bot\BotMessenger;
use App\TransactionType;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Tests\TestCase;

class WeeklyReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_weekly_summary_to_every_user(): void
    {
        $active = User::factory()->create(['telegram_id' => 42]);
        User::factory()->create(['telegram_id' => 43]);

        $salary = Category::factory()->create([
            'user_id' => $active->id,
            'name' => 'Зарплата',
            'keywords' => ['зарплата'],
            'type' => TransactionType::Income,
        ]);
        Transaction::factory()->create([
            'user_id' => $active->id,
            'category_id' => $salary->id,
            'amount' => 50000,
            'type' => TransactionType::Income,
            'created_at' => now()->subDay(),
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, '💵 Доходы: *50000.00 RUB*')), 'Markdown');
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(43, Mockery::on(fn (string $text): bool => str_contains($text, '💵 Доходы: *0.00 RUB*')), 'Markdown');

        $this->artisan('reports:weekly')
            ->assertSuccessful();
    }

    public function test_continues_with_other_users_when_a_send_fails(): void
    {
        User::factory()->create(['telegram_id' => 42]);
        User::factory()->create(['telegram_id' => 43]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::any(), 'Markdown')
            ->andThrow(new TelegramSDKException('connection failed'));
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(43, Mockery::any(), 'Markdown');

        $this->artisan('reports:weekly')
            ->assertSuccessful();
    }

    public function test_weekly_report_is_scheduled_every_monday_at_nine_moscow_time(): void
    {
        $event = null;

        foreach (app(Schedule::class)->events() as $candidate) {
            if ($candidate instanceof Event && str_contains((string) $candidate->command, 'reports:weekly')) {
                $event = $candidate;

                break;
            }
        }

        if (! $event instanceof Event) {
            $this->fail('The weekly report event was not found in the schedule.');
        }

        $this->assertSame('0 9 * * 1', $event->expression);
        $this->assertSame('Europe/Moscow', $event->timezone);
    }
}
