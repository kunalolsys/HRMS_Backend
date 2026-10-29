<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $columnType = $this->deletedByColumnType();
        if (!in_array($columnType, ['timestamp', 'timestamp without time zone', 'timestamp with time zone'], true)) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE minimum_wage_masters ALTER COLUMN deleted_by TYPE BIGINT USING NULL::BIGINT');

            return;
        }

        Schema::table('minimum_wage_masters', function (Blueprint $table): void {
            $table->unsignedBigInteger('deleted_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        $columnType = $this->deletedByColumnType();
        if ($columnType !== 'bigint') {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE minimum_wage_masters ALTER COLUMN deleted_by TYPE TIMESTAMP USING NULL::TIMESTAMP');

            return;
        }

        Schema::table('minimum_wage_masters', function (Blueprint $table): void {
            $table->timestamp('deleted_by')->nullable()->change();
        });
    }

    private function deletedByColumnType(): ?string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $column = DB::selectOne(
                'SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                ['minimum_wage_masters', 'deleted_by']
            );

            return $column?->data_type;
        }

        return Schema::hasColumn('minimum_wage_masters', 'deleted_by')
            ? Schema::getColumnType('minimum_wage_masters', 'deleted_by')
            : null;
    }
};
