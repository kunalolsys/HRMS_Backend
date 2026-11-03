<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broadcast_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->default('email');
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('subject')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_logs');
    }
};
