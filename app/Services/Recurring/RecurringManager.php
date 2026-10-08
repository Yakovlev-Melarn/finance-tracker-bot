<?php

namespace App\Services\Recurring;

use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Parser\CategoryMatcher;
use App\TransactionType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final readonly class RecurringManager
{
    public function __construct(
        private CategoryMatcher $categories,
    ) {}

    /**
     * Create a recurring entry; the category is resolved from the entry name
     * (by exact name first, then by keywords) and may stay uncategorized.
     *
     * @throws RecurringException
     */
    public function add(User $user, string $name, float $amount, int $day, TransactionType $type = TransactionType::Expense): RecurringTransaction
    {
        $name = trim($name);

        if ($name === '') {
            throw new RecurringException('Укажите название записи, например: /recurring add Подписка 500 1');
        }

        if ($amount <= 0.0) {
            throw new RecurringException('Укажите сумму больше нуля, например: /recurring add Подписка 500 1');
        }

        if ($day < 1 || $day > 31) {
            throw new RecurringException('Число месяца должно быть от 1 до 31.');
        }

        $existing = $this->entryNamed($user, $name);

        if ($existing !== null) {
            throw new RecurringException('Запись «'.$existing->name.'» уже существует.');
        }

        $entry = new RecurringTransaction([
            'user_id' => $user->id,
            'name' => $name,
            'amount' => $amount,
            'day' => $day,
            'type' => $type,
            'category_id' => $this->resolveCategory($user, $name)?->id,
        ]);

        $entry->save();

        return $entry;
    }

    /**
     * All recurring entries of the user, sorted by day and then by name.
     *
     * @return Collection<int, RecurringTransaction>
     */
    public function allFor(User $user): Collection
    {
        $entries = $user->recurringTransactions()->with('category')->get();

        return new Collection(
            $entries
                ->sortBy(static fn (RecurringTransaction $entry): array => [$entry->day, mb_strtolower($entry->name)])
                ->values()
                ->all(),
        );
    }

    /**
     * Remove the recurring entry with the given (case-insensitive) name.
     *
     * @throws RecurringException
     */
    public function remove(User $user, string $name): void
    {
        $name = trim($name);

        $entry = $this->entryNamed($user, $name)
            ?? throw new RecurringException('Запись «'.$name.'» не найдена.');

        $entry->delete();
    }

    /**
     * Find the user's entry with the given (case-insensitive) name.
     */
    private function entryNamed(User $user, string $name): ?RecurringTransaction
    {
        $name = mb_strtolower(trim($name));

        $entry = null;

        foreach (RecurringTransaction::where('user_id', $user->id)->get() as $candidate) {
            if (! $candidate instanceof RecurringTransaction) {
                continue;
            }

            if (mb_strtolower($candidate->name) === $name) {
                $entry = $candidate;

                break;
            }
        }

        return $entry;
    }

    /**
     * The entries of the user that should run on the given date.
     *
     * An entry with a day beyond the length of the month (e.g. day 31 in
     * February) is skipped for that month. An entry that has already been
     * run on or after the given date is not due again.
     *
     * @return Collection<int, RecurringTransaction>
     */
    public function dueOn(User $user, Carbon $date): Collection
    {
        $entries = $user->recurringTransactions()->with('category')->get();

        $due = $entries->filter(
            static fn (RecurringTransaction $entry) => $entry->day <= $date->daysInMonth
                && $entry->day === $date->day
                && ($entry->last_run_date === null || $entry->last_run_date->lt($date->startOfDay())),
        );

        return new Collection($due->all());
    }

    /**
     * Record the transaction of a due entry and mark it as run.
     */
    public function run(RecurringTransaction $entry, Carbon $date): Transaction
    {
        $user = $entry->user;

        $transaction = new Transaction([
            'user_id' => $user->id,
            'category_id' => $entry->category_id,
            'amount' => (float) $entry->amount,
            'type' => $entry->type,
            'comment' => $entry->name,
            'created_at' => $date->copy(),
        ]);

        $transaction->save();

        $entry->forceFill(['last_run_date' => $date->copy()->startOfDay()])->save();

        return $transaction;
    }

    private function resolveCategory(User $user, string $name): ?Category
    {
        $name = mb_strtolower(trim($name));

        $byName = null;

        foreach (Category::where('user_id', $user->id)->get() as $category) {
            if (! $category instanceof Category) {
                continue;
            }

            if (mb_strtolower($category->name) === $name) {
                $byName = $category;

                break;
            }
        }

        if ($byName !== null) {
            return $byName;
        }

        return $this->categories->match($name, $user->categories()->get());
    }
}
