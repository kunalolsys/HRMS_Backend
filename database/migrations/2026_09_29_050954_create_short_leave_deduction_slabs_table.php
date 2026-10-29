<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_leave_deduction_slabs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('short_leaves_count')->unique(); // e.g. 3, 5, 6
            $table->decimal('days_deducted', 3, 1);                 // e.g. 1.0, 1.5, 2.0
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_leave_deduction_slabs');
    }
};