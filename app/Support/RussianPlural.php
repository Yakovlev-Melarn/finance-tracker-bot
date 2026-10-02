<?php

namespace App\Support;

final class RussianPlural
{
    /**
     * Pick the correct Russian noun form for the given count.
     */
    public static function of(int $count, string $one, string $few, string $many): string
    {
        $mod10 = abs($count) % 10;
        $mod100 = abs($count) % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }

        return $many;
    }
}
