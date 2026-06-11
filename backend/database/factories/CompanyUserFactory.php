<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyUser>
 */
class CompanyUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_nit' => function () {
                return Company::factory()->create()->nit;
            },
            'user_id' => function () {
                return User::factory()->create()->id;
            },
            'company_role_id' => function () {
                return CompanyRole::factory()->create()->id;
            },
        ];
    }
}
