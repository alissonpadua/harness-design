<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrgType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'type' => OrgType::Team,
            'owner_id' => User::factory(),
        ];
    }
}
