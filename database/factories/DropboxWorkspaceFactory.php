<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DropboxWorkspace>
 */
class DropboxWorkspaceFactory extends Factory
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
            'account_id' => 'dbid:'.$this->faker->unique()->bothify('??????????????????'),
            'account_name' => $this->faker->company(),
            'access_token' => 'sl.'.$this->faker->uuid(),
            'refresh_token' => 'rt.'.$this->faker->uuid(),
            'installed_by_user_id' => null,
        ];
    }
}
