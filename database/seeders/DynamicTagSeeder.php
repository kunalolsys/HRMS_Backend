<?php

namespace Database\Seeders;

use App\Models\DynamicTag;
use Illuminate\Database\Seeder;

class DynamicTagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            [
                'key' => 'employee_name',
                'label' => '{employee_name}',
                'description' => 'Full Name of Employee',
                'color' => 'blue',
                'mapped_column' => 'name'
            ],
            [
                'key' => 'employee_code',
                'label' => '{employee_code}',
                'description' => 'Employee ID Code',
                'color' => 'geekblue',
                'mapped_column' => 'employee_code'
            ],
            [
                'key' => 'store_location',
                'label' => '{store_location}',
                'description' => 'Assigned Store Branch',
                'color' => 'green',
                'mapped_column' => 'store_location'
            ],
            [
                'key' => 'designation',
                'label' => '{designation}',
                'description' => 'Current Designation',
                'color' => 'purple',
                'mapped_column' => 'designation'
            ],
            [
                'key' => 'salary_month',
                'label' => '{salary_month}',
                'description' => 'Target Payroll Month',
                'color' => 'gold',
                'mapped_column' => null // Dynamically calculated
            ],
            [
                'key' => 'festival_name',
                'label' => '{festival_name}',
                'description' => 'Current Occasion / Festival',
                'color' => 'magenta',
                'mapped_column' => null
            ],
        ];

        foreach ($tags as $tag) {
            DynamicTag::updateOrCreate(['key' => $tag['key']], $tag);
        }
    }
}
