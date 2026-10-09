<?php

namespace App\Services\Recurring;

use App\Currency;
use App\Models\RecurringTransaction;
use App\Support\Markdown;
use App\TransactionType;
use Illuminate\Database\Eloquent\Collection;

final class RecurringFormatter
{
    /**
     * Format the user's recurring entries as a Telegram Markdown list.
     *
     * @param  Collection<int, RecurringTransaction>  $entries
     */
    public static function list(Collection $entries, Currency $currency): string
    {
        if ($entries->isEmpty()) {
            return implode("\n", [
                '🔁 *Регулярные записи:*',
                '',
                'Пока нет — нажми «➕ Добавить» или отправь: /recurring add Подписка 500 1',
            ]);
        }

        $lines = ['🔁 *Регулярные записи:*', ''];

        foreach ($entries as $entry) {
            $direction = $entry->type === TransactionType::Income ? 'доход' : 'расход';

            $lines[] = sprintf('%s — %s %s, %d-е число (%s)%s',
                Markdown::escape($entry->name),
                number_format((float) $entry->amount, 2, '.', ''),
                $currency->value,
                $entry->day,
                $direction,
                $entry->category !== null ? ', '.Markdown::escape($entry->category->name) : '',
            );
        }

        return implode("\n", $lines);
    }
}
