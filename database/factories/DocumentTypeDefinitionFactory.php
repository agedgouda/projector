<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DocumentTypeDefinition>
 */
class DocumentTypeDefinitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'key' => $this->faker->unique()->slug(2, false),
            'label' => $this->faker->words(2, true),
            'is_task' => false,
            'order' => $this->faker->numberBetween(1, 10),
        ];
    }
}
