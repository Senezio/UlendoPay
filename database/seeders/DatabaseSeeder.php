<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Core Infrastructure (Must run first)
        $priority = [
            'PartnerSeeder',
            'SystemAccountSeeder'
        ];

        foreach ($priority as $seeder) {
            $class = "Database\\Seeders\\$seeder";
            if (class_exists($class)) {
                $this->command->info("Seeding Priority: $seeder");
                $this->call($class);
            }
        }

        // 2. All other seeders EXCEPT UserSeeder
        $files = File::files(database_path('seeders'));
        foreach ($files as $file) {
            $className = $file->getBasename('.php');
            
            $exclude = array_merge($priority, ['DatabaseSeeder', 'UserSeeder']);
            
            if (!in_array($className, $exclude)) {
                $this->call("Database\\Seeders\\$className");
            }
        }

        $this->command->warn("Infrastructure seeded. User table left empty.");
    }
}
