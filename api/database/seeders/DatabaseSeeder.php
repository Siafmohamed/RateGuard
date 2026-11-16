<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The WithoutModelEvents trait is deliberately NOT used here: the tenancy
     * trait fills organization_id from a "creating" model event, so model
     * events must stay enabled while seeding.
     */
    public function run(): void
    {
        $this->call(DemoDataSeeder::class);
    }
}
