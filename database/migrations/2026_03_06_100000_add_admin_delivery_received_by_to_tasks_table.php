<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Stores which user received the payment when finishing a delivery task (type "Remise paiement à l'administration").
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_delivery_received_by_user_id')->nullable()->after('admin_delivery_task_id');
            $table->foreign('admin_delivery_received_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['admin_delivery_received_by_user_id']);
            $table->dropColumn('admin_delivery_received_by_user_id');
        });
    }
};
