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
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('paid_days', 5, 2)->default(0.00);
            $table->decimal('earned_gross', 12, 2)->default(0.00);
            $table->decimal('earned_basic', 12, 2)->default(0.00);
            $table->decimal('pf_employee_deduction', 10, 2)->default(0.00);
            $table->decimal('esi_employee_deduction', 10, 2)->default(0.00);
            $table->decimal('total_deductions', 12, 2)->default(0.00);
            $table->decimal('pf_employer_contribution', 10, 2)->default(0.00)->after('esi_employee_deduction');
            $table->decimal('eps_employer_contribution', 10, 2)->default(0.00)->after('pf_employer_contribution');
            $table->decimal('esi_employer_contribution', 10, 2)->default(0.00)->after('eps_employer_contribution');
            $table->decimal('total_employer_contribution', 12, 2)->default(0.00)->after('esi_employer_contribution');
            $table->decimal('net_pay', 12, 2)->default(0.00);
            $table->string('status', 30)->default('DRAFT'); // DRAFT, GENERATED, PAID
            $table->timestamps();

            // Required for batch upserting in ProcessPayrollRunJob
            $table->unique(['payroll_run_id', 'employee_id'], 'payslips_run_employee_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};