<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        Schema::table($rolesTable, function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->boolean('is_delete')->default(false);
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        Schema::table($rolesTable, function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'is_delete', 'deleted_by', 'deleted_at']);
        });
    }
};
