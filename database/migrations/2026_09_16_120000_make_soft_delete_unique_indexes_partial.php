<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $uniqueConstraints = [
            'companies' => [
                'companies_company_code_unique' => 'company_code',
                'companies_pan_number_unique' => 'pan_number',
                'companies_tan_number_unique' => 'tan_number',
                'companies_gstin_number_unique' => 'gstin_number',
            ],
            'cost_centers' => [
                'cost_centers_code_unique' => 'code',
            ],
            'divisions' => [
                'divisions_name_unique' => 'name',
            ],
            'brands' => [
                'brands_brand_code_unique' => 'brand_code',
            ],
        ];

        foreach ($uniqueConstraints as $table => $constraints) {
            foreach ($constraints as $constraint => $column) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$constraint} ON {$table} ({$column}) WHERE is_delete = false AND {$column} IS NOT NULL");
            }
        }
    }

    public function down(): void
    {
        $indexes = [
            'companies' => ['companies_company_code_unique' => 'company_code', 'companies_pan_number_unique' => 'pan_number', 'companies_tan_number_unique' => 'tan_number', 'companies_gstin_number_unique' => 'gstin_number'],
            'cost_centers' => ['cost_centers_code_unique' => 'code'],
            'divisions' => ['divisions_name_unique' => 'name'],
            'brands' => ['brands_brand_code_unique' => 'brand_code'],
        ];

        foreach ($indexes as $table => $tableIndexes) {
            foreach ($tableIndexes as $index => $column) {
                DB::statement("DROP INDEX IF EXISTS {$index}");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$index} UNIQUE ({$column})");
            }
        }
    }
};