<?php

namespace App\Services\Categories;

use App\Models\Category;
use App\Models\User;
use App\TransactionType;
use Illuminate\Database\Eloquent\Collection;

final class CategoryManager
{
    /**
     * All categories of the user, sorted by name.
     *
     * @return Collection<int, Category>
     */
    public function all(User $user): Collection
    {
        return $user->categories()->orderBy('name')->get();
    }

    /**
     * Create a category with the given keywords (type is expense by default).
     *
     * @param  list<string>  $keywords
     *
     * @throws CategoryException
     */
    public function create(User $user, string $name, array $keywords): Category
    {
        $name = $this->normalizeName($name);
        $keywords = $this->normalizeKeywords($keywords);

        $this->assertNameIsFree($user->categories()->get(), $name);
        $this->assertKeywordsAreFree($user->categories()->get(), $keywords);

        $category = new Category([
            'user_id' => $user->id,
            'name' => $name,
            'keywords' => $keywords,
            'type' => TransactionType::Expense,
        ]);

        $category->save();

        return $category;
    }

    /**
     * Rename a category and, when new keywords are given, replace its keywords.
     *
     * @param  list<string>  $keywords  empty list keeps the current keywords
     *
     * @throws CategoryException
     */
    public function rename(User $user, string $oldName, string $newName, array $keywords = []): Category
    {
        $categories = $user->categories()->get();
        $category = $this->findByName($categories, $oldName);
        $newName = $this->normalizeName($newName);
        $keywords = $keywords === [] ? $category->keywords : $this->normalizeKeywords($keywords);

        if (! self::sameName($newName, $category->name)) {
            $this->assertNameIsFree($categories, $newName, $category->id);
        }

        $this->assertKeywordsAreFree($categories, $keywords, $category->id);

        $category->update([
            'name' => $newName,
            'keywords' => $keywords,
        ]);

        return $category;
    }

    /**
     * Delete a category; its transactions become uncategorized.
     *
     * Returns the number of affected transactions.
     *
     * @throws CategoryException
     */
    public function delete(User $user, string $name): int
    {
        $category = $this->findByName($user->categories()->get(), $name);

        $affected = $category->transactions()->update(['category_id' => null]);
        $category->delete();

        return $affected;
    }

    /**
     * @param  Collection<int, Category>  $categories
     *
     * @throws CategoryException
     */
    private function findByName(Collection $categories, string $name): Category
    {
        return $categories->first(
            static fn (Category $category): bool => self::sameName($category->name, $name),
        ) ?? throw new CategoryException('Категория «'.$name.'» не найдена.');
    }

    /**
     * @throws CategoryException
     */
    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new CategoryException('Укажите название категории.');
        }

        return $name;
    }

    /**
     * Trim, lowercase, drop empties and de-duplicate keywords case-insensitively.
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function normalizeKeywords(array $keywords): array
    {
        $normalized = array_map('mb_strtolower', array_map('trim', $keywords));

        return array_values(array_filter(array_unique($normalized), static fn (string $keyword): bool => $keyword !== ''));
    }

    /**
     * @param  Collection<int, Category>  $categories
     *
     * @throws CategoryException
     */
    private function assertNameIsFree(Collection $categories, string $name, ?int $excludingId = null): void
    {
        foreach ($categories as $existing) {
            if ($excludingId !== null && $existing->id === $excludingId) {
                continue;
            }

            if (self::sameName($existing->name, $name)) {
                throw new CategoryException('Категория «'.$existing->name.'» уже существует.');
            }
        }
    }

    /**
     * Case-insensitive, multibyte-safe name comparison.
     */
    private static function sameName(string $a, string $b): bool
    {
        return mb_strtolower($a) === mb_strtolower($b);
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  list<string>  $keywords
     *
     * @throws CategoryException
     */
    private function assertKeywordsAreFree(Collection $categories, array $keywords, ?int $excludingId = null): void
    {
        if ($keywords === []) {
            return;
        }

        foreach ($categories as $existing) {
            if ($excludingId !== null && $existing->id === $excludingId) {
                continue;
            }

            foreach ($keywords as $keyword) {
                if (in_array($keyword, $existing->keywords, true)) {
                    throw new CategoryException('Ключевое слово «'.$keyword.'» уже используется в категории «'.$existing->name.'».');
                }
            }
        }
    }
}
