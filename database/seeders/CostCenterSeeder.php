<?php

namespace Database\Seeders;

use App\Models\CostCenter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class CostCenterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable foreign key checks to truncate the table
        Schema::disableForeignKeyConstraints();
        CostCenter::truncate();
        Schema::enableForeignKeyConstraints();

        $costCenters = [
            ['id' => 1, 'name' => 'HEAD OFFICE'],
            ['id' => 2, 'name' => 'KARNAL'],
            ['id' => 3, 'name' => 'PANIPAT'],
            ['id' => 4, 'name' => 'YAMUNA NAGAR'],
            ['id' => 5, 'name' => 'KURUKSHETRA'],
            ['id' => 6, 'name' => 'ROHTAK'],
            ['id' => 7, 'name' => 'HISAR'],
            ['id' => 8, 'name' => 'KAITHAL'],
            ['id' => 21, 'name' => 'SONIPAT'],
            ['id' => 27, 'name' => 'MURTHAL'],
            ['id' => 33, 'name' => 'MEERUT'],
            ['id' => 38, 'name' => 'FACTORY OUTLET'],
            ['id' => 43, 'name' => 'MATHURA'],
            ['id' => 51, 'name' => 'REWARI'],
            ['id' => 99, 'name' => 'Suvidha Website'],
        ];

        foreach ($costCenters as $center) {
            CostCenter::create([
                'id' => $center['id'],
                'code' => 'CC-' . $center['id'], // Add a unique code
                'name' => $center['name'],
                'is_active' => true,
                'created_by' => 1, // Assuming user with ID 1 is the default creator
            ]);
        }
    }
}