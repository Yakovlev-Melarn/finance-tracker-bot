<?php

namespace Tests\Feature\Services\Recurring;

use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\Parser\CategoryMatcher;
use App\Services\Recurring\RecurringException;
use App\Services\Recurring\RecurringManager;
use App\TransactionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function manager(): RecurringManager
    {
        return new RecurringManager(app(CategoryMatcher::class));
    }

    /**
     * @throws RecurringException
     */
    public function test_add_creates_entry_with_expense_type_by_default(): void
    {
        $entry = $this->manager()->add($this->user, 'Подписка', 500.0, 1);

        $this->assertSame('Подписка', $entry->name);
        $this->assertSame(500.0, (float) $entry->amount);
        $this->assertSame(1, $entry->day);
        $this->assertSame(TransactionType::Expense, $entry->type);
        $this->assertNull($entry->category_id);
        $this->assertDatabaseHas('recurring_transactions', [
            'user_id' => $this->user->id,
            'name' => 'Подписка',
            'amount' => 500.0,
            'day' => 1,
            'type' => 'expense',
        ]);
    }

    /**
     * @throws RecurringException
     */
    public function test_add_resolves_category_by_name(): void
    {
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Подписка',
            'keywords' => ['подписка'],
            'type' => TransactionType::Expense,
        ]);

        $entry = $this->manager()->add($this->user, 'подписка', 500.0, 1);

        $this->assertSame($category->id, $entry->category_id);
    }

    /**
     * @throws RecurringException
     */
    public function test_add_resolves_category_by_keyword(): void
    {
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Развлечения',
            'keywords' => ['подписка', 'музыка'],
            'type' => TransactionType::Expense,
        ]);

        $entry = $this->manager()->add($this->user, 'Подписка на музыку', 500.0, 1);

        $this->assertSame($category->id, $entry->category_id);
    }

    /**
     * @throws RecurringException
     */
    public function test_add_keeps_entry_uncategorized_when_no_category_matches(): void
    {
        Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $entry = $this->manager()->add($this->user, 'Интернет', 800.0, 5);

        $this->assertNull($entry->category_id);
    }

    public function test_add_rejects_empty_name(): void
    {
        $this->expectException(RecurringException::class);
        $this->expectExceptionMessage('название');

        $this->manager()->add($this->user, '   ', 500.0, 1);
    }

    public function test_add_rejects_zero_amount(): void
    {
        $this->expectException(RecurringException::class);
        $this->expectExceptionMessage('больше нуля');

        $this->manager()->add($this->user, 'Подписка', 0.0, 1);
    }

    public function test_add_rejects_day_below_one(): void
    {
        $this->expectException(RecurringException::class);
        $this->expectExceptionMessage('от 1 до 31');

        $this->manager()->add($this->user, 'Подписка', 500.0, 0);
    }

    public function test_add_rejects_day_above_thirty_one(): void
    {
        $this->expectException(RecurringException::class);
        $this->expectExceptionMessage('от 1 до 31');

        $this->manager()->add($this->user, 'Подписка', 500.0, 32);
    }

    /**
     * @throws RecurringException
     */
    public function test_add_rejects_duplicate_name_case_insensitively(): void
    {
        $this->manager()->add($this->user, 'Подписка', 500.0, 1);

        $this->expectException(RecurringException::class);
        $this->expectExceptionMessage('уже существует');

        $this->manager()->add($this->user, 'подписка', 700.0, 2);
    }

    /**
     * @throws RecurringException
     */
    public function test_all_for_is_sorted_by_day_then_name(): void
    {
        $this->manager()->add($this->user, 'Б', 10.0, 5);
        $this->manager()->add($this->user, 'А', 20.0, 5);
        $this->manager()->add($this->user, 'В', 30.0, 1);

        $entries = $this->manager()->allFor($this->user);

        $this->assertSame(
            ['В', 'А', 'Б'],
            $entries->map(static fn (RecurringTransaction $entry): string => $entry->name)->all(),
        );
    }

    /**
     * @throws RecurringException
     */
    public function test_remove_deletes_entry(): void
    {
        $entry = $this->manager()->add($this->user, 'Подписка', 500.0, 1);

        $this->manager()->remove($this->user, 'подписка');

        $this->assertNull($entry->fresh());
    }

    public function test_remove_fails_when_entry_not_found(): void
    {
        $this->expectException(RecurringException::class);
        $this->expectExceptionMessage('не найдена');

        $this->manager()->remove($this->user, 'Подписка');
    }

    /**
     * @throws RecurringException
     */
    public function test_due_on_returns_entry_for_matching_day(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $entry = $this->manager()->add($this->user, 'Подписка', 500.0, 5);

        $due = $this->manager()->dueOn($this->user, Carbon::now());

        $this->assertCount(1, $due);
        $this->assertSame($entry->id, $due->first()->id);
    }

    /**
     * @throws RecurringException
     */
    public function test_due_on_skips_entry_for_another_day(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->manager()->add($this->user, 'Подписка', 500.0, 6);

        $this->assertCount(0, $this->manager()->dueOn($this->user, Carbon::now()));
    }

    /**
     * @throws RecurringException
     */
    public function test_due_on_skips_day_beyond_month_length(): void
    {
        Carbon::setTestNow('2026-02-28 09:00:00');
        $this->manager()->add($this->user, 'Конец месяца', 100.0, 31);

        $this->assertCount(0, $this->manager()->dueOn($this->user, Carbon::now()));
    }

    /**
     * @throws RecurringException
     */
    public function test_due_on_skips_entry_already_run_on_the_date(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $entry = $this->manager()->add($this->user, 'Подписка', 500.0, 5);
        $entry->forceFill(['last_run_date' => Carbon::now()])->save();

        $this->assertCount(0, $this->manager()->dueOn($this->user, Carbon::now()));
    }

    /**
     * @throws RecurringException
     */
    public function test_due_on_returns_entry_run_on_an_earlier_day(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $entry = $this->manager()->add($this->user, 'Подписка', 500.0, 5);
        $entry->forceFill(['last_run_date' => Carbon::now()->subDay()])->save();

        $this->assertCount(1, $this->manager()->dueOn($this->user, Carbon::now()));
    }

    /**
     * @throws RecurringException
     */
    public function test_run_creates_transaction_and_marks_entry_as_run(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Подписка',
            'keywords' => ['подписка'],
            'type' => TransactionType::Expense,
        ]);
        $entry = $this->manager()->add($this->user, 'Подписка', 500.0, 5);

        $transaction = $this->manager()->run($entry, Carbon::now());

        $this->assertSame($this->user->id, $transaction->user_id);
        $this->assertSame($category->id, $transaction->category_id);
        $this->assertSame(500.0, (float) $transaction->amount);
        $this->assertSame(TransactionType::Expense, $transaction->type);
        $this->assertSame('Подписка', $transaction->comment);
        $this->assertSame(Carbon::now()->startOfDay()->format('Y-m-d'), $entry->fresh()->last_run_date->format('Y-m-d'));
        $this->assertCount(0, $this->manager()->dueOn($this->user, Carbon::now()));
    }

    /**
     * @throws RecurringException
     */
    public function test_run_with_deleted_category_creates_uncategorized_transaction(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Подписка',
            'keywords' => ['подписка'],
            'type' => TransactionType::Expense,
        ]);
        $entry = $this->manager()->add($this->user, 'Подписка', 500.0, 5);
        $category->delete();
        $entry->refresh();

        $transaction = $this->manager()->run($entry, Carbon::now());

        $this->assertNull($transaction->category_id);
    }
}
