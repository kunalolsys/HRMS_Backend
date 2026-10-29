<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('late_deduction_slabs', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('days_deducted');
            $table->boolean('is_delete')->default(false)->after('is_active');
            $table->unsignedBigInteger('deleted_by')->nullable()->after('is_delete');
            $table->timestamp('deleted_at')->nullable()->after('deleted_by');
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('short_leave_deduction_slabs', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('days_deducted');
            $table->boolean('is_delete')->default(false)->after('is_active');
            $table->unsignedBigInteger('deleted_by')->nullable()->after('is_delete');
            $table->timestamp('deleted_at')->nullable()->after('deleted_by');
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('late_deduction_slabs', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['is_active', 'is_delete', 'deleted_by', 'deleted_at']);
        });

        Schema::table('short_leave_deduction_slabs', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['is_active', 'is_delete', 'deleted_by', 'deleted_at']);
        });
    }
};