<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\TaskType;

class TaskTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = ['Installation', 'Maintenance', 'Réparation', 'Autre'];
        
        foreach ($types as $type) {
            TaskType::create(['name' => $type]);
        }
    }
}
