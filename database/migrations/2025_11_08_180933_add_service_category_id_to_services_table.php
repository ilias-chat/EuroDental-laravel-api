<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('service_category_id')
                ->nullable()
                ->after('price')
                ->constrained('service_categories')
                ->restrictOnDelete();
        });

        $defaultCategoryId = DB::table('service_categories')
            ->where('name', 'General')
            ->value('id');

        if (!$defaultCategoryId) {
            $now = now();
            $defaultCategoryId = DB::table('service_categories')->insertGetId([
                'name' => 'General',
                'description' => 'Default service category automatically created for existing services.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('services')
            ->whereNull('service_category_id')
            ->update(['service_category_id' => $defaultCategoryId]);

        DB::statement('ALTER TABLE services MODIFY service_category_id BIGINT UNSIGNED NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['service_category_id']);
            $table->dropColumn('service_category_id');
        });
    }
};
