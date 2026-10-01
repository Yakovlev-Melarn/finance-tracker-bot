<?php

namespace Tests\Unit\Services\Parser;

use App\Models\Category;
use App\Services\Parser\CategoryMatcher;
use App\TransactionType;
use PHPUnit\Framework\TestCase;

class CategoryMatcherTest extends TestCase
{
    private CategoryMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new CategoryMatcher;
    }

    public function test_matches_category_when_text_contains_its_keyword(): void
    {
        $categories = [$this->makeCategory('Кофе', ['кофе', 'латте'])];

        $matched = $this->matcher->match('кофе', $categories);

        $this->assertSame('Кофе', $matched->name);
    }

    public function test_matches_when_word_starts_with_keyword(): void
    {
        $categories = [$this->makeCategory('Кофе', ['кофе'])];

        $matched = $this->matcher->match('кофейня 100', $categories);

        $this->assertSame('Кофе', $matched->name);
    }

    public function test_returns_null_when_no_keyword_matches(): void
    {
        $categories = [$this->makeCategory('Кофе', ['кофе', 'латте'])];

        $matched = $this->matcher->match('обувь', $categories);

        $this->assertNull($matched);
    }

    public function test_matches_case_insensitively(): void
    {
        $categories = [$this->makeCategory('Кофе', ['кофе'])];

        $matched = $this->matcher->match('КОФЕ', $categories);

        $this->assertSame('Кофе', $matched->name);
    }

    public function test_prefers_category_with_longest_matched_keyword(): void
    {
        $categories = [
            $this->makeCategory('Кофе', ['кофе']),
            $this->makeCategory('Кофейня', ['кофейня']),
        ];

        $matched = $this->matcher->match('кофейня 100', $categories);

        $this->assertSame('Кофейня', $matched->name);
    }

    public function test_returns_first_category_on_keyword_tie(): void
    {
        $categories = [
            $this->makeCategory('Кофе', ['кофе']),
            $this->makeCategory('Кофейня', ['кофе']),
        ];

        $matched = $this->matcher->match('кофе 100', $categories);

        $this->assertSame('Кофе', $matched->name);
    }

    public function test_ignores_keywords_shorter_than_three_characters(): void
    {
        $categories = [$this->makeCategory('А', ['а'])];

        $matched = $this->matcher->match('а', $categories);

        $this->assertNull($matched);
    }

    public function test_matches_keyword_among_other_words(): void
    {
        $categories = [$this->makeCategory('Кофе', ['кофе'])];

        $matched = $this->matcher->match('купил кофе утром', $categories);

        $this->assertSame('Кофе', $matched->name);
    }

    public function test_returns_null_for_empty_text(): void
    {
        $categories = [$this->makeCategory('Кофе', ['кофе'])];

        $matched = $this->matcher->match('', $categories);

        $this->assertNull($matched);
    }

    public function test_returns_null_for_text_without_letters(): void
    {
        $categories = [$this->makeCategory('Кофе', ['кофе'])];

        $matched = $this->matcher->match('150 200', $categories);

        $this->assertNull($matched);
    }

    public function test_matches_keyword_adjacent_to_number(): void
    {
        $categories = [$this->makeCategory('Кофе', ['кофе'])];

        $matched = $this->matcher->match('кофе150', $categories);

        $this->assertSame('Кофе', $matched->name);
    }

    public function test_matches_across_punctuation(): void
    {
        $categories = [$this->makeCategory('Кофе', ['кофе'])];

        $matched = $this->matcher->match('кофе, латте!', $categories);

        $this->assertSame('Кофе', $matched->name);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function makeCategory(string $name, array $keywords): Category
    {
        return new Category([
            'name' => $name,
            'keywords' => $keywords,
            'type' => TransactionType::Expense,
        ]);
    }
}
