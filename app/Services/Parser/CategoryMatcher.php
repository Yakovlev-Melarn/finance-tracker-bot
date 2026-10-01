<?php

namespace App\Services\Parser;

use App\Models\Category;

class CategoryMatcher
{
    private const int MIN_KEYWORD_LENGTH = 3;

    /**
     * Find the category whose keywords match the given text.
     *
     * A category matches when a word of the text starts with one of its
     * keywords (case-insensitive). The category with the longest matched
     * keyword wins; ties resolve to the first category in the given order.
     *
     * @param  iterable<Category>  $categories
     */
    public function match(string $text, iterable $categories): ?Category
    {
        $words = $this->extractWords($text);

        $matched = null;
        $matchedKeywordLength = 0;

        foreach ($categories as $category) {
            foreach ($category->keywords as $keyword) {
                $keyword = mb_strtolower(trim((string) $keyword));

                if (mb_strlen($keyword) < self::MIN_KEYWORD_LENGTH) {
                    continue;
                }

                foreach ($words as $word) {
                    if (str_starts_with($word, $keyword) && mb_strlen($keyword) > $matchedKeywordLength) {
                        $matched = $category;
                        $matchedKeywordLength = mb_strlen($keyword);
                    }
                }
            }
        }

        return $matched;
    }

    /**
     * @return list<string>
     */
    private function extractWords(string $text): array
    {
        preg_match_all('/[a-z0-9а-яё]+/u', mb_strtolower($text), $matches);

        return $matches[0];
    }
}
