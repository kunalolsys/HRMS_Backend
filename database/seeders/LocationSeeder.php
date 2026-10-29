<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable foreign key checks to truncate the table
        Schema::disableForeignKeyConstraints();
        Location::truncate();
        Schema::enableForeignKeyConstraints();

        $locations = [
            ['name' => 'PANIPAT', 'code' => 3],
            ['name' => 'KARNAL', 'code' => 2],
            ['name' => 'KURUKSHETRA', 'code' => 5],
            ['name' => 'MEERUT', 'code' => 33],
            ['name' => 'HISAR', 'code' => 7],
            ['name' => 'ROHTAK', 'code' => 6],
            ['name' => 'KAITHAL', 'code' => 8],
            ['name' => 'BUFFER', 'code' => 9],
            ['name' => 'HEAD OFFICE', 'code' => 1],
            ['name' => 'Suvidha Website', 'code' => 99],
            ['name' => 'SONIPAT', 'code' => 21],
            ['name' => 'SONIPAT ONLINE', 'code' => 10],
            ['name' => 'Himaira', 'code' => 11],
            ['name' => 'MURTHAL', 'code' => 27],
            ['name' => 'YAMUNA NAGAR', 'code' => 4],
            ['name' => 'FACTORY OUTLET', 'code' => 38],
            ['name' => 'MATHURA', 'code' => 43],
            ['name' => 'REWARI', 'code' => 51],
        ];

        $i = 1;
        foreach ($locations as $location) {       

            Location::create([
                'location_name' => $location['name'],                
                'location_code' => $location['code'],
                'is_active' => true,
                'created_by' => 1, // Assuming user with ID 1 is the default creator
            ]);
            $i++;
        }
    }
}