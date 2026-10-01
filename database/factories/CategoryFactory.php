<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use App\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * The default category presets with their keywords.
     *
     * @var array<string, array{name: string, keywords: list<string>, type: TransactionType}>
     */
    public const array PRESETS = [
        'coffee' => [
            'name' => 'Кофе',
            'keywords' => ['кофе', 'латте', 'капучино', 'эспрессо', 'американо'],
            'type' => TransactionType::Expense,
        ],
        'fast_food' => [
            'name' => 'Фастфуд',
            'keywords' => ['бургер', 'пицца', 'суши', 'шаурма', 'донер'],
            'type' => TransactionType::Expense,
        ],
        'groceries' => [
            'name' => 'Продукты',
            'keywords' => ['продукты', 'супермаркет', 'молоко', 'хлеб', 'сыр'],
            'type' => TransactionType::Expense,
        ],
        'transport' => [
            'name' => 'Транспорт',
            'keywords' => ['такси', 'метро', 'бензин', 'автобус', 'троллейбус'],
            'type' => TransactionType::Expense,
        ],
        'restaurant' => [
            'name' => 'Ресторан',
            'keywords' => ['ресторан', 'кафе', 'ужин', 'банкет'],
            'type' => TransactionType::Expense,
        ],
        'subscriptions' => [
            'name' => 'Подписки',
            'keywords' => ['подписка', 'spotify', 'netflix', 'облако'],
            'type' => TransactionType::Expense,
        ],
        'health' => [
            'name' => 'Здравоохранение',
            'keywords' => ['аптека', 'врач', 'лекарства', 'лечение'],
            'type' => TransactionType::Expense,
        ],
        'entertainment' => [
            'name' => 'Развлечения',
            'keywords' => ['кино', 'концерт', 'игры', 'билеты'],
            'type' => TransactionType::Expense,
        ],
        'salary' => [
            'name' => 'Зарплата',
            'keywords' => ['зарплата', 'аванс', 'заработок'],
            'type' => TransactionType::Income,
        ],
        'freelance' => [
            'name' => 'Фриланс',
            'keywords' => ['фриланс', 'клиент', 'проект', 'подработка'],
            'type' => TransactionType::Income,
        ],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $preset = fake()->randomElement(self::PRESETS);

        return [
            'user_id' => User::factory(),
            'name' => $preset['name'],
            'keywords' => $preset['keywords'],
            'type' => $preset['type'],
        ];
    }
}
