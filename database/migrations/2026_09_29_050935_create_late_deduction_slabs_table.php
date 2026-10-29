<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_deduction_slabs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('lates_count')->unique(); // e.g. 5, 8, 10
            $table->decimal('days_deducted', 3, 1);           // e.g. 1.0, 1.5, 2.0
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_deduction_slabs');
    }
};