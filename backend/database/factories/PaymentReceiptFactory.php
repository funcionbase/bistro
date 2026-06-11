<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Order;
use App\Models\PaymentReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentReceipt>
 */
class PaymentReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'company_nit' => fn () => Company::factory()->create()->nit,
            'file_path' => 'receipts/'.fake()->uuid().'.jpg',
            'payment_data' => [
                'amount' => fake()->randomFloat(2, 5000, 200000),
                'reference' => fake()->numerify('REF-######'),
            ],
        ];
    }
}
