<?php

namespace Database\Factories;

use App\Currency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_id' => fake()->unique()->numberBetween(1_000_000_000_000, 9_999_999_999_999_999),
            'name' => fake()->name(),
            'currency' => Currency::RUB,
        ];
    }
}
