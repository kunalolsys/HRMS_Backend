<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('statutory_parameter_masters', function (Blueprint $table): void {
            if (!Schema::hasColumn('statutory_parameter_masters', 'is_delete')) {
                $table->boolean('is_delete')->default(false)->after('is_active');
            }

            if (!Schema::hasColumn('statutory_parameter_masters', 'deleted_by')) {
                $table->unsignedBigInteger('deleted_by')->nullable()->after('is_delete');
                $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('statutory_parameter_masters', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('deleted_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statutory_parameter_masters', function (Blueprint $table): void {
            if (Schema::hasColumn('statutory_parameter_masters', 'deleted_by')) {
                $table->dropForeign(['deleted_by']);
                $table->dropColumn('deleted_by');
            }

            $columns = [];
            if (Schema::hasColumn('statutory_parameter_masters', 'deleted_at')) {
                $columns[] = 'deleted_at';
            }
            if (Schema::hasColumn('statutory_parameter_masters', 'is_delete')) {
                $columns[] = 'is_delete';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};