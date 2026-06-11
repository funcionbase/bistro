<?php

namespace Database\Factories;

use App\Models\BusinessHourException;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHourException>
 */
class BusinessHourExceptionFactory extends Factory
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
            'exception_date' => fake()->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'reason' => fake()->sentence(),
            'is_open' => false,
            'open_time' => null,
            'close_time' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_open' => true,
            'open_time' => '10:00:00',
            'close_time' => '18:00:00',
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => ['company_nit' => $company->nit]);
    }

    public function forDate(string $date): static
    {
        return $this->state(fn (array $attributes) => ['exception_date' => $date]);
    }
}
