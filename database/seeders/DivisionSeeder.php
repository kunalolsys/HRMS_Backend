<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Division;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DivisionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable foreign key checks to avoid issues with existing relations
        Schema::disableForeignKeyConstraints();
        // Truncate the table to start fresh
        Division::truncate();
        Schema::enableForeignKeyConstraints();

        $divisions = [
            'BATH AND BEAUTY',
            'EXTRA',
            'KIDS',
            'LADIES',
            'MENS',
            'MTO',
            'Non Apparels',
            'Non Trade',
            'Suvidha Promo',
            'UNIFORM',
            'WOMEN'
        ];

        // Using array_unique to prevent any accidental duplicates from the list
        foreach (array_unique($divisions) as $divisionName) {
            Division::create([
                'name' => $divisionName,
                'is_active' => true,
                'created_by' => 1, // Assuming a user with ID 1 is the default creator/admin
            ]);
        }
    }
}
