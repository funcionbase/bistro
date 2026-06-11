<?php

namespace Database\Factories;

use App\Models\BusinessHour;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHour>
 */
class BusinessHourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_nit' => fn () => Company::factory()->create()->nit,
            'day_of_week' => fake()->numberBetween(0, 6),
            'open_time' => '08:00:00',
            'close_time' => '22:00:00',
            'is_enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['is_enabled' => false]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => ['company_nit' => $company->nit]);
    }

    public function forDay(int $dayOfWeek): static
    {
        return $this->state(fn (array $attributes) => ['day_of_week' => $dayOfWeek]);
    }
}
