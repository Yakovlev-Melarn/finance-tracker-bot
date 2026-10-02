<?php

namespace Tests\Feature\Services\Bot;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bot\BotMessenger;
use App\Services\Bot\CallbackRouter;
use App\Services\Bot\Menu;
use App\Services\Bot\PendingAction;
use App\TransactionType;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Tests\TestCase;

class CallbackRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function callbackQuery(array $overrides = []): array
    {
        return array_merge([
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
            'data' => 'menu',
        ], $overrides);
    }

    /**
     * @throws BindingResolutionException
     */
    private function router(): CallbackRouter
    {
        return $this->app->make(CallbackRouter::class);
    }

    /**
     * @throws BindingResolutionException
     * @throws TelegramSDKException
     */
    public function test_callback_is_acknowledged(): void
    {
        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once()->with(5001);
        $bot->shouldReceive('editMessage')->once();

        $this->router()->handle($this->callbackQuery());
    }

    /**
     * @throws BindingResolutionException
     * @throws TelegramSDKException
     */
    public function test_stats_button_sends_weekly_report(): void
    {
        User::factory()->create(['telegram_id' => 42]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'Итоги за 7 дней')), 'Markdown');

        $this->router()->handle($this->callbackQuery(['data' => 'stats']));
    }

    /**
     * @throws BindingResolutionException
     * @throws TelegramSDKException
     */
    public function test_history_button_sends_recent_transactions(): void
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
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'Последние записи') && str_contains($text, 'Кофе')), 'Markdown');

        $this->router()->handle($this->callbackQuery(['data' => 'history']));
    }

    /**
     * @throws TelegramSDKException
     * @throws BindingResolutionException
     */
    public function test_record_button_shows_prompt_and_cancels_pending_action(): void
    {
        User::factory()->create(['telegram_id' => 42]);
        $this->app->make(PendingAction::class)->set(42, 'cats_add');

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'Отправь запись текстом')));

        $this->router()->handle($this->callbackQuery(['data' => 'record']));

        $this->assertNull($this->app->make(PendingAction::class)->get(42));
    }

    /**
     * @throws BindingResolutionException
     * @throws TelegramSDKException
     */
    public function test_cats_button_shows_list_with_submenu_keyboard(): void
    {
        $user = User::factory()->create(['telegram_id' => 42]);
        Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, Mockery::on(fn (string $text): bool => str_contains($text, 'Мои категории')), 'Markdown', Menu::categories());

        $this->router()->handle($this->callbackQuery(['data' => 'cats']));
    }

    /**
     * @throws BindingResolutionException
     * @throws TelegramSDKException
     */
    public function test_cats_add_button_stores_pending_action_and_prompts(): void
    {
        User::factory()->create(['telegram_id' => 42]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('sendMessage')
            ->once()
            ->with(42, '➕ Отправь название и ключевые слова через запятую, например: «Кофе, кофе, латте, капучино»');

        $this->router()->handle($this->callbackQuery(['data' => 'cats_add']));

        $this->assertSame('cats_add', $this->app->make(PendingAction::class)->get(42));
    }

    /**
     * @throws BindingResolutionException
     * @throws TelegramSDKException
     */
    public function test_menu_button_edits_existing_message_in_place(): void
    {
        User::factory()->create(['telegram_id' => 42, 'name' => 'Ivan']);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('editMessage')
            ->once()
            ->with(42, 99, Menu::welcome('Ivan'), null, Menu::main());
        $bot->shouldReceive('sendPhoto')->never();

        $this->router()->handle($this->callbackQuery(['data' => 'menu']));
    }

    /**
     * @throws BindingResolutionException
     * @throws TelegramSDKException
     */
    public function test_menu_button_sends_fresh_photo_when_message_cannot_be_edited(): void
    {
        User::factory()->create(['telegram_id' => 42, 'name' => 'Ivan']);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('editMessage')
            ->once()
            ->andThrow(new TelegramSDKException('Bad Request: message to edit not found'));
        $bot->shouldReceive('sendPhoto')
            ->once()
            ->with(42, resource_path('images/menu.png'), Menu::welcome('Ivan'), null, Menu::main());

        $this->router()->handle($this->callbackQuery(['data' => 'menu']));
    }

    /**
     * @throws BindingResolutionException
     * @throws TelegramSDKException
     */
    public function test_menu_button_without_message_id_sends_fresh_photo(): void
    {
        User::factory()->create(['telegram_id' => 42, 'name' => 'Ivan']);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('editMessage')->never();
        $bot->shouldReceive('sendPhoto')
            ->once()
            ->with(42, resource_path('images/menu.png'), Menu::welcome('Ivan'), null, Menu::main());

        $this->router()->handle($this->callbackQuery([
            'data' => 'menu',
            'message' => ['chat' => ['id' => 42, 'type' => 'private']],
        ]));
    }

    /**
     * @throws TelegramSDKException
     * @throws BindingResolutionException
     */
    public function test_menu_button_with_null_message_sends_fresh_photo(): void
    {
        User::factory()->create(['telegram_id' => 42, 'name' => 'Ivan']);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('editMessage')->never();
        $bot->shouldReceive('sendPhoto')
            ->once()
            ->with(42, resource_path('images/menu.png'), Menu::welcome('Ivan'), null, Menu::main());

        $this->router()->handle($this->callbackQuery([
            'data' => 'menu',
            'message' => null,
        ]));
    }

    /**
     * @throws TelegramSDKException
     * @throws BindingResolutionException
     */
    public function test_unknown_callback_data_does_nothing(): void
    {
        User::factory()->create(['telegram_id' => 42]);

        $bot = $this->mock(BotMessenger::class);
        $bot->shouldReceive('answerCallback')->once();
        $bot->shouldReceive('sendMessage')->never();
        $bot->shouldReceive('sendPhoto')->never();
        $bot->shouldReceive('editMessage')->never();

        $this->router()->handle($this->callbackQuery(['data' => 'bogus']));
    }
}
