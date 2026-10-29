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
        Schema::create('employee_attendance_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_attendance_id')->constrained('employee_attendances')->cascadeOnDelete();
            $table->dateTime('in_out_time')->comment('Timestamp of punch in or punch out');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_details');
    }
};