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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_delete')->default(false);
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('permanent_unique_id')->unique()->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->string('employee_code')->unique()->nullable();
            $table->string('employee_name');
            $table->string('mobile_number')->unique()->nullable();
            $table->string('email_id')->unique()->nullable();
            $table->string('pan_number', 10)->unique()->nullable()->comment('10-character Alphanumeric');
            $table->string('blood_group')->nullable();
            $table->string('gender')->nullable();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->integer('age')->nullable();
            $table->string('job_status')->nullable();
            $table->string('ecode')->nullable();
            $table->date('birthday_date')->nullable();
            $table->string('status')->nullable();
            $table->string('mode')->nullable();
            $table->string('father_name')->nullable();
            $table->text('current_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('spouse_name')->nullable();
            $table->date('anniversary_date')->nullable();            
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->decimal('basic_salary', 10, 2)->nullable();
            $table->date('date_of_join')->nullable();
            $table->date('date_of_leave')->nullable();
            $table->string('pf_number', 22)->nullable()->comment('22-character Alphanumeric');
            $table->string('uan_number', 12)->nullable()->comment('12-digit Numeric');
            $table->string('esi_number', 17)->nullable()->comment('17-digit Numeric');
            $table->string('bank_name')->nullable();
            $table->string('account_number', 20)->nullable()->comment('9 to 18 digits, allowing up to 20');
            $table->string('ifsc_number', 11)->nullable()->comment('11-character Alphanumeric');
            $table->string('weekly_off')->nullable();
            $table->boolean('brand_staff')->default(false);
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('employee_type')->nullable();
            $table->text('employee_photo')->nullable()->comment('Can store base64 string or a URL');
            $table->string('emergency_contact_person_name')->nullable();
            $table->string('emergency_contact_person_relation')->nullable();
            $table->string('emergency_contact_person_phone_number')->nullable();
            $table->string('reporting_dm')->nullable();            
            $table->string('reporting_sm')->nullable();
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
        Schema::dropIfExists('employees');
    }
};
