<?php

namespace Database\Factories;

use App\Models\Bank;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nit' => fake()->unique()->numerify('#########-#'),
            'commercial_name' => fake()->company(),
            'legal_name' => fake()->company().' S.A.S.',
            'bank_id' => Bank::factory(),
            'account_number' => fake()->numerify('##########'),
            'account_type' => fake()->randomElement(['corriente', 'ahorros']),
            'qr_code_path' => null,
            'breb_key' => null,
            'status' => 'pending_activation',
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }
}
