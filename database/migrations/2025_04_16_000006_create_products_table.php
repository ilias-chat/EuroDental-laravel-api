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
        // 🔹 10. Create Products Table
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_name');
            $table->foreignId('id_category')->constrained('categories');
            $table->foreignId('id_sub_category')->constrained('sub_categories');
            $table->foreignId('id_brand')->constrained('brands');
            $table->decimal('price', 10, 2);
            $table->integer('stock_quantity');
            $table->boolean('has_warranty');
            $table->integer('warranty_duration_months')->nullable();
            $table->date('purchase_date')->nullable();
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('image_id')->nullable()->constrained('images');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
