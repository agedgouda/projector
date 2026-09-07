<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlackUserIdentity>
 */
class SlackUserIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'slack_team_id' => 'T'.$this->faker->unique()->bothify('##########'),
            'slack_user_id' => 'U'.$this->faker->unique()->bothify('##########'),
            'slack_username' => $this->faker->userName(),
        ];
    }
}
