<?php

namespace Database\Factories;

use App\Models\DemoSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoSession>
 */
class DemoSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ref' => null,
            'country' => fake()->countryCode(),
            'device_type' => fake()->randomElement(['desktop', 'smartphone', 'tablet']),
            'browser' => fake()->randomElement(['Chrome', 'Safari', 'Firefox']),
            'referrer_host' => fake()->domainName(),
        ];
    }

    public function started(): static
    {
        return $this->state(fn (): array => ['started_at' => now()]);
    }
}
