<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_id' => fake()->unique()->numerify('##########'),
            // `name` es columna generada (first_name + last_name): no se setea.
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function pendingEnrollment(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_enrollment',
            'first_name' => null,
            'last_name' => null,
            'cedula' => null,
        ]);
    }

    public function withCedula(): static
    {
        return $this->state(fn (array $attributes) => [
            'cedula' => fake()->unique()->numerify('##########'),
        ]);
    }
}
