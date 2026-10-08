<?php

namespace Database\Factories;

use App\Models\RecurringTransaction;
use App\Models\User;
use App\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringTransaction>
 */
class RecurringTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => null,
            'name' => fake()->unique()->words(2, true),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'type' => TransactionType::Expense,
            'day' => fake()->numberBetween(1, 28),
            'last_run_date' => null,
        ];
    }
}
