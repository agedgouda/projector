<?php

namespace Database\Factories;

use App\Models\ProjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkflowStep>
 */
class WorkflowStepFactory extends Factory
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
            'from_key' => $this->faker->unique()->slug(2, false),
            'to_key' => $this->faker->unique()->slug(2, false),
            'ai_template_id' => null,
            'single_output' => false,
            'order' => $this->faker->numberBetween(1, 10),
        ];
    }
}
