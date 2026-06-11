<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $assignedAt = fake()->dateTimeBetween('-30 days', 'now');

        return [
            'company_nit' => fn () => Company::factory()->create()->nit,
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'assigned_at' => $assignedAt,
            'delivered_at' => null,
            'duration_minutes' => null,
            'status' => 'pending',
            'previous_delivery_id' => null,
            'reason' => 'Asignación inicial',
            'cancellation_reason' => null,
            'created_by' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attrs) => ['status' => 'pending']);
    }

    public function completed(): static
    {
        return $this->state(function (array $attrs) {
            $assignedAt = $attrs['assigned_at'] instanceof \DateTimeInterface
                ? $attrs['assigned_at']
                : new \DateTime($attrs['assigned_at']);
            $deliveredAt = (clone $assignedAt)->modify('+'.fake()->numberBetween(10, 60).' minutes');

            return [
                'status' => 'completed',
                'delivered_at' => $deliveredAt,
                'duration_minutes' => (int) (($deliveredAt->getTimestamp() - $assignedAt->getTimestamp()) / 60),
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'cancelled',
            'cancellation_reason' => fake()->sentence(),
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attrs) => ['company_nit' => $company->nit]);
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attrs) => [
            'order_id' => $order->id,
            'company_nit' => $order->company_nit,
        ]);
    }

    public function forDeliverer(User $user): static
    {
        return $this->state(fn (array $attrs) => ['user_id' => $user->id]);
    }
}
