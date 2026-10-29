<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_duplicate_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_a_id');
            $table->unsignedBigInteger('client_b_id');
            $table->unsignedTinyInteger('score');
            $table->json('reasons');
            $table->json('clients');
            $table->string('status')->default('pending')->index();
            $table->unsignedBigInteger('survivor_client_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['client_a_id', 'client_b_id']);
        });
        Schema::create('client_merge_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_client_id');
            $table->unsignedBigInteger('survivor_client_id');
            $table->unsignedBigInteger('merged_by');
            $table->json('before');
            $table->json('after');
            $table->json('moved_counts');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_merge_audits');
        Schema::dropIfExists('client_duplicate_reviews');
    }
};
