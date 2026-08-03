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
        // 🔹 5. Create Clients Table
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone_number')->nullable();
            $table->string('fixed_phone_number')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('city_id')->constrained('cities');
            $table->text('description')->nullable();
            $table->foreignId('image_id')->nullable()->constrained('images');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
