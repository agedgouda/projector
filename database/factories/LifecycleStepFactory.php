<?php

namespace Database\Factories;

use App\Models\ProjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LifecycleStep>
 */
class LifecycleStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_type_id' => ProjectType::factory(),
            'order' => $this->faker->numberBetween(1, 10),
            'label' => $this->faker->words(2, true),
            'description' => $this->faker->optional()->sentence(),
            'color' => $this->faker->randomElement(['indigo', 'blue', 'green', 'amber', 'orange', 'red', 'purple', 'pink', 'slate']),
        ];
    }
}
