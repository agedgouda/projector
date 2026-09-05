<?php

namespace Database\Factories;

use App\Models\SlackWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlackChannelBinding>
 */
class SlackChannelBindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * project_id has no sensible default (Project has no factory of its own — every existing
     * test creates one directly via Project::create() alongside a Client), so callers must
     * always pass it explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slack_workspace_id' => SlackWorkspace::factory(),
            'channel_id' => 'C'.$this->faker->unique()->bothify('##########'),
            'channel_name' => $this->faker->word(),
        ];
    }
}
