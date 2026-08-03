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
        Schema::table('tasks', function (Blueprint $table) {
            $table->decimal('admin_delivery_amount', 10, 2)->nullable()->after('incomplete_reason');
            $table->unsignedBigInteger('admin_delivery_task_id')->nullable()->after('admin_delivery_amount');
            $table->foreign('admin_delivery_task_id')->references('id')->on('tasks')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['admin_delivery_task_id']);
            $table->dropColumn(['admin_delivery_amount', 'admin_delivery_task_id']);
        });
    }
};
