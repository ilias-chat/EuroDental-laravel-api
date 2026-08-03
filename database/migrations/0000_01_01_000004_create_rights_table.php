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
       // 🔹 4. Create Rights Table
        Schema::create('rights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_profile')->constrained('profiles');
            $table->boolean('invoices_read');
            $table->boolean('invoices_write');
            $table->boolean('clients_read');
            $table->boolean('clients_write');
            $table->boolean('products_read');
            $table->boolean('products_write');
            $table->boolean('tasks_read');
            $table->boolean('tasks_write');
            $table->boolean('users_read');
            $table->boolean('users_write');
            $table->boolean('mobile_tasks_read');
            $table->boolean('mobile_tasks_write');
            $table->boolean('mobile_stock_read');
            $table->boolean('mobile_stock_write');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rights');
    }
};
