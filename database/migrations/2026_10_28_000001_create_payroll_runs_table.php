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
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->unsignedSmallInteger('payroll_year');
            $table->unsignedTinyInteger('payroll_month');
            $table->string('status', 30)->default('QUEUED'); // QUEUED, PROCESSING, COMPLETED, FAILED, LOCKED
            $table->foreignId('run_by_user')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('run_timestamp')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('total_employees')->default(0);
            $table->decimal('total_gross_paid', 14, 2)->default(0.00);
            $table->decimal('total_net_paid', 14, 2)->default(0.00);
            $table->timestamps();

            $table->unique(['company_id', 'payroll_year', 'payroll_month'], 'payroll_runs_company_year_month_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};