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
        Schema::create('product_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');

            $table->text('html_description')->nullable(); // Rich HTML content

            $table->decimal('package_length', 10, 2)->nullable(); // in cm
            $table->decimal('package_width', 10, 2)->nullable();
            $table->decimal('package_height', 10, 2)->nullable();
            $table->decimal('weight_grams', 10, 2)->nullable(); // Optional: for shipping

            $table->json('gallery')->nullable(); // array of image IDs from images table

            $table->string('seo_title')->nullable(); // Optional
            $table->text('seo_description')->nullable();
            $table->json('additional_info')->nullable(); // Optional extras

            $table->timestamps();
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_details');
    }
};
