<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlatformSeeder::class);

        // Demo companies, buyers and auctions; never on a production install.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
