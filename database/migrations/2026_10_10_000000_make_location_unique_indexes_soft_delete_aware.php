<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE locations DROP CONSTRAINT IF EXISTS locations_location_code_unique');
        DB::statement('ALTER TABLE locations DROP CONSTRAINT IF EXISTS locations_location_name_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS locations_location_code_unique ON locations (location_code) WHERE is_delete = false');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS locations_location_name_unique ON locations (location_name) WHERE is_delete = false');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS locations_location_code_unique');
        DB::statement('DROP INDEX IF EXISTS locations_location_name_unique');
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_location_code_unique UNIQUE (location_code)');
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_location_name_unique UNIQUE (location_name)');
    }
};
