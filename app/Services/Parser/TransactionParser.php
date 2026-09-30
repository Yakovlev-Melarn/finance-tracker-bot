<?php

namespace App\Services\Parser;

class TransactionParser
{
    /**
     * Parse a natural-language message like "кофе 150" or "зарплата 50000".
     *
     * @return array{description: string, amount: int|float, comment: string|null}
     */
    public function parse(string $text): array
    {
        //
    }
}
