<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyRole>
 */
class CompanyRoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_nit' => function () {
                return Company::factory()->create()->nit;
            },
            'name' => fake()->unique()->jobTitle(),
            'description' => fake()->sentence(),
            'is_system' => false,
            'color' => null,
        ];
    }
}
