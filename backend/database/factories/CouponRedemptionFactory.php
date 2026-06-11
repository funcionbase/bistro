<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CouponRedemption>
 */
class CouponRedemptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_nit' => fn () => Company::factory()->create()->nit,
            'coupon_id' => fn (array $attributes) => Coupon::factory()
                ->forCompany(Company::query()->where('nit', $attributes['company_nit'])->firstOrFail())
                ->create()
                ->id,
            'order_id' => fn (array $attributes) => Order::factory()
                ->forCompany(Company::query()->where('nit', $attributes['company_nit'])->firstOrFail())
                ->create()
                ->id,
            'client_phone' => fake()->optional()->numerify('57##########'),
            'discount_amount' => fake()->randomFloat(2, 1000, 50000),
            'order_total_before' => fake()->randomFloat(2, 10000, 200000),
            'order_total_after' => fake()->randomFloat(2, 1000, 190000),
            'created_at' => fake()->dateTimeBetween('-90 days', 'now'),
        ];
    }
}
