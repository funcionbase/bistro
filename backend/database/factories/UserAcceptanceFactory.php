<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAcceptance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAcceptance>
 */
class UserAcceptanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'document_type' => fake()->randomElement(['terms', 'privacy', 'contract']),
            'accepted_at' => now(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
