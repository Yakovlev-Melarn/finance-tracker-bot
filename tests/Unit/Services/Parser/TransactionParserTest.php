<?php

namespace Tests\Unit\Services\Parser;

use App\Models\Category;
use App\Services\Parser\CategoryMatcher;
use App\Services\Parser\TransactionParseException;
use App\Services\Parser\TransactionParser;
use App\TransactionType;
use PHPUnit\Framework\TestCase;

class TransactionParserTest extends TestCase
{
    private TransactionParser $parser;

    /**
     * @var list<Category>
     */
    private array $categories;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new TransactionParser(new CategoryMatcher);
        $this->categories = [
            $this->makeCategory('Кофе', ['кофе', 'латте', 'капучино'], TransactionType::Expense),
            $this->makeCategory('Зарплата', ['зарплата', 'аванс'], TransactionType::Income),
        ];
    }

    /**
     * @throws TransactionParseException
     */
    public function test_parses_amount_and_comment_from_simple_message(): void
    {
        $parsed = $this->parser->parse('кофе 150', $this->categories);

        $this->assertSame(150.0, $parsed->amount);
        $this->assertSame('кофе', $parsed->comment);
        $this->assertSame(TransactionType::Expense, $parsed->type);
        $this->assertSame('Кофе', $parsed->category?->name);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_parses_income_message_with_salary_keyword(): void
    {
        $parsed = $this->parser->parse('зарплата 50000', $this->categories);

        $this->assertSame(50000.0, $parsed->amount);
        $this->assertSame('зарплата', $parsed->comment);
        $this->assertSame(TransactionType::Income, $parsed->type);
        $this->assertSame('Зарплата', $parsed->category?->name);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_keeps_trailing_text_as_comment(): void
    {
        $parsed = $this->parser->parse('кофе 150 работа', $this->categories);

        $this->assertSame(150.0, $parsed->amount);
        $this->assertSame('кофе работа', $parsed->comment);
        $this->assertSame(TransactionType::Expense, $parsed->type);
        $this->assertSame('Кофе', $parsed->category?->name);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_parses_decimal_amount_with_dot(): void
    {
        $parsed = $this->parser->parse('обед 150.50', $this->categories);

        $this->assertSame(150.5, $parsed->amount);
        $this->assertSame('обед', $parsed->comment);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_parses_decimal_amount_with_comma(): void
    {
        $parsed = $this->parser->parse('обед 150,50', $this->categories);

        $this->assertSame(150.5, $parsed->amount);
        $this->assertSame('обед', $parsed->comment);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_rounds_amount_to_two_decimals(): void
    {
        $parsed = $this->parser->parse('кофе 150.123', $this->categories);

        $this->assertSame(150.12, $parsed->amount);
    }

    public function test_throws_exception_when_message_has_no_amount(): void
    {
        $this->expectException(TransactionParseException::class);

        $this->parser->parse('кофе', $this->categories);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_parses_message_with_only_amount(): void
    {
        $parsed = $this->parser->parse('150', $this->categories);

        $this->assertSame(150.0, $parsed->amount);
        $this->assertSame('', $parsed->comment);
        $this->assertSame(TransactionType::Expense, $parsed->type);
        $this->assertNull($parsed->category);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_ignores_extra_whitespace(): void
    {
        $parsed = $this->parser->parse('  кофе   150  ', $this->categories);

        $this->assertSame(150.0, $parsed->amount);
        $this->assertSame('кофе', $parsed->comment);
        $this->assertSame('Кофе', $parsed->category?->name);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_defaults_to_expense_when_no_category_matches(): void
    {
        $parsed = $this->parser->parse('обувь 500', $this->categories);

        $this->assertSame(500.0, $parsed->amount);
        $this->assertSame('обувь', $parsed->comment);
        $this->assertSame(TransactionType::Expense, $parsed->type);
        $this->assertNull($parsed->category);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_takes_first_number_as_amount(): void
    {
        $parsed = $this->parser->parse('такси 150 и 300', $this->categories);

        $this->assertSame(150.0, $parsed->amount);
        $this->assertSame('такси и 300', $parsed->comment);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_parses_uppercase_message(): void
    {
        $parsed = $this->parser->parse('КОФЕ 150', $this->categories);

        $this->assertSame(150.0, $parsed->amount);
        $this->assertSame('КОФЕ', $parsed->comment);
        $this->assertSame('Кофе', $parsed->category?->name);
    }

    /**
     * @throws TransactionParseException
     */
    public function test_parses_long_comment_containing_keyword(): void
    {
        $parsed = $this->parser->parse('покупал кофе и сигареты 250', $this->categories);

        $this->assertSame(250.0, $parsed->amount);
        $this->assertSame('покупал кофе и сигареты', $parsed->comment);
        $this->assertSame(TransactionType::Expense, $parsed->type);
        $this->assertSame('Кофе', $parsed->category?->name);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function makeCategory(string $name, array $keywords, TransactionType $type): Category
    {
        return new Category([
            'name' => $name,
            'keywords' => $keywords,
            'type' => $type,
        ]);
    }
}
