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
        Schema::create('companies', function (Blueprint $table) {
            $table->id(); // Primary Key
            $table->boolean('is_active')->default(true);
            $table->boolean('is_delete')->default(false);
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('company_code')->unique();
            $table->string('company_name');
            $table->string('company_type')->nullable();
            $table->string('pan_number')->unique()->nullable();
            $table->string('tan_number')->unique()->nullable();
            $table->string('gstin_number')->unique()->nullable();
            $table->string('pf_number')->nullable();
            $table->string('esi_number')->nullable();
            $table->string('lwf_number')->nullable();
            $table->text('registered_address')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps(); // created_at aur updated_at columns
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
