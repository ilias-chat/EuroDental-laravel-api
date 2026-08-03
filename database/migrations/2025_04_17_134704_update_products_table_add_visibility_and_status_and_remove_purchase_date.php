<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('products', function (Blueprint $table) {
        $table->string('visibility', 10)->default('offline')->after('product_name');
        $table->string('status', 20)->nullable()->after('visibility');
        $table->dropColumn('purchase_date');
    });
}

public function down()
{
    Schema::table('products', function (Blueprint $table) {
        $table->dropColumn(['visibility', 'status']);
        $table->date('purchase_date')->nullable();
    });
}

};
