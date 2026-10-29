<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dynamic_tags', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g. employee_name, store_location
            $table->string('label');         // e.g. {employee_name}
            $table->string('description');   // e.g. Full Name of Employee
            $table->string('color')->default('blue');
            $table->string('mapped_column')->nullable(); // users table column: name, email, employee_code etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_tags');
    }
};
