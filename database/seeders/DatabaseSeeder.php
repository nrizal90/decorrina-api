<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AuthRolePermissionSeeder::class,
            // Bergantung pada tenant "decorinna" yang dibuat seeder di atas.
            MasterDataSeeder::class,
        ]);
    }
}
