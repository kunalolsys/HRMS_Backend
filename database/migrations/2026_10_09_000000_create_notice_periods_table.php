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
        Schema::create('notice_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('designation_id')->constrained('designations')->cascadeOnDelete();
            $table->integer('notice_period_days')->comment('Notice period for confirmed employees in days');
            $table->integer('probation_notice_period_days')->nullable()->comment('Notice period during probation in days');
            $table->text('description')->nullable();

            // Status & Tracking fields
            $table->boolean('is_active')->default(true);
            $table->boolean('is_delete')->default(false);
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notice_periods');
    }
};