<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\User;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 123456789,
            'name' => 'Test User',
        ]);

        foreach (CategoryFactory::PRESETS as $preset) {
            $user->categories()->create([
                'name' => $preset['name'],
                'keywords' => $preset['keywords'],
                'type' => $preset['type'],
            ]);
        }

        $categories = $user->categories()->get();

        foreach ($categories as $category) {
            Transaction::factory()
                ->for($user)
                ->for($category, 'category')
                ->state(fn () => ['type' => $category->type])
                ->count(5)
                ->create();
        }
    }
}
