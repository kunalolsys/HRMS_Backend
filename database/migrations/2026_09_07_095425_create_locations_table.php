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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_delete')->default(false);
            $table->integer('deleted_by')->nullable();
            $table->datetime('deleted_at')->nullable();
            $table->string('location_code')->unique();
            $table->string('location_name')->unique();
            $table->string('group_type')->nullable();
            $table->string('state')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->foreign('cost_center_id')->references('id')->on('cost_centers')->onDelete('set null');
            $table->text('est_registration')->nullable();
            $table->date('renewal_date')->nullable();
            $table->text('biometric_device_id')->nullable();
            $table->string('weekly_off_day')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');           
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
