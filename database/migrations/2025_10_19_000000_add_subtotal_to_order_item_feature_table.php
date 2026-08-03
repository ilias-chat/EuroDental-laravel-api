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
        Schema::table('order_item_feature', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->after('extra_price');
            $table->decimal('subtotal', 10, 2)->default(0)->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_item_feature', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'subtotal']);
        });
    }
};

