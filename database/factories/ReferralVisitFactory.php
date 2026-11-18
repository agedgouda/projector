<?php

namespace Database\Factories;

use App\Models\ReferralVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralVisit>
 */
class ReferralVisitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ref' => fake()->slug(2),
            'landing_path' => fake()->randomElement(['/login', '/register']),
            'country' => fake()->countryCode(),
            'device_type' => fake()->randomElement(['desktop', 'smartphone', 'tablet']),
            'browser' => fake()->randomElement(['Chrome', 'Safari', 'Firefox']),
            'referrer_host' => fake()->domainName(),
            'converted_at' => null,
        ];
    }

    public function converted(): static
    {
        return $this->state(fn (): array => ['converted_at' => now()]);
    }
}
