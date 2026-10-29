<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        Company::truncate();
        Schema::enableForeignKeyConstraints();

        $companies = [
            [
                'company_code' => 'SRPL',
                'company_name' => 'Suvidha Retail Pvt. Ltd.',
                'company_type' => 'Private Limited',
                'pan_number' => 'ABCDE1234F',
                'tan_number' => 'DELA12345B',
                'gstin_number' => '07ABCDE1234F1Z5',
                'pf_number' => 'DL/12345/ABC',
                'esi_number' => '11000123450001001',
                'lwf_number' => 'LWF/DL/12345',
                'registered_address' => '123, Main Market, New Delhi, India',
                'is_active' => true,
                'created_by' => 1,
            ],
            [
                'company_code' => 'HFS',
                'company_name' => 'Himaira Fashion Studio',
                'company_type' => 'Proprietorship',
                'pan_number' => 'FGHIJ5678K',
                'tan_number' => 'DELH54321C',
                'gstin_number' => '07FGHIJ5678K1Z6',
                'is_active' => true,
                'created_by' => 1,
            ],
        ];

        foreach ($companies as $companyData) {
            Company::create($companyData);
        }
    }
}