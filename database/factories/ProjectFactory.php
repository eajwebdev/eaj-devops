<?php

namespace Database\Factories;

use App\Enums\ProjectConnectionType;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'key' => strtoupper(Str::random(1).fake()->unique()->bothify('?##')),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Project $project) {
            $project->connection()->save(new ProjectConnection([
                'type' => ProjectConnectionType::Local->value,
                'local_path' => 'C:\\laragon\\www\\'.$project->slug,
            ]));
        });
    }
}
