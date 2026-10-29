<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_merge_audits', function (Blueprint $table) {
            $table->unsignedBigInteger('review_id')->nullable()->index();
            $table->json('undo_data')->nullable();
            $table->unsignedBigInteger('undone_by')->nullable();
            $table->timestamp('undone_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_merge_audits', function (Blueprint $table) {
            $table->dropIndex(['review_id']);
            $table->dropColumn(['review_id', 'undo_data', 'undone_by', 'undone_at']);
        });
    }
};
