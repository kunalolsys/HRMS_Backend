<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Statutory Parameter Master
        Schema::create('statutory_parameter_masters', function (Blueprint $table) {
            $table->id();
            $table->string('rule_code', 50)->unique();
            $table->text('description')->nullable();

            // Provident Fund (PF)
            $table->decimal('pf_employee_rate', 5, 2)->default(12.00);
            $table->decimal('pf_employer_rate', 5, 2)->default(13.00);
            $table->decimal('pf_wage_ceiling', 10, 2)->default(15000.00);
            $table->decimal('pf_eps_rate', 5, 2)->default(8.33);
            $table->decimal('pf_eps_max_cap', 10, 2)->default(1250.00);
            $table->integer('pf_start_month')->default(9); // 9th month start

            // ESI
            $table->decimal('esi_employee_rate', 5, 2)->default(0.75);
            $table->decimal('esi_employer_rate', 5, 2)->default(3.25);
            $table->decimal('esi_gross_ceiling', 10, 2)->default(21000.00);
            $table->integer('esi_start_month')->default(1); // 1st month start

            // Deduction Cap
            $table->decimal('total_deduction_cap_pct', 5, 2)->default(50.00);

            // Effective Dating
            $table->date('effective_from');
            $table->date('effective_to')->default('2099-12-31');
            $table->boolean('is_active')->default(true);           
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        // 2. Minimum Wage Sub-Master
        Schema::create('minimum_wage_masters', function (Blueprint $table) {
            $table->id();
            $table->string('state_code', 10); // HR / UP / UK
            $table->string('skill_category', 30); // Unskilled, Semi-skilled, Skilled, Highly Skilled
            $table->decimal('basic_minimum_wage', 10, 2);
            $table->decimal('vda_amount', 10, 2)->default(0.00);

            $table->date('effective_from');
            $table->date('effective_to')->default('2099-12-31');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minimum_wage_masters');
        Schema::dropIfExists('statutory_parameter_masters');
    }
};