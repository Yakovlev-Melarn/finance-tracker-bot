<?php

namespace App\Services\Parser;

class CategoryMatcher
{
    /**
     * Find the category whose keywords match the given description.
     *
     * @param  iterable<array{name: string, keywords: list<string>, type: string}>  $categories
     * @return array{name: string, keywords: list<string>, type: string}|null
     */
    public function match(string $description, iterable $categories): ?array
    {
        //
    }
}
