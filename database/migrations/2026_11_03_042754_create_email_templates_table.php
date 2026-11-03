<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_code')->unique(); // e.g. TPL-101
            $table->string('title');
            $table->string('category');
            $table->enum('channel', ['email', 'whatsapp', 'telegram', 'all'])->default('email');
            $table->string('subject')->nullable();
            $table->longText('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
