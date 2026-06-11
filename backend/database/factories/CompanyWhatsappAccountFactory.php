<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyWhatsappAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyWhatsappAccount>
 */
class CompanyWhatsappAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_nit' => fn () => Company::factory()->create()->nit,
            'provisioning_mode' => 'embedded_signup',
            'status' => 'pending',
            'waba_id' => (string) $this->faker->numerify('################'),
            'phone_number_id' => (string) $this->faker->unique()->numerify('################'),
            'business_id' => (string) $this->faker->numerify('################'),
            'phone_e164' => '+57'.$this->faker->numerify('##########'),
            'display_name' => $this->faker->company(),
            'display_name_status' => 'APPROVED',
            'is_business_verified' => false,
        ];
    }

    public function connected(): self
    {
        return $this->state([
            'status' => 'connected',
            'connected_at' => now(),
            'access_token_encrypted' => 'fake-token-'.$this->faker->uuid(),
            'webhook_subscribed_at' => now(),
        ]);
    }

    public function naas(): self
    {
        return $this->state([
            'provisioning_mode' => 'naas',
            'naas_provider' => 'twilio',
        ]);
    }
}
