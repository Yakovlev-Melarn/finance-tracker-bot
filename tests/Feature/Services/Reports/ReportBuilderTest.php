<?php

namespace Tests\Feature\Services\Reports;

use App\Currency;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\ReportBuilder;
use App\TransactionType;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_week_stats_sums_income_and_expense_within_seven_days(): void
    {
        $salary = $this->category('Зарплата', TransactionType::Income);
        $coffee = $this->category('Кофе', TransactionType::Expense);
        $groceries = $this->category('Продукты', TransactionType::Expense);

        $this->transaction($salary, 10000.0, now()->subDays(), type: TransactionType::Income);
        $this->transaction(null, 2500.0, now()->subDays(2), type: TransactionType::Income);
        $this->transaction($coffee, 150.0, now()->subDays());
        $this->transaction($coffee, 200.5, now()->subDays(3));
        $this->transaction($groceries, 1000.0, now()->subDays(0));

        $this->transaction($salary, 999.0, now()->subDays(10), type: TransactionType::Income);
        $this->transaction($coffee, 777.0, now()->subDays(), user: User::factory()->create());

        $stats = (new ReportBuilder)->weekStats($this->user);

        $this->assertSame(12500.0, $stats->income);
        $this->assertSame(1350.5, $stats->expense);
        $this->assertCount(2, $stats->topCategories);
        $this->assertSame('Продукты', $stats->topCategories[0]->name);
        $this->assertSame(1000.0, $stats->topCategories[0]->total);
        $this->assertSame('Кофе', $stats->topCategories[1]->name);
        $this->assertSame(350.5, $stats->topCategories[1]->total);
    }

    public function test_week_stats_top_categories_limited_to_three(): void
    {
        $names = ['А', 'Б', 'В', 'Г', 'Д'];

        foreach ($names as $position => $name) {
            $category = $this->category($name, TransactionType::Expense);
            $this->transaction($category, ($position + 1) * 10.0, now()->subDay());
        }

        $stats = (new ReportBuilder)->weekStats($this->user);

        $this->assertCount(3, $stats->topCategories);
        $this->assertSame('Д', $stats->topCategories[0]->name);
        $this->assertSame(50.0, $stats->topCategories[0]->total);
        $this->assertSame('Г', $stats->topCategories[1]->name);
        $this->assertSame(40.0, $stats->topCategories[1]->total);
        $this->assertSame('В', $stats->topCategories[2]->name);
        $this->assertSame(30.0, $stats->topCategories[2]->total);
    }

    public function test_week_stats_groups_uncategorized_expenses_together(): void
    {
        $this->transaction(null, 100.0, now()->subDay());
        $this->transaction(null, 50.25, now()->subDays(2));

        $stats = (new ReportBuilder)->weekStats($this->user);

        $this->assertCount(1, $stats->topCategories);
        $this->assertSame('Без категории', $stats->topCategories[0]->name);
        $this->assertSame(150.25, $stats->topCategories[0]->total);
    }

    public function test_week_stats_is_empty_without_transactions(): void
    {
        $stats = (new ReportBuilder)->weekStats($this->user);

        $this->assertSame(0.0, $stats->income);
        $this->assertSame(0.0, $stats->expense);
        $this->assertSame([], $stats->topCategories);
    }

    public function test_recent_transactions_returns_newest_first_up_to_limit(): void
    {
        foreach (range(1, 25) as $n) {
            $this->transaction(null, (float) $n, now()->subSeconds($n * 60));
        }

        $recent = (new ReportBuilder)->recentTransactions($this->user);

        $this->assertCount(20, $recent);
        $this->assertSame(1.0, (float) $recent->first()->amount);
        $this->assertSame(20.0, (float) $recent->last()->amount);
    }

    public function test_format_week_stats_renders_totals_and_top_categories(): void
    {
        $coffee = $this->category('Кофе', TransactionType::Expense);
        $groceries = $this->category('Продукты', TransactionType::Expense);

        $this->transaction(null, 12500.0, now()->subDay(), type: TransactionType::Income);
        $this->transaction($coffee, 350.5, now()->subDay());
        $this->transaction($groceries, 1000.0, now()->subDay());

        $stats = (new ReportBuilder)->weekStats($this->user);
        $text = (new ReportBuilder)->formatWeekStats($stats, Currency::RUB);

        $this->assertStringContainsString('📊 *Итоги за 7 дней*', $text);
        $this->assertStringContainsString('💵 Доходы: *12500.00 RUB*', $text);
        $this->assertStringContainsString('💸 Расходы: *1350.50 RUB*', $text);
        $this->assertStringContainsString('⚖️ Баланс: *11149.50 RUB*', $text);
        $this->assertStringContainsString('🏆 Топ категорий (расходы):', $text);
        $this->assertStringContainsString('1. Продукты — 1000.00', $text);
        $this->assertStringContainsString('2. Кофе — 350.50', $text);
    }

    public function test_format_week_stats_omits_top_section_without_expenses(): void
    {
        $this->transaction(null, 100.0, now()->subDay(), type: TransactionType::Income);

        $stats = (new ReportBuilder)->weekStats($this->user);
        $text = (new ReportBuilder)->formatWeekStats($stats, Currency::RUB);

        $this->assertStringNotContainsString('Топ категорий', $text);
    }

    public function test_format_history_renders_lines_with_icons_and_signs(): void
    {
        $coffee = $this->category('Кофе', TransactionType::Expense);

        $this->transaction($coffee, 150.0, now()->subDay(), comment: 'кофе');
        $this->transaction(null, 50000.0, now()->subDays(2), type: TransactionType::Income, comment: 'зарплата');

        $text = (new ReportBuilder)->formatHistory(
            (new ReportBuilder)->recentTransactions($this->user),
            Currency::RUB,
        );

        $this->assertStringContainsString('📜 *Последние записи:*', $text);
        $this->assertStringContainsString('«кофе» −150.00 RUB', $text);
        $this->assertStringContainsString('«зарплата» +50000.00 RUB', $text);
        $this->assertStringContainsString('💸', $text);
        $this->assertStringContainsString('💵', $text);
        $this->assertStringContainsString('Без категории', $text);
    }

    public function test_format_history_escapes_markdown_special_characters(): void
    {
        $this->transaction(null, 10.0, now()->subDay(), comment: 'a_b *c* `d` [e]');

        $text = (new ReportBuilder)->formatHistory(
            (new ReportBuilder)->recentTransactions($this->user),
            Currency::RUB,
        );

        $this->assertStringContainsString('a\_b \*c\* \`d\` \[e\]', $text);
    }

    public function test_format_history_shows_hint_when_empty(): void
    {
        $text = (new ReportBuilder)->formatHistory($this->user->transactions()->get(), Currency::RUB);

        $this->assertSame('📭 Записей пока нет. Отправьте, например: «кофе 150».', $text);
    }

    private function category(string $name, TransactionType $type): Category
    {
        return Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'keywords' => [$name],
            'type' => $type,
        ]);
    }

    private function transaction(
        ?Category $category,
        float $amount,
        DateTimeInterface $createdAt,
        ?User $user = null,
        TransactionType $type = TransactionType::Expense,
        ?string $comment = null,
    ): Transaction {
        return Transaction::factory()->create([
            'user_id' => $user?->id ?? $this->user->id,
            'category_id' => $category?->id,
            'amount' => $amount,
            'type' => $type,
            'comment' => $comment,
            'created_at' => $createdAt,
        ]);
    }
}
