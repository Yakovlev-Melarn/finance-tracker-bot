<?php

namespace App\Services\Reports;

use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Markdown;
use App\TransactionType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class ReportBuilder
{
    private const int TOP_CATEGORIES_LIMIT = 3;

    private const int RECENT_TRANSACTIONS_LIMIT = 20;

    private const string UNCATEGORIZED = 'Без категории';

    /**
     * Aggregate the user's income, expense and top spending categories
     * for the last seven days.
     */
    public function weekStats(User $user): WeekStats
    {
        $from = Carbon::now()->subDays(7);
        $to = Carbon::now();

        $transactions = $user->transactions()
            ->with('category')
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $income = (float) $transactions->where('type', TransactionType::Income)->sum('amount');
        $expense = (float) $transactions->where('type', TransactionType::Expense)->sum('amount');

        $topCategories = $transactions
            ->where('type', TransactionType::Expense)
            ->groupBy(fn (Transaction $transaction): string => $transaction->category?->name ?? self::UNCATEGORIZED)
            ->map(fn (Collection $group): float => round((float) $group->sum('amount'), 2))
            ->sortDesc()
            ->take(self::TOP_CATEGORIES_LIMIT)
            ->map(fn (float $total, string $name): CategorySpend => new CategorySpend($name, $total))
            ->values()
            ->all();

        return new WeekStats(
            from: $from,
            to: $to,
            income: round($income, 2),
            expense: round($expense, 2),
            topCategories: $topCategories,
        );
    }

    /**
     * The user's most recent transactions, newest first.
     *
     * @return Collection<int, Transaction>
     */
    public function recentTransactions(User $user, int $limit = self::RECENT_TRANSACTIONS_LIMIT): Collection
    {
        return $user->transactions()
            ->with('category')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Format the weekly summary as a Telegram Markdown message.
     */
    public function formatWeekStats(WeekStats $stats, Currency $currency): string
    {
        $lines = [
            sprintf('📊 *Итоги за 7 дней* (%s — %s)', $stats->from->format('d.m.Y'), $stats->to->format('d.m.Y')),
            '',
            sprintf('💵 Доходы: *%s %s*', $this->money($stats->income), $currency->value),
            sprintf('💸 Расходы: *%s %s*', $this->money($stats->expense), $currency->value),
            sprintf('⚖️ Баланс: *%s %s*', $this->money($stats->income - $stats->expense), $currency->value),
        ];

        if ($stats->topCategories !== []) {
            $lines[] = '';
            $lines[] = '🏆 Топ категорий (расходы):';

            foreach ($stats->topCategories as $position => $spend) {
                $lines[] = sprintf('%d. %s — %s', $position + 1, Markdown::escape($spend->name), $this->money($spend->total));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format the recent transactions as a Telegram Markdown message.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    public function formatHistory(Collection $transactions, Currency $currency): string
    {
        if ($transactions->isEmpty()) {
            return '📭 Записей пока нет. Отправьте, например: «кофе 150».';
        }

        $lines = ['📜 *Последние записи:*', ''];

        foreach ($transactions as $transaction) {
            $icon = $transaction->type === TransactionType::Income ? '💵' : '💸';
            $category = Markdown::escape($transaction->category?->name ?? self::UNCATEGORIZED);
            $comment = $transaction->comment !== null && $transaction->comment !== ''
                ? ' «'.Markdown::escape($transaction->comment).'»'
                : '';
            $sign = $transaction->type === TransactionType::Income ? '+' : '−';
            $dateTime = $transaction->created_at?->format('d.m H:i') ?? '—';

            $lines[] = sprintf(
                '%s %s %s%s %s%s %s',
                $icon,
                $dateTime,
                $category,
                $comment,
                $sign,
                $this->money($transaction->amount),
                $currency->value,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Format an amount as a fixed-point string without thousands separators.
     */
    private function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
