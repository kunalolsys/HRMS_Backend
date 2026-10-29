<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Designation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DesignationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable foreign key checks to avoid issues with existing relations
        Schema::disableForeignKeyConstraints();
        // Truncate the table to start fresh
        Designation::truncate();
        Schema::enableForeignKeyConstraints();

        $designations = [
            'DM', 'Promoter (Brand)', 'CCE', 'DM (POS)', 'VM', 'HR Head', 'Tagger', 'Merchandiser',
            'Driver', 'Petty Expense', 'Debit/Credit notes', 'IT (HO)', 'Operator', 'Security',
            'Picker', 'HR', 'PC HO', 'Accounts', 'Bank Reconcilation', 'Stock Reco', 'SOR Accounts',
            'CRM', 'Outright accounts', 'Purchase invoice', 'Management', 'Head merchandiser',
            'Scanning', 'Ledger Reco', 'Warehouse Head', 'Operations Head', 'SM', 'CCE (Brand)',
            'OM', 'Electrician', 'Promoter', 'Cashier', 'IT Head', 'Tailor', 'House Keeping',
            'House Keeping Head', 'IT (Locations)', 'Services (POS)', 'IT (Warehouse)',
            'Data Entry', 'Customer Care', 'Auditor', 'Marketing Executive'
        ];

        $uniqueDesignations = array_unique($designations);
        $i = 1;

        foreach ($uniqueDesignations as $designationName) {
            Designation::create([
                'name' => $designationName,
                'code' => 'DESG' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'is_active' => true,
                'created_by' => 1, // Assuming user with ID 1 is the default creator
            ]);
            $i++;
        }
    }
}
