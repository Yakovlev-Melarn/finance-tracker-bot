<?php

namespace App\Services\Parser;

use App\Models\Category;
use App\TransactionType;

readonly class TransactionParser
{
    public function __construct(
        private CategoryMatcher $matcher,
    ) {}

    /**
     * Parse a natural-language message like "кофе 150" or "зарплата 50000".
     *
     * The first number in the message is the amount. The remaining text is
     * the comment, which is matched against the category keywords. The
     * transaction type is taken from the matched category and defaults to
     * expense when no category matches.
     *
     * @param  iterable<Category>  $categories
     *
     * @throws TransactionParseException
     */
    public function parse(string $text, iterable $categories): ParsedTransaction
    {
        $amountMatch = $this->extractAmount($text);

        $amount = round((float) str_replace(',', '.', $amountMatch), 2);
        $comment = $this->extractComment($text, $amountMatch);
        $category = $this->matcher->match($comment, $categories);
        $type = $category?->type ?? TransactionType::Expense;

        return new ParsedTransaction(
            amount: $amount,
            comment: $comment,
            type: $type,
            category: $category,
        );
    }

    /**
     * @throws TransactionParseException
     */
    private function extractAmount(string $text): string
    {
        if (preg_match('/\d+(?:[.,]\d+)?/', $text, $matches) !== 1) {
            throw new TransactionParseException('No amount found in the message.');
        }

        return $matches[0];
    }

    private function extractComment(string $text, string $amountMatch): string
    {
        $comment = (string) preg_replace('/'.preg_quote($amountMatch, '/').'/u', '', $text, 1);

        return trim((string) preg_replace('/\s+/', ' ', $comment));
    }
}
