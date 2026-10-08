<?php

namespace Tests\Feature\Services\Budgets;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Budgets\BudgetException;
use App\Services\Budgets\BudgetManager;
use App\TransactionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @return array{0: Budget, 1: Category}
     * @throws BudgetException
     */
    private function withBudget(float $amount, string $categoryName = 'Кофе'): array
    {
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => $categoryName,
            'keywords' => [mb_strtolower($categoryName)],
            'type' => TransactionType::Expense,
        ]);
        $budget = (new BudgetManager)->set($this->user, $category, $amount);

        return [$budget, $category];
    }

    /**
     * @throws BudgetException
     */
    public function test_set_creates_budget(): void
    {
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $budget = (new BudgetManager)->set($this->user, $category, 2000.0);

        $this->assertSame($this->user->id, $budget->user_id);
        $this->assertSame($category->id, $budget->category_id);
        $this->assertSame(2000.0, $budget->amount);
        $this->assertDatabaseHas('budgets', [
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 2000.0,
        ]);
    }

    /**
     * @throws BudgetException
     */
    public function test_set_updates_existing_budget(): void
    {
        [$budget] = $this->withBudget(2000.0);

        $updated = (new BudgetManager)->set($this->user, $budget->category, 1500.0);

        $this->assertSame($budget->id, $updated->id);
        $this->assertSame(1500.0, $updated->fresh()->amount);
        $this->assertDatabaseCount('budgets', 1);
    }

    public function test_set_rejects_zero_amount(): void
    {
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $this->expectException(BudgetException::class);
        $this->expectExceptionMessage('больше нуля');

        (new BudgetManager)->set($this->user, $category, 0.0);
    }

    public function test_set_rejects_negative_amount(): void
    {
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $this->expectException(BudgetException::class);

        (new BudgetManager)->set($this->user, $category, -100.0);
    }

    /**
     * @throws BudgetException
     */
    public function test_remove_deletes_budget(): void
    {
        [$budget, $category] = $this->withBudget(2000.0);

        (new BudgetManager)->remove($this->user, $category);

        $this->assertNull($budget->fresh());
    }

    public function test_remove_fails_when_budget_not_set(): void
    {
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);

        $this->expectException(BudgetException::class);
        $this->expectExceptionMessage('не установлен');

        (new BudgetManager)->remove($this->user, $category);
    }

    /**
     * @throws BudgetException
     */
    public function test_all_for_is_sorted_by_category_name(): void
    {
        $this->withBudget(100.0, 'Транспорт');
        $this->withBudget(200.0);

        $budgets = (new BudgetManager)->allFor($this->user);

        $this->assertSame(
            ['Кофе', 'Транспорт'],
            $budgets->map(static fn (Budget $budget): string => $budget->category->name)->all(),
        );
    }

    /**
     * @throws BudgetException
     */
    public function test_spent_only_counts_current_month_expenses(): void
    {
        [, $category] = $this->withBudget(2000.0);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 500.0,
            'type' => TransactionType::Expense,
            'created_at' => now()->subMonthNoOverflow(),
        ]);
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 350.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $this->assertSame(350.0, (new BudgetManager)->spent($this->user, $category));
    }

    /**
     * @throws BudgetException
     */
    public function test_spent_ignores_income_transactions(): void
    {
        [, $category] = $this->withBudget(2000.0);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 700.0,
            'type' => TransactionType::Income,
            'created_at' => now(),
        ]);

        $this->assertSame(0.0, (new BudgetManager)->spent($this->user, $category));
    }

    /**
     * @throws BudgetException
     */
    public function test_alert_after_crossing_eighty_percent(): void
    {
        [, $category] = $this->withBudget(1000.0);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 700.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 150.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $alert = (new BudgetManager)->alertAfterTransaction($this->user, $transaction);

        $this->assertSame('⚠️ Бюджет «Кофе»: 850.00 из 1000.00 RUB (85%).', $alert);
    }

    /**
     * @throws BudgetException
     */
    public function test_alert_after_crossing_full_budget(): void
    {
        [, $category] = $this->withBudget(1000.0);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 900.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 100.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $alert = (new BudgetManager)->alertAfterTransaction($this->user, $transaction);

        $this->assertSame('🚨 Бюджет «Кофе» превышен: 1000.00 из 1000.00 RUB.', $alert);
    }

    /**
     * @throws BudgetException
     */
    public function test_no_alert_below_eighty_percent(): void
    {
        [, $category] = $this->withBudget(1000.0);

        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 500.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $this->assertNull((new BudgetManager)->alertAfterTransaction($this->user, $transaction));
    }

    /**
     * @throws BudgetException
     */
    public function test_no_alert_when_budget_already_exceeded(): void
    {
        [, $category] = $this->withBudget(1000.0);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 1100.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 100.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $this->assertNull((new BudgetManager)->alertAfterTransaction($this->user, $transaction));
    }

    /**
     * @throws BudgetException
     */
    public function test_no_alert_for_income_transactions(): void
    {
        [, $category] = $this->withBudget(1000.0);

        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 1500.0,
            'type' => TransactionType::Income,
            'created_at' => now(),
        ]);

        $this->assertNull((new BudgetManager)->alertAfterTransaction($this->user, $transaction));
    }

    public function test_no_alert_without_budget(): void
    {
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Кофе',
            'keywords' => ['кофе'],
            'type' => TransactionType::Expense,
        ]);
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 150.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $this->assertNull((new BudgetManager)->alertAfterTransaction($this->user, $transaction));
    }

    /**
     * @throws BudgetException
     */
    public function test_no_alert_for_uncategorized_expense(): void
    {
        $this->withBudget(1000.0);

        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => null,
            'amount' => 150.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $this->assertNull((new BudgetManager)->alertAfterTransaction($this->user, $transaction));
    }

    /**
     * @throws BudgetException
     */
    public function test_alert_uses_the_user_currency(): void
    {
        $this->user->update(['currency' => 'USD']);

        [, $category] = $this->withBudget(1000.0);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 700.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 150.0,
            'type' => TransactionType::Expense,
            'created_at' => now(),
        ]);

        $alert = (new BudgetManager)->alertAfterTransaction($this->user, $transaction);

        $this->assertSame('⚠️ Бюджет «Кофе»: 850.00 из 1000.00 USD (85%).', $alert);
    }
}
