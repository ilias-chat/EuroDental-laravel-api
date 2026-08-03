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
        Schema::create('proposed_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_name');
            $table->string('task_type');
            $table->foreignId('client_id')->nullable()->constrained()->onDelete('set null');
            $table->text('description');
            $table->foreignId('proposed_by')->constrained('users')->onDelete('cascade');
            $table->boolean('urgent')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposed_tasks');
    }
};

