<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class BudgetManager
{
    /**
     * Set (create or update) the monthly budget of a category.
     *
     * @throws BudgetException
     */
    public function set(User $user, Category $category, float $amount): Budget
    {
        if ($amount <= 0.0) {
            throw new BudgetException('Укажите сумму бюджета больше нуля.');
        }

        return Budget::updateOrCreate(
            ['user_id' => $user->id, 'category_id' => $category->id],
            ['amount' => $amount],
        );
    }

    /**
     * Remove the budget of a category.
     *
     * @throws BudgetException
     */
    public function remove(User $user, Category $category): void
    {
        $deleted = $user->budgets()->where('category_id', $category->id)->delete();

        if ($deleted === 0) {
            throw new BudgetException('Бюджет «'.$category->name.'» не установлен.');
        }
    }

    /**
     * All budgets of the user, sorted by category name.
     *
     * @return Collection<int, Budget>
     */
    public function allFor(User $user): Collection
    {
        $budgets = $user->budgets()->with('category')->get();

        return new Collection(
            $budgets->sortBy(static fn (Budget $budget): string => mb_strtolower($budget->category->name))
                ->values()
                ->all(),
        );
    }

    /**
     * The user's expense spent on the category within the current month.
     */
    public function spent(User $user, Category $category): float
    {
        return (float) $user->transactions()
            ->where('category_id', $category->id)
            ->where('type', TransactionType::Expense)
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->sum('amount');
    }

    /**
     * A spending alert if this transaction crossed the 80% or 100%
     * threshold of the category budget. Null otherwise.
     */
    public function alertAfterTransaction(User $user, Transaction $transaction): ?string
    {
        if ($transaction->type !== TransactionType::Expense || $transaction->category_id === null) {
            return null;
        }

        $budget = $user->budgets()->where('category_id', $transaction->category_id)->first();

        if ($budget === null) {
            return null;
        }

        $category = $transaction->category;
        $limit = (float) $budget->amount;
        $spent = $this->spent($user, $category);
        $before = $spent - (float) $transaction->amount;
        $currency = $user->currency->value;

        if ($before < $limit && $spent >= $limit) {
            return sprintf('🚨 Бюджет «%s» превышен: %s из %s %s.', $category->name, $this->money($spent), $this->money($limit), $currency);
        }

        if ($before < $limit * 0.8 && $spent >= $limit * 0.8) {
            return sprintf('⚠️ Бюджет «%s»: %s из %s %s (%d%%).', $category->name, $this->money($spent), $this->money($limit), $currency, (int) round($spent / $limit * 100));
        }

        return null;
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
