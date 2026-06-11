<?php

namespace Database\Factories;

use App\Models\CartSession;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartSession>
 */
class CartSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jwt_jti' => fake()->uuid(),
            'company_nit' => fn () => Company::factory()->create()->nit,
            'client_phone' => fake()->numerify('57##########'),
            'status' => 'active',
            'expired_at' => now()->addHour(),
        ];
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'abandoned',
            'expired_at' => now()->subHour(),
        ]);
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'converted']);
    }
}
