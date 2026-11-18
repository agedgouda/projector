<?php

namespace Database\Factories;

use App\Models\LifecycleTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LifecycleTemplate>
 */
class LifecycleTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'organization_id' => null,
        ];
    }
}
