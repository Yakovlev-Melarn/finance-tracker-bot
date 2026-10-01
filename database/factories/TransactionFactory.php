<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use App\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'amount' => fake()->randomFloat(2, 50, 5000),
            'type' => fake()->randomElement(TransactionType::cases()),
            'comment' => fake()->optional(0.3)->sentence(3),
            'created_at' => fake()->dateTimeBetween(),
        ];
    }
}
