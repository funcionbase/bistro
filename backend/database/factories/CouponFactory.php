<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_nit' => fn () => Company::factory()->create()->nit,
            'code' => strtoupper(fake()->unique()->lexify('???????????')),
            'type' => fake()->randomElement(['percentage', 'fixed_amount']),
            'value' => fake()->randomFloat(2, 5, 50),
            'valid_from' => null,
            'valid_until' => fake()->optional()->dateTimeBetween('now', '+6 months'),
            'max_uses' => fake()->optional()->numberBetween(10, 500),
            'uses_count' => 0,
            'min_order_amount' => fake()->randomFloat(2, 0, 50000),
            'first_order_only' => false,
            'is_active' => true,
            'status' => 'active',
            'created_by' => null,
        ];
    }

    public function percentage(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'percentage',
            'value' => fake()->randomFloat(2, 5, 30),
        ]);
    }

    public function fixedAmount(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'fixed_amount',
            'value' => fake()->randomFloat(2, 5000, 50000),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_until' => fake()->dateTimeBetween('-6 months', '-1 day'),
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'max_uses' => 10,
            'uses_count' => 10,
            'status' => 'exhausted',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'status' => 'inactive',
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => ['company_nit' => $company->nit]);
    }
}
