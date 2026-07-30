<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KanbanColumn>
 */
class KanbanColumnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = $this->faker->unique()->words(2, true);

        return [
            'project_id' => Project::factory(),
            'key' => Str::slug($label, '_'),
            'label' => $label,
            'color' => $this->faker->randomElement(['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal']),
            'order' => $this->faker->numberBetween(1, 10),
        ];
    }
}
