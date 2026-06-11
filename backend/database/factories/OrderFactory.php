<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_nit' => fn () => Company::factory()->create()->nit,
            'session_id' => fake()->optional()->lexify('????????????????'),
            'client_phone' => fake()->optional()->numerify('57##########'),
            'items' => null,
            'status' => fake()->randomElement(['completed', 'cancelled', 'abandoned']),
            'total' => fake()->randomFloat(2, 5000, 200000),
            'cost' => fake()->randomFloat(2, 1000, 80000),
            'ordered_at' => fake()->dateTimeBetween('-90 days', 'now'),
        ];
    }

    // --- Operational lifecycle states ---

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'pending']);
    }

    public function inKitchen(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'in_kitchen']);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'ready']);
    }

    public function inTransit(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'in_transit']);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'completed']);
    }

    // --- Terminal failure states ---

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'failed']);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'cancelled']);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'refunded']);
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'abandoned']);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => ['company_nit' => $company->nit]);
    }

    public function orderedAt(\DateTimeInterface $date): static
    {
        return $this->state(fn (array $attributes) => ['ordered_at' => $date]);
    }

    /** @param  list<array<string, mixed>>  $items */
    public function withItems(array $items): static
    {
        return $this->state(fn (array $attributes) => [
            'items' => $items,
            'total' => collect($items)->sum(fn ($i) => ($i['price'] ?? 0) * ($i['quantity'] ?? 1)),
        ]);
    }

    public function fromChatbot(string $sessionId, string $clientPhone): static
    {
        return $this->state(fn (array $attributes) => [
            'session_id' => $sessionId,
            'client_phone' => $clientPhone,
            'status' => 'pending',
        ]);
    }
}
