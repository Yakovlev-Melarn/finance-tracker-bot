<?php

namespace App\Services\Bot;

final class Menu
{
    /**
     * The main menu shown after /start and when navigating back to the root.
     *
     * @return array<int, array<int, array{text: string, callback: string}>>
     */
    public static function main(): array
    {
        return [
            [['text' => '📝 Добавить запись', 'callback' => 'record']],
            [['text' => '📊 Отчёт за неделю', 'callback' => 'stats']],
            [['text' => '📜 История', 'callback' => 'history']],
            [['text' => '🗂 Мои категории', 'callback' => 'cats']],
            [['text' => '🔁 Регулярные', 'callback' => 'recurring']],
        ];
    }

    /**
     * The recurring entries management submenu.
     *
     * @return array<int, array<int, array{text: string, callback: string}>>
     */
    public static function recurring(): array
    {
        return [
            [
                ['text' => '➕ Добавить', 'callback' => 'rec_add'],
                ['text' => '🗑 Удалить', 'callback' => 'rec_delete'],
            ],
            [
                ['text' => '🏠 В меню', 'callback' => 'menu'],
            ],
        ];
    }

    /**
     * The category management submenu.
     *
     * @return array<int, array<int, array{text: string, callback: string}>>
     */
    public static function categories(): array
    {
        return [
            [
                ['text' => '➕ Добавить', 'callback' => 'cats_add'],
                ['text' => '✏️ Переименовать', 'callback' => 'cats_rename'],
            ],
            [
                ['text' => '🗑 Удалить', 'callback' => 'cats_delete'],
                ['text' => '🏠 В меню', 'callback' => 'menu'],
            ],
        ];
    }

    /**
     * The caption shown on the menu photo.
     */
    public static function welcome(string $name): string
    {
        return sprintf(
            "Привет, %s! 👋\n\nЯ веду учёт твоих финансов.\nОтправляй записи вида «кофе 150» — или нажимай кнопки ниже.",
            $name,
        );
    }
}
