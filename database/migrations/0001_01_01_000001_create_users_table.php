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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique(); // keep
            $table->string('password');        // keep
            $table->string('first_name');      // add
            $table->string('last_name');       // add
            $table->string('phone_number')->nullable(); // add
            $table->foreignId('city_id')->nullable()->constrained('cities');
            $table->text('address')->nullable();        // add
            $table->foreignId('profile_id')->constrained('profiles'); // add
            $table->boolean('is_blocked')->default(false);             // add
            $table->boolean('requires_password_change')->default(false); // add
            $table->foreignId('image_id')->nullable()->constrained('images'); // add
            $table->timestamp('email_verified_at')->nullable(); // keep
            $table->rememberToken();  // keep
            $table->timestamps();     // keep
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
