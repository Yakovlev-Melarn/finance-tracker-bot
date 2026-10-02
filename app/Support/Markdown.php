<?php

namespace App\Support;

final class Markdown
{
    /**
     * Escape legacy-Telegram-Markdown special characters in user-provided text.
     */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([*_`\[\]])/', '\\\\$1', $text);
    }
}
