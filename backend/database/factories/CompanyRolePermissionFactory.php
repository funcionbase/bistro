<?php

namespace Database\Factories;

use App\Models\CompanyRole;
use App\Models\CompanyRolePermission;
use App\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyRolePermission>
 */
class CompanyRolePermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_role_id' => function () {
                return CompanyRole::factory()->create()->id;
            },
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
