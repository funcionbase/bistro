<?php

namespace Database\Factories;

use App\Models\Feature;
use App\Models\PermissionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PermissionTemplate>
 */
class PermissionTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_type' => fake()->randomElement(['owner', 'admin', 'employee']),
            'feature_id' => function () {
                return Feature::factory()->create()->id;
            },
            'can_create' => fake()->boolean(),
            'can_read' => true,
            'can_update' => fake()->boolean(),
            'can_delete' => fake()->boolean(),
        ];
    }
}
