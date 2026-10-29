<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            // Primary Key
            $table->id('id');

            $table->string('shift_code', 30)->unique();
            $table->string('shift_name', 100);            
            $table->time('shift_start_time');
            $table->time('shift_end_time');
            $table->integer('grace_time')->default(20);                       
            
            // Working hours minutes me store honge (e.g., 480 mins = 8 hours)
            $table->integer('full_day_min_hours')->nullable()->comment('Duration in minutes');
            $table->integer('short_leave_min_hours')->nullable()->comment('Duration in minutes');
            $table->integer('half_day_min_hours')->nullable()->comment('Duration in minutes');
            $table->integer('no_salary_max_hours')->nullable()->comment('Duration in minutes');            
            
            // Effective-Dating (FRS Standards)
            $table->date('effective_from')->default(DB::raw('CURRENT_DATE'));
            $table->date('effective_to')->default('2099-12-31');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_delete')->default(false); 
            
            // Soft delete & tracking fields
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable();  
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
        Schema::dropIfExists('shifts');
    }
};