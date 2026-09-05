<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlackWorkspace>
 */
class SlackWorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'team_id' => 'T'.$this->faker->unique()->bothify('##########'),
            'team_name' => $this->faker->company(),
            'bot_access_token' => 'xoxb-'.$this->faker->uuid(),
            'bot_user_id' => 'U'.$this->faker->bothify('##########'),
            'scopes' => 'chat:write,commands,files:read,channels:history,users:read',
            'installed_by_user_id' => null,
        ];
    }
}
