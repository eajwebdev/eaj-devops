<?php

namespace Database\Seeders;

use App\Actions\Organizations\ProvisionPersonalOrganization;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Local development only: one owner with a personal organization and no
     * projects. Demo data (Plan §58) belongs in a separate demo organization.
     */
    public function run(ProvisionPersonalOrganization $provision): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $provision->handle($user);
    }
}
