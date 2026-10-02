<?php

namespace Tests\Feature\Services\Categories;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Categories\CategoryException;
use App\Services\Categories\CategoryManager;
use App\TransactionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @throws CategoryException
     */
    public function test_create_stores_category_with_keywords_and_expense_type(): void
    {
        $category = (new CategoryManager)->create($this->user, 'Кофе', ['кофе', 'латте']);

        $this->assertSame('Кофе', $category->name);
        $this->assertSame(['кофе', 'латте'], $category->keywords);
        $this->assertSame(TransactionType::Expense, $category->type);
        $this->assertSame($this->user->id, $category->user_id);
        $this->assertDatabaseHas('categories', [
            'user_id' => $this->user->id,
            'name' => 'Кофе',
        ]);
    }

    /**
     * @throws CategoryException
     */
    public function test_create_normalizes_keywords(): void
    {
        $category = (new CategoryManager)->create($this->user, 'Кофе', ['  Кофе ', 'кофе', ' латте', '']);

        $this->assertSame(['кофе', 'латте'], $category->keywords);
    }

    /**
     * @throws CategoryException
     */
    public function test_create_without_keywords(): void
    {
        $category = (new CategoryManager)->create($this->user, 'Другое', []);

        $this->assertSame([], $category->keywords);
    }

    /**
     * @throws CategoryException
     */
    public function test_create_rejects_duplicate_name_case_insensitively(): void
    {
        (new CategoryManager)->create($this->user, 'Кофе', ['кофе']);

        $this->expectException(CategoryException::class);
        $this->expectExceptionMessage('уже существует');

        (new CategoryManager)->create($this->user, 'кофе', []);
    }

    /**
     * @throws CategoryException
     */
    public function test_create_rejects_keyword_used_by_another_category(): void
    {
        (new CategoryManager)->create($this->user, 'Кофе', ['кофе', 'латте']);

        $this->expectException(CategoryException::class);
        $this->expectExceptionMessage('кофе');

        (new CategoryManager)->create($this->user, 'Чай', ['кофе', 'чай']);
    }

    public function test_create_requires_name(): void
    {
        $this->expectException(CategoryException::class);
        $this->expectExceptionMessage('название');

        (new CategoryManager)->create($this->user, '   ', ['кофе']);
    }

    /**
     * @throws CategoryException
     */
    public function test_create_is_scoped_to_the_user(): void
    {
        $other = User::factory()->create();
        (new CategoryManager)->create($other, 'Кофе', ['кофе']);

        $category = (new CategoryManager)->create($this->user, 'Кофе', ['кофе']);

        $this->assertNotNull($category->id);
    }

    /**
     * @throws CategoryException
     */
    public function test_rename_changes_name_and_keywords(): void
    {
        (new CategoryManager)->create($this->user, 'Кофе', ['кофе']);

        $category = (new CategoryManager)->rename($this->user, 'Кофе', 'Капучино', ['эспрессо']);

        $this->assertSame('Капучино', $category->name);
        $this->assertSame(['эспрессо'], $category->keywords);
        $this->assertDatabaseCount('categories', 1);
    }

    /**
     * @throws CategoryException
     */
    public function test_rename_keeps_keywords_when_none_given(): void
    {
        (new CategoryManager)->create($this->user, 'Кофе', ['кофе', 'латте']);

        $category = (new CategoryManager)->rename($this->user, 'Кофе', 'Капучино');

        $this->assertSame('Капучино', $category->name);
        $this->assertSame(['кофе', 'латте'], $category->keywords);
    }

    /**
     * @throws CategoryException
     */
    public function test_rename_rejects_name_taken_by_another_category(): void
    {
        (new CategoryManager)->create($this->user, 'Кофе', ['кофе']);
        (new CategoryManager)->create($this->user, 'Чай', ['чай']);

        $this->expectException(CategoryException::class);
        $this->expectExceptionMessage('уже существует');

        (new CategoryManager)->rename($this->user, 'Чай', 'Кофе');
    }

    /**
     * @throws CategoryException
     */
    public function test_rename_rejects_keyword_used_by_another_category(): void
    {
        (new CategoryManager)->create($this->user, 'Кофе', ['кофе']);
        (new CategoryManager)->create($this->user, 'Чай', ['чай']);

        $this->expectException(CategoryException::class);
        $this->expectExceptionMessage('кофе');

        (new CategoryManager)->rename($this->user, 'Чай', 'Чайный стол', ['кофе']);
    }

    /**
     * @throws CategoryException
     */
    public function test_rename_allows_keeping_own_keyword(): void
    {
        (new CategoryManager)->create($this->user, 'Кофе', ['кофе', 'латте']);

        $category = (new CategoryManager)->rename($this->user, 'Кофе', 'Кофе', ['кофе']);

        $this->assertSame(['кофе'], $category->keywords);
    }

    public function test_rename_fails_for_unknown_category(): void
    {
        $this->expectException(CategoryException::class);
        $this->expectExceptionMessage('не найдена');

        (new CategoryManager)->rename($this->user, 'Нет', 'Новое');
    }

    /**
     * @throws CategoryException
     */
    public function test_delete_removes_category_and_uncategorizes_transactions(): void
    {
        $category = (new CategoryManager)->create($this->user, 'Кофе', ['кофе']);
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 150,
            'type' => TransactionType::Expense,
        ]);
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'amount' => 100,
            'type' => TransactionType::Expense,
        ]);

        $affected = (new CategoryManager)->delete($this->user, 'Кофе');

        $this->assertSame(2, $affected);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseHas('transactions', ['category_id' => null]);
    }

    public function test_delete_fails_for_unknown_category(): void
    {
        $this->expectException(CategoryException::class);
        $this->expectExceptionMessage('не найдена');

        (new CategoryManager)->delete($this->user, 'Нет');
    }

    /**
     * @throws CategoryException
     */
    public function test_all_returns_categories_sorted_by_name(): void
    {
        (new CategoryManager)->create($this->user, 'Продукты', ['продукты']);
        (new CategoryManager)->create($this->user, 'Кофе', ['кофе']);

        $names = (new CategoryManager)->all($this->user)->pluck('name')->all();

        $this->assertSame(['Кофе', 'Продукты'], $names);
    }
}
