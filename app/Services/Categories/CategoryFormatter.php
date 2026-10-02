<?php

namespace App\Services\Categories;

use App\Models\Category;
use App\Support\Markdown;
use App\TransactionType;
use Illuminate\Database\Eloquent\Collection;

final class CategoryFormatter
{
    /**
     * Format the user's categories as a Telegram Markdown list.
     *
     * @param  Collection<int, Category>  $categories
     */
    public static function list(Collection $categories): string
    {
        if ($categories->isEmpty()) {
            return implode("\n", [
                '🗂 *Мои категории:*',
                '',
                'Пока пусто — нажми «➕ Добавить» или отправь: /categories add Кофе, кофе, латте',
            ]);
        }

        $lines = ['🗂 *Мои категории:*', ''];

        foreach ($categories as $category) {
            $icon = $category->type === TransactionType::Income ? '💵' : '💸';
            $keywords = $category->keywords === []
                ? 'без ключевых слов'
                : implode(', ', array_map(Markdown::escape(...), $category->keywords));

            $lines[] = sprintf('%s %s: %s', $icon, Markdown::escape($category->name), $keywords);
        }

        return implode("\n", $lines);
    }
}
